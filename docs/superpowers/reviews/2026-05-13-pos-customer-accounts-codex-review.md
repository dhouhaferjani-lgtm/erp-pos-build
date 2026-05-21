# Adversarial review — POS Customer Accounts spec (2026-05-13)

**Reviewer:** Codex
**Review date:** 2026-05-13
**Spec reviewed:** apps/erp/docs/superpowers/specs/2026-05-13-pos-customer-accounts-design.md
**Verdict:** BLOCK
**Total findings:** 3 BLOCKER, 9 P1, 8 P2, 1 P3

## Executive summary

- The proposed event chain is not transactionally coupled to the fiscal/payment writes it claims to protect. Existing domain events are explicitly dispatched after commit and audit persistence currently swallows failures, so the hybrid chain can drift by design.
- Offline Encaissement numbering is incompatible with the spec's own per-company, gapless fiscal numbering requirement. A local pre-allocator cannot prove no gaps or duplicates across multiple offline terminals.
- On-account charge is underspecified against the current accounting model. The new document type has no existing VAT/revenue/AR posting path, and POS receipts currently post paid tender directly to revenue, not receivables.
- The risk model is asymmetric in the wrong places: approval fallback can be downgraded by going offline, POS PIN hashes are already replicated to terminals, and existing offline cash payouts are outside the new control.
- Several "additive" claims are not true against the codebase: PaymentAllocationService cannot be reused as-is, web payment origin stamping changes API/types/UI, and future acompte/marketplace/mobile paths require new posting, event, sync, and UI flows.

## Findings

### [BLOCKER] Event chain is not atomic with the writes it certifies

**Dimension:** architecture, compliance  
**Spec location:** §4.1-§4.3 lines 214-250; §5.1 lines 270-288  
**Evidence:** `apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:191` dispatches `InvoicePosted` with `DB::afterCommit`; `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:215` and `:587` dispatch `PaymentRecorded` after commit; `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:825` catches all audit persistence failures and only logs them; `apps/api/app/Modules/Compliance/Domain/AuditEvent.php:41` has `event_hash` but no `previous_hash`, `channel_key`, or chain sequence columns.  
**Issue:** The spec says fiscal events will be chained in `audit_events`, but the existing event/audit path runs after the business transaction commits and treats audit failure as non-fatal. That means a payment or document can commit while its chain row is absent, unsequenced, or failed. The proposed "synchronous health check" detects drift after the fact; it does not prevent it.  
**Impact:** A certification or fiscal verifier can see valid business rows with missing or broken event-chain rows. The document chain and event chain can diverge permanently, especially under queue/listener failures, database deadlocks, or deployment bugs.  
**Suggested fix:** Allocate and persist fiscal chain state inside the same transaction as the fiscal write, or introduce a transactional outbox with a `pending_seal` state that blocks fiscal finalization until sealing succeeds. For hashable fiscal events, audit-chain persistence must not be swallowed by `DomainEventSubscriber`; failure must abort finalization or leave an explicit unreleased state. Store `channel_key`, `previous_hash`, `chain_sequence`, and the referenced document/payment ID atomically.

### [BLOCKER] Offline Encaissement numbering contradicts gapless per-company sequence semantics

**Dimension:** compliance, offline  
**Spec location:** §2.4 lines 103-124; §9.1 lines 520-530; §10.2 lines 594-601  
**Evidence:** `apps/api/app/Modules/Document/Domain/Services/DocumentNumberingService.php:18` locks a single `document_sequences` row per company/type/year and increments it in a server transaction; `apps/api/app/Modules/Document/Domain/Services/DocumentNumberingService.php:21` derives the year from server `date('Y')`; `apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:54` instead locks a POS terminal row and sequences receipts per terminal. The spec cites Tunisian Art. 18 gapless numbering at lines 60 and 688, then proposes local pre-allocation for offline Encaissement at line 526.  
**Issue:** Multiple offline terminals cannot safely reserve a company-wide, year-scoped Encaissement sequence. A lost terminal, abandoned draft, duplicate customer merge, failed sync, or manager cancellation leaks numbers. Two offline terminals can also allocate overlapping ranges unless a central lock has already been acquired, which is unavailable offline.  
**Impact:** The design cannot prove gapless fiscal numbering for Encaissements in exactly the offline scenario it supports. It will fail under multi-terminal, multi-location tenants, and midnight year rollover creates additional ambiguity for long-running transactions.  
**Suggested fix:** Pick one enforceable model. Either make offline Encaissement provisional and assign the definitive company `E-YYYY-NNNNNN` only on server sync before fiscal issuance, or make the legal sequence per terminal/location if counsel confirms it is acceptable. If neither is acceptable, disallow offline Encaissement. Do not claim gapless per-company numbering with a local pre-allocator.

### [BLOCKER] On-account charge has no compatible VAT/revenue/AR posting path

**Dimension:** architecture, compliance  
**Spec location:** §2.2 lines 77-85; §5.2 lines 292-307; §8.4 lines 480-486  
**Evidence:** `apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:7` only defines quote, order, invoice, credit note, delivery note, return note, and expense; `apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:55` limits receivable effects to `Invoice` and `CreditNote`; `apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:31` only treats invoices and credit notes as fiscal posting documents; `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:48` posts invoice VAT/revenue/AR, while `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1188` documents POS payments as direct revenue recognition and `:1194` posts POS tender directly to revenue.  
**Issue:** The proposed `OnAccountCharge` document is neither a normal invoice nor an existing POS receipt settlement. If it follows the POS path, the unpaid portion has no AR journal. If it follows the Document path, it has no VAT/revenue/fiscal posting support and may become a quasi-invoice without the invoice contract.  
**Impact:** Charge-to-account checkout can create a sale with missing receivable, wrong VAT/revenue recognition, wrong customer balance, or an unchained fiscal document. This is not an implementation detail; it is the core accounting behavior.  
**Suggested fix:** Define one accounting model explicitly. Either issue a real `Invoice` for account sales, or keep a POS receipt as the fiscal sale and add a separate AR settlement journal for the account tender. Extend `DocumentType`, `DocumentPostingService`, GL posting, balance refresh, and receipt print rules as one reviewed design, with tests for mixed cash/card/account tenders.

### [P1] French legal anchor is scheduled to become stale before the planned France path

**Dimension:** compliance  
**Spec location:** §2.3 lines 87-90; Appendix lines 680-684  
**Evidence:** The spec cites Article 269 CGI as the France anchor. Legifrance lists Article 269 as "en vigueur du 01 janvier 2026 au 01 septembre 2026" and "abrogé par Ordonnance n°2025-920 du 18 septembre 2025 - art. 2 (V)" at https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000056191870. The European Commission's VAT in the Digital Age page describes EU-level VAT digital reporting and e-invoicing changes at https://taxation-customs.ec.europa.eu/taxation/vat/vat-digital-age_en.  
**Issue:** A spec dated 2026-05-13 and explicitly targeting "France next" relies on a Code général des impôts article that is already scheduled to be abrogated on 2026-09-01. It also does not map the France design to ViDA/e-invoicing milestones.  
**Impact:** The France implementation can ship with obsolete legal references and wrong date-effective tax point assumptions. That weakens certification, customer documentation, and future acompte handling.  
**Suggested fix:** Add a date-effective legal appendix: current CGI references for now, successor Code des impositions references after 2026-09-01, and a France/ViDA obligation matrix with effective dates. Gate France-specific logic by jurisdiction and legal-effective date, not a single hardcoded citation.

### [P1] Web payment retrofit names a non-existent event and understates API impact

**Dimension:** implementation, architecture  
**Spec location:** §5.1 lines 287-288; §9.2 lines 534-541  
**Evidence:** The existing event is `PaymentRecorded`, not `PaymentReceived`, in `apps/api/app/Modules/Treasury/Domain/Events/PaymentRecorded.php:15`; it is subscribed in `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:843`; `PaymentController` dispatches it at `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:215` and `:587`. `PaymentController::formatPayment()` returns no origin/source fields at `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:893`; the web `Payment` interface and table columns have no source or document reference at `apps/web/src/features/treasury/PaymentListPage.tsx:11` and `:123`.  
**Issue:** The spec says the B2B flow is unchanged except `origin='web_admin'`, but that is a schema/API/fixture/type/UI change. It also points to a new or misnamed event, creating a risk of double-firing or bypassing the existing `PaymentRecorded` audit subscriber.  
**Impact:** Existing API consumers, generated TypeScript contracts, tests, exports, and UI tables will break or silently omit the new source data. The fiscal chain may receive duplicate or incomplete payment events.  
**Suggested fix:** Version the payment event contract deliberately. Either extend `PaymentRecorded` with origin/document metadata and migrate all dispatchers, or introduce one new fiscal payment event and retire the old audit path for fiscal payments. Document schema migration, generated type updates, API response changes, exports, and web UI fixture updates.

### [P1] PaymentAllocationService cannot be reused as-is for POS Encaissement

**Dimension:** implementation  
**Spec location:** §5.1 lines 282-286; §5.3 lines 309-312  
**Evidence:** The actual method is `applyAllocation()`, not `apply()`, in `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:83`; the service depends on `CompanyContext` at `:28`; it uses `Auth::user()` when creating CustomerAdvance entries at `:231` and `:260`, and skips those GL postings if no authenticated user exists; open allocation targets are invoices and sales orders only at `:338`.  
**Issue:** POS offline replay and background sync are not guaranteed to have a normal web `Auth::user()` context. The spec promises excess routes to CustomerAdvance, but the current service makes CustomerAdvance GL creation conditional on an authenticated user.  
**Impact:** Encaissements with overpayment or sales-order advance can create payments without matching 4191 liability entries, leaving partner balances, statements, and GL wrong.  
**Suggested fix:** Refactor allocation behind an explicit command DTO containing tenant, company, actor, source, and allocation intent. Make CustomerAdvance posting unconditional when the business condition is met, using a system actor when replaying offline POS sync. Add tests for authenticated web payments, offline POS replay, excess allocation, and sales-order advance.

### [P1] Local PIN approval ignores the existing high-risk hash replication path

**Dimension:** risk, implementation  
**Spec location:** §3.1 lines 146-148; §5.4 lines 313-325; §7.1 lines 397-401  
**Evidence:** `apps/api/database/migrations/2026_03_07_100000_add_pos_pin_to_users.php:14` stores `users.pos_pin`; `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:117` returns operator PIN hashes, roles, and permissions to POS clients; `apps/pos/src/lib/db/migrations.ts:72` stores those hashes locally in `operator_pins`; `apps/pos/src/lib/db/migrations.ts:346` stores queued client PIN hash updates; `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:147` accepts client-submitted PIN hashes during sync.  
**Issue:** The spec treats `LocalPinChannel` as an abstract approval channel, but the codebase already replicates manager PIN hashes to terminals and allows offline PIN update sync. The spec does not define at-rest encryption, offline brute-force limits, rotation/revocation semantics, or whether client-authored hashes remain allowed.  
**Impact:** A stolen or unattended terminal can expose manager PIN hashes for offline attack. Revoked managers may remain able to approve offline. Client-originated PIN hash sync can undermine server authority.  
**Suggested fix:** Specify a concrete approval-token protocol: encrypted per-terminal PIN cache, per-approver and per-terminal offline attempt counters, short offline validity TTL, signed permission snapshot version, server-authoritative rotation, and no generic client-authored PIN hash writes except a dedicated verified setup flow.

### [P1] Push-to-PIN fallback is a deliberate approval downgrade path

**Dimension:** risk, offline  
**Spec location:** §5.4 lines 319-325; §7.1 lines 397-401  
**Evidence:** The spec marks `RemotePushChannel` as not offline-capable and says it falls back to PIN. Existing manager PIN approval is tied to `pos.close_shift_with_variance` in `apps/api/app/Modules/POS/Presentation/Controllers/ManagerPinController.php:76`; authorized managers are selected by the same permission at `apps/api/app/Modules/POS/Presentation/Controllers/AuthorizedManagersController.php:38`.  
**Issue:** If high-risk approvals are supposed to require remote push, allowing offline fallback lets a cashier unplug the network and downgrade the control to a locally observed PIN. The current permission model is also shift-variance-specific, not override-type-specific.  
**Impact:** AML, credit-limit, and cash-out approvals can be intentionally routed through the weakest channel. A manager authorized for till variance could accidentally approve credit or cash refund exceptions.  
**Suggested fix:** Make fallback policy explicit per override type. For example, `push_required_online_only` must block the operation offline; `pin_allowed_offline` must have tighter thresholds and a signed permission snapshot. Replace the current manager-PIN contract with an approval endpoint that checks the requested override permission, amount, jurisdiction, and source channel.

### [P1] Cash-out controls ignore existing offline drawer payouts and laundering-shaped change

**Dimension:** risk, compliance  
**Spec location:** §6.3 lines 375-383; §9.5 lines 567-575  
**Evidence:** `apps/pos/src/api/cashDrawerApi.ts:24` queues `payoutCash()` offline; `apps/api/app/Modules/POS/Presentation/Controllers/CashDrawerController.php:21` says payouts include refunds and petty cash; the controller authorizes payout with `pos.operate_terminal` at `apps/api/app/Modules/POS/Presentation/Controllers/CashDrawerController.php:86`. The French public-service cash-payment cap page is https://www.service-public.fr/particuliers/vosdroits/F10999, while the spec itself cites a France cash cap at line 62.  
**Issue:** The spec adds a new permission for refunding customer credit as cash, but existing cash drawer payout and petty-cash flows can already give out cash, including offline. It also dismisses standard change as not risky even though an overpayment/change pattern can be laundering-shaped.  
**Impact:** Cash can leave the till through paths outside the new approval and fiscal audit policy. Country cash caps and AML controls can be bypassed with payout, refund, or excessive-change workflows.  
**Suggested fix:** Introduce one `CashOutPolicy` that covers customer-credit refunds, sale refunds, drawer payouts, petty cash, and change over configurable thresholds. The policy must be jurisdiction-aware, online/offline-aware, and audited with the same approval primitive.

### [P1] Printed offline Encaissement can disagree with authoritative server allocation

**Dimension:** offline, compliance  
**Spec location:** §7.5 lines 436-440; §8.8 lines 510-514; §9.1 lines 526-530  
**Evidence:** The spec says the POS prints a Reçu d'encaissement offline at line 527, then the server applies allocation against the live ledger at line 530. `PaymentAllocationService` can redirect excess to CustomerAdvance at `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:254`.  
**Issue:** If another terminal or web admin pays the same invoices before sync, the server allocation can differ from the printed receipt. The spec says "server authoritative" and "cashier sees warning", but it does not define an amended receipt, customer notification, reprint, or signed audit link between the local printout and the server correction.  
**Impact:** The customer can hold a receipt that lists invoice allocations or balances the server later changes. That creates disputes, statement mismatches, and weak fiscal evidence.  
**Suggested fix:** Mark offline allocation receipts as provisional unless definitive allocation is reserved online. On sync, compare local intended allocation to server allocation; if different, emit an amended Encaissement receipt with a correction reference and require cashier/customer-facing reconciliation.

### [P1] Phone-based customer dedup is unsafe and the FK rewrite plan is incomplete

**Dimension:** offline, data integrity  
**Spec location:** §7.1 lines 393-395; §7.5 lines 436-440; §9.4 lines 555-565  
**Evidence:** `apps/api/app/Modules/Partner/Domain/Partner.php:47` has nullable `phone` and `country_code` fields but no model-level uniqueness; the spec says server-side dedup uses phone equality at lines 393-395 and only mentions rewriting local receipts to the canonical UUID at line 565.  
**Issue:** Phone numbers are reused, shared, mistyped, and country-dependent. `+216` and `+33` normalization is not optional in this product. The rewrite plan also ignores local outbox events, allocations, printed receipt snapshots, vouchers, loyalty, and any future customer references beyond receipts.  
**Impact:** Two real customers can be merged incorrectly, or a rejected temp customer UUID can survive in local records and queued fiscal events. That corrupts statements, credit limits, and audit trails.  
**Suggested fix:** Use normalized E.164 plus country, tax ID/national ID, name, and address as a scored match, not an automatic merge key. Add a local identity-map table that rewrites every pending local reference atomically while preserving immutable printed fiscal snapshots.

### [P1] Approval permissions do not line up with existing POS authorization

**Dimension:** implementation, risk  
**Spec location:** §6.1 lines 345-359; §6.2 lines 363-373  
**Evidence:** Existing manager PIN verification checks only `pos.close_shift_with_variance` in `apps/api/app/Modules/POS/Presentation/Controllers/ManagerPinController.php:76`; the manager list is built from the same permission at `apps/api/app/Modules/POS/Presentation/Controllers/AuthorizedManagersController.php:38`; the spec introduces `pos.override.credit_limit`, `pos.override.stale_balance`, `pos.payment.*`, and AML permissions without mapping them to the existing endpoints.  
**Issue:** Reusing the current manager PIN endpoint would authorize the wrong thing. Creating new permissions without a new approval contract leaves implementers to infer which permission controls each override.  
**Impact:** Till-variance managers may be able to approve AML or credit overrides, or legitimate managers may be blocked because their permission snapshot lacks the new names.  
**Suggested fix:** Define an approval request schema with `override_type`, `amount`, `jurisdiction`, `source`, and `required_permission`. Version or replace the manager-PIN endpoint so it checks the exact permission for the requested override, not just `pos.close_shift_with_variance`.

### [P2] Event channel keys create web/API write hot spots and weak attribution

**Dimension:** architecture  
**Spec location:** §4.1 lines 214-224; §4.3 lines 246-253  
**Evidence:** The spec proposes `web:<company_id>`, `mob:<company_id>`, and `api:<company_id>` channel keys at lines 219-222. Existing POS chaining is per terminal: `apps/api/database/migrations/2026_01_08_190429_create_pos_terminals_table.php:40` stores terminal chain state, and `apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:54` locks a single terminal row.  
**Issue:** All web admin users and API clients in a company serialize through one chain row if the chain is correctly locked. The channel key also cannot distinguish browser, integration client, or admin device without relying on mutable payload metadata.  
**Impact:** High-volume tenants get an artificial company-wide write hot spot for web/API fiscal events. Fraud investigation loses device/client attribution at the chain level.  
**Suggested fix:** Use `(origin, company_id, device_or_client_id)` as the chain identity. Server-assign virtual terminal/client IDs for web and API channels, and include user ID and session ID in the hash payload.

### [P2] Genesis and reset rules contradict the existing chain convention

**Dimension:** consistency, compliance  
**Spec location:** §4.1 lines 214-224; §10.2 lines 594-601  
**Evidence:** The spec says the first event row per channel has `previous_hash=''` at line 601. Existing document posting uses a company genesis seed when no prior fiscal document exists at `apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:156`; POS terminals have `genesis_seed` in `apps/api/database/migrations/2026_01_08_190429_create_pos_terminals_table.php:40`.  
**Issue:** The new event chain uses a weaker and different genesis convention from the existing document and POS chains. It also lacks a rule for channel rebirth, terminal replacement, virtual web channel creation, or fiscal year rollover.  
**Impact:** Auditors will see incompatible genesis policies across chains, and operators will not know whether a reset is legitimate or tampering.  
**Suggested fix:** Add explicit signed chain genesis records with seed, reason, created_by, effective_at, and predecessor channel reference. Use the same genesis convention across document, POS, and event chains, or document why the legal treatment differs.

### [P2] "Corrections via opposing events" is prose, not an enforced invariant

**Dimension:** consistency, implementation  
**Spec location:** §4.2 lines 240-242; §10.1 lines 581-590  
**Evidence:** `apps/api/app/Modules/Document/Domain/Document.php:97` uses `SoftDeletes`; `apps/api/database/migrations/2025_11_30_080001_create_documents_table.php:35` includes `softDeletes`; the POS receipt immutability trigger exists separately in `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:137`.  
**Issue:** The spec says corrections are opposing events and fiscal rows are never mutated, but the Document model and table permit soft deletion and ordinary updates unless service code prevents them. The POS receipt table has DB-level immutability triggers; new fiscal Document types do not.  
**Impact:** A bug, admin script, or future controller path can mutate or soft-delete Encaissement/OnAccountCharge records without an opposing event, breaking the audit story.  
**Suggested fix:** Add DB-level immutability constraints for fiscal document/payment rows, with a small allowed-transition matrix. Require corrections through explicit reversal documents/events and test forbidden update/delete paths.

### [P2] Partner account fields duplicate existing balance semantics

**Dimension:** implementation  
**Spec location:** §3.1 lines 136-148; §10.2 lines 594-601  
**Evidence:** `apps/api/app/Modules/Partner/Domain/Partner.php:36` already has `credit_limit`, `payment_terms_days`, `receivable_balance`, `credit_balance`, and `balance_updated_at`; `apps/api/database/migrations/2025_12_06_100001_add_balance_fields_to_partners.php:13` says cached balances exist for performance while GL is the source of truth.  
**Issue:** The spec adds `credit_used` as another stored monetary field but does not define whether it is source of truth, cache, or derived from existing balances. It also proposes account-status backfill based on balances without tying the result to GL refresh.  
**Impact:** Credit-limit checks can read a stale or conflicting number, especially after offline replay, allocation changes, or CustomerAdvance clearing.  
**Suggested fix:** Do not store `credit_used` separately. Define it as `max(receivable_balance - credit_balance, 0)` or compute it through `PartnerBalanceService`, and make all credit checks use one documented balance source.

### [P2] Acompte is not additive under the proposed discriminator

**Dimension:** scope, forward-compat  
**Spec location:** §2.3 lines 91-101; §12 lines 663-665  
**Evidence:** `PaymentAllocationService` already treats sales-order allocations as prepayments and creates CustomerAdvance entries at `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:190` and `:230`; `GeneralLedgerService::clearCustomerAdvanceToReceivable()` exists at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:715`; `DocumentType` lacks `FactureAcompte` at `apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:7`.  
**Issue:** A `payment_document_type` enum and CHECK constraint do not accommodate VAT-on-collection, `FactureAcompte` issuance, GL 4191 clearing rules, new fiscal events, and validation differences. The existing advance flow is already a distinct accounting branch, not a simple payment subtype.  
**Impact:** Future acompte work will require new event handlers, GL posting, tax validation, document numbering, and print formats despite the spec calling it additive. Historical rows may need reinterpretation.  
**Suggested fix:** Mark acompte as a separate future design with explicit document type, event types, tax point, GL entries, and migration boundaries. Keep the enum reserve only as a namespace reservation, not a compatibility claim.

### [P2] Marketplace, delivery, and mobile sync compatibility is asserted, not designed

**Dimension:** scope, offline  
**Spec location:** §7.6 lines 442-450; §12 lines 668-671  
**Evidence:** The current POS local schema is specific to products, receipts, terminal state, sync state, cash operations, vouchers, and PINs in `apps/pos/src/lib/db/migrations.ts:8` through `:157`; POS sync routes are specific endpoints such as `/receipts/sync`, `/sync/pull`, and `/sync/menu` in `apps/api/app/Modules/POS/routes.php:84`.  
**Issue:** A typed envelope alone does not make marketplace orders, delivery confirmations, mobile approvals, or body-shop offline flows additive. Each needs local storage, conversion-to-sale rules, conflict policy, and UI behavior. The spec does not say where an inbound marketplace order becomes a sale or whether `OnAccountCharge` needs order-aware variants.  
**Impact:** Future teams will discover incompatible assumptions in sync, checkout, and accounting after the current design has hardened.  
**Suggested fix:** Downgrade the claim. Define only a versioned envelope convention now, and require each future document type to provide schema, sync route, conflict policy, conversion service, GL behavior, and UI state machine.

### [P2] i18n and generated TypeScript contract work are missing from the implementation plan

**Dimension:** implementation  
**Spec location:** §8 lines 454-514; §10 lines 581-602  
**Evidence:** `CLAUDE.md:34` says frontend types are backend-generated via `php artisan typescript:transform`; `CLAUDE.md:45` forbids hardcoded frontend strings; `docs/conventions/04-FRONTEND-TYPES.md:170` requires regenerating `resources/js/types/generated.d.ts`, `apps/web/src/types/generated.ts`, and `packages/shared/types/generated.d.ts`; `docs/conventions/04-FRONTEND-TYPES.md:163` notes backend `DECIMAL` values emit as TypeScript strings.  
**Issue:** The spec adds backend DTO/schema fields and multiple POS/web UI surfaces but does not name translation namespaces, type regeneration steps, or decimal-string handling.  
**Impact:** Strict TypeScript, i18n linting, and money comparisons can fail or regress. UI implementers may add hardcoded strings or compare monetary strings lexically.  
**Suggested fix:** Add an implementation checklist for translation keys in POS and web namespaces, DTO updates, `php artisan typescript:transform`, generated file commits, and decimal helper usage for every monetary field.

### [P2] "No backfill" conflicts with the proposed account-status migration semantics

**Dimension:** consistency  
**Spec location:** §4.2 lines 240-242; §10.2 lines 594-601  
**Evidence:** The spec says backfilling past events is prohibited at line 242, then initializes partner account status for existing rows at lines 598-600. It also says account-status changes are audit-relevant in §3.2 line 151. Existing `CustomerCategory` migration has already backfilled partner classification at `apps/api/database/migrations/2026_03_09_100001_add_customer_category_to_partners.php:26`.  
**Issue:** If account status is audit-relevant, a migration that derives Active/Blocked from current balances creates state without a corresponding chain/audit history. If it is not fiscal/audit-relevant, §3.2 overstates the audit requirement.  
**Impact:** Reviewers cannot tell where the cutover boundary is. The first chained account event may imply a state transition from an undocumented prior state.  
**Suggested fix:** Define the cutover explicitly. Record one non-fiscal migration snapshot event per partner with `source=migration_cutover`, or state that account-status initialization is not part of the fiscal hash chain and only future manual changes are audited.

### [P3] Printed sequence examples hide the definitive fiscal identifier

**Dimension:** consistency, compliance  
**Spec location:** §2.4 lines 117-122; §8.8 lines 510-514  
**Evidence:** The stored ticket format includes `OTO/AVB/POS01/2026/000123` at lines 107-112, but the print example shows location and terminal on one row and `Ticket #2026/000123` on another at lines 117-122.  
**Issue:** The spec does not state whether the full stored fiscal sequence appears on paper, in the QR payload, and in exports as one definitive identifier. Splitting it across lines is readable, but it can obscure the exact key support and auditors need.  
**Impact:** Staff and inspectors may have to reconstruct the stored identifier from multiple fields, increasing reconciliation errors.  
**Suggested fix:** Print the full fiscal sequence at least once exactly as stored, optionally alongside the friendly split. Use the same identifier in QR payloads, exports, audit trail, and customer reprints.
