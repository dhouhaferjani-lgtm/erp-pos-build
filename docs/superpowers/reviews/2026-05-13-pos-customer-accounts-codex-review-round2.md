# Adversarial review round 2 — POS Customer Accounts spec v1.1

**Reviewer:** Codex (round 2)
**Review date:** 2026-05-13
**Spec reviewed:** apps/erp/docs/superpowers/specs/2026-05-13-pos-customer-accounts-design-v1.1.md
**Round-1 review:** apps/erp/docs/superpowers/reviews/2026-05-13-pos-customer-accounts-codex-review.md
**Verdict:** BLOCK
**Total findings:** 4 BLOCKER, 8 P1, 4 P2, 1 P3

## Executive summary

- v1.1's central simplification, "POS Receipt variants using the existing receipt chain as-is," is not buildable as specified. Current `pos_receipts` constraints, `receipt_type` enum values, offline SQLite schema, sync DTO, and server sync writer do not represent `Encaissement` or identified customer-account receipts.
- The existing V2 receipt hash does not bind `receipt_type`, partner/customer identity, on-account semantics, or override/account-status audit. Deferring the event chain is only defensible for ordinary paid POS sales; it is not sufficient for the new account-control and override surfaces v1.1 adds.
- The on-account tender reframing still collides with Treasury and GL. Existing receipt payment processing assumes every tender has a payment repository and creates a Treasury Payment plus direct-to-revenue GL entry.
- v1.1 fixes the naive phone-only dedup and push-to-PIN downgrade at the prose level, but the new identity-map and approval-token designs are not grounded in the actual SQLite schema, Tauri crypto/key storage, or existing client-authored PIN sync path.
- The legal/per-terminal numbering reframing is plausible for ordinary cash-register tickets, but v1.1 overstates it for identified account sales that can function as sales invoices or invoice substitutes in Tunisian practice.

## Adequacy of v1.1 responses to round-1 findings

- **B1 event chain not atomic:** REFRAMED-UNSAFELY — deferring cross-channel chaining is defensible for plain POS sales, but v1.1 adds account-status changes, override audits, cash-out decisions, and web freezes that are not protected by the POS receipt chain.
- **B2 offline numbering:** REFRAMED-UNSAFELY — per-terminal POS ticket sequencing may be defensible, but v1.1 applies it to identified account sales without proving those receipts are not invoices or documents in lieu of invoices under Tunisian practice.
- **B3 no posting path:** REFRAMED-UNSAFELY — moving to POS Receipt variants avoids `DocumentType`, but the existing receipt schema, hash payload, offline sync DTO, payment processing, and GL entry path do not support the new variants as stated.
- **P1 French legal anchor stale:** ADEQUATE — v1.1 now marks Art. 269 CGI successor mapping as a France-cert dependency, which is acceptable while France/NF525 remains out of scope.
- **P1 web payment retrofit no-op claim:** REFRAMED-UNSAFELY — v1.1 admits schema/API/UI changes, but its blanket `Payment.origin='web_admin'` backfill would mislabel existing POS-generated Treasury payments.
- **P1 PaymentAllocationService reuse:** ADEQUATE — v1.1 now requires a command DTO and removes the `Auth::user()` dependency for CustomerAdvance posting.
- **P1 PIN approval under-specified:** REFRAMED-UNSAFELY — v1.1 adds a protocol, but it assumes a Tauri keyring and a clean fiscal/non-fiscal PIN split that the codebase does not currently have.
- **P1 push fallback bypass:** ADEQUATE — the new matrix blocks the highest-risk push-only approvals offline instead of falling back to PIN.
- **P1 cash-risk model too narrow:** REFRAMED-UNSAFELY — v1.1 introduces `CashOutPolicy`, but threshold storage and `context_json` schema enforcement are absent.
- **P1 offline receipt/allocation mismatch:** REFRAMED-UNSAFELY — v1.1 says silent server rerouting is fine, yet the printed Encaissement still shows allocated invoices and new balance.
- **P1 phone dedup unsafe:** REFRAMED-DEFENSIBLY — multi-factor scoring and manual review are a better direction, but the implementation still needs minimum offline identity fields and validated tax/phone normalization.
- **P1 approval permissions mismatch:** ADEQUATE — v1.1 replaces the shift-variance-only manager PIN endpoint with an override-type-driven approval contract.

## New findings (round-2)

### [BLOCKER] `receipt_type` migration is incompatible with the existing enum and CHECK constraint

**Dimension:** implementation  
**Spec location:** §2.2 lines 115-124; §3.4 lines 233-242; §10.1 lines 722-733  
**Evidence:** v1.1 says to add `receipt_type ENUM(Sale, Encaissement, Refund)` with default `Sale` at lines 238 and 726. The column already exists: `apps/api/database/migrations/2026_03_09_200000_add_return_fields_to_pos_receipts.php:24` adds `receipt_type` default `'sale'`; `apps/api/app/Modules/POS/Domain/Enums/ReceiptType.php:9` defines only `'sale'` and `'return'`; the database CHECK permits only `receipt_type = 'sale'` or `receipt_type = 'return'` at `apps/api/database/migrations/2026_03_09_200000_add_return_fields_to_pos_receipts.php:45`. `partner_id` also already exists on `pos_receipts` at `apps/api/database/migrations/2026_03_09_100000_add_partner_id_to_pos_receipts.php:21`.  
**Issue:** The migration plan is not additive. It attempts to add an already-existing `receipt_type` column and an already-existing `partner_id` column, while using new capitalized enum values that do not match the lowercase PHP enum or the PostgreSQL CHECK constraint.  
**Impact:** The migration either fails immediately with duplicate columns, or `Encaissement`/`Refund` rows fail enum casting and database validation. Existing code paths that compare to `ReceiptType::Sale` / `ReceiptType::Return` will not handle the new strings.  
**Suggested fix:** Treat this as an extension of the existing field, not a new field. Add lowercase enum cases such as `encaissement` and either reuse existing `return` or explicitly migrate `return` to `refund`. Update `pos_receipts_return_logic`, `ReceiptType`, validation requests, resources, analytics, reports, and tests in the same change. Remove `partner_id` from the migration plan except for any new nullability/index changes actually required.

### [BLOCKER] Encaissement as an empty-lines receipt violates current receipt totals semantics

**Dimension:** implementation, architecture  
**Spec location:** §2.2 lines 117-124; §4.2 lines 271-277; §5.1 lines 301-323  
**Evidence:** v1.1 defines Encaissement as `lines = []`, empty VAT breakdown, and `total = sum(payment_lines.amount)` at lines 305-310. The current PostgreSQL receipt CHECK requires `total = subtotal + tax_amount - discount_amount` at `apps/api/database/migrations/2026_03_09_200000_add_return_fields_to_pos_receipts.php:41`. Receipt lines are optional by absence of rows, but the header totals are still sale-style totals. `ReceiptCreationService` creates receipt VAT and payment hashes from sale line aggregates and an empty payment list at `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:484`.  
**Issue:** A payment-only receipt with no goods has no sales subtotal and no VAT. If `subtotal=0`, `tax_amount=0`, and `discount_amount=0`, then `total=100` violates the existing `pos_receipts_totals` constraint. If implementers set `subtotal=100` just to satisfy the CHECK, analytics and GL will treat an AR collection as a sale.  
**Impact:** Encaissement rows cannot be inserted honestly. Workarounds either fail database constraints or inflate POS revenue/sales analytics with non-sale cash collections.  
**Suggested fix:** Add explicit total semantics per receipt type. For `encaissement`, either relax the CHECK to `receipt_type='encaissement' AND subtotal=0 AND tax_amount=0 AND total = tender_total`, or store payment-only receipts in a separate fiscal receipt table. Update analytics and Z/X reports to exclude Encaissement from sales revenue while including it in cash drawer inflow.

### [BLOCKER] Offline POS sync cannot carry `receipt_type` or customer identity

**Dimension:** offline, implementation  
**Spec location:** §7.1 lines 520-532; §7.2 lines 534-545; §9.1 lines 658-669; §9.4 lines 695-705  
**Evidence:** v1.1 says offline Encaissement and AML-identified sales are final receipts with `receipt_type=Encaissement` and `partner_id=temp_uuid` at lines 527 and 702. The local `offline_receipts` table has no `receipt_type` or `partner_id` columns in `apps/pos/src/lib/db/migrations.ts:91`; later migrations add `payments_json`, `fiscal_schema_version`, and `is_training`, but not receipt type or partner ID at `apps/pos/src/lib/db/migrations.ts:304` and `:690`. The POS sync wire payload has no `receipt_type`, `partner_id`, customer name, or customer identifier fields in `apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php:65`; the client `receiptToPayload()` likewise emits no such fields at `apps/pos/src/lib/sync/syncService.ts:1847`. Server sync hardcodes `receipt_type` to `ReceiptType::Sale` at `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:483`.  
**Issue:** The offline flows described by v1.1 cannot be represented by the existing local table, client sync payload, DTO, or server writer. The server will ingest every offline receipt as a sale with no partner, even if the printed receipt says Encaissement or identified AML sale.  
**Impact:** Offline Encaissements, on-account charges, AML identity attachment, customer balance footers, identity-map rewrites, and server-side allocation cannot work. Worse, the terminal can print a customer-account receipt that the server persists as an anonymous sale.  
**Suggested fix:** Add `receipt_type`, `partner_temp_uuid`, `partner_canonical_uuid`, `customer_name_snapshot`, and `customer_identifier_snapshot` to local SQLite, the TS receipt model, sync payload, request validation, `SyncReceiptPayload`, and `ReceiptSyncService`. Do not print final account receipts offline until the local row contains the same identity fields that will sync and verify on the server.

### [BLOCKER] Existing V2 receipt hash does not bind the customer-account semantics v1.1 relies on

**Dimension:** architecture, compliance  
**Spec location:** §2.5 lines 164-174; §4.1-§4.3 lines 260-287; §9.4 lines 695-705  
**Evidence:** v1.1 says the identified payload includes customer identity in the hashable data at lines 172-173 and says V2 applies without changes at lines 271-277. `ReceiptHashService::serializeForHashing()` hashes only `receipt_number|posted_at|total|currency|vat_breakdown_hash|payment_methods_hash` at `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:62`; `hashPaymentMethods()` hashes only `payment_type` and `amount` at `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:114`. `receipt_type`, `partner_id`, `customer_name`, `customer_identifier`, approval metadata, and on-account meaning are absent.  
**Issue:** The POS chain proves that a receipt number, timestamp, total, currency, VAT subhash, and payment subhash existed. It does not prove whether the row was a sale or Encaissement, which partner was attached, whether the tender was actual cash/card or on-account AR, or which override authorized it.  
**Impact:** v1.1's fiscal proof is weaker than the operations it now places on the POS chain. An inspector or dispute process cannot verify from the chain that a 6,000 TND AML-identified sale was tied to the printed identity, or that an on-account tender was actually the AR portion of the sale.  
**Suggested fix:** Do not use unchanged V2 for customer-account receipts. Either require terminal `fiscal_schema_version=3` and extend V3 with `receipt_type`, partner/customer snapshot, on-account tender kind, and approval/audit hash, or introduce a V2.1 payload with those fields and a hard cutover before enabling these features.

### [P1] On-account tender conflicts with the current Treasury Payment and GL path

**Dimension:** accounting, implementation  
**Spec location:** §2.2 lines 117-124; §5.2 lines 325-355; §9.3 lines 682-693  
**Evidence:** v1.1 models the unpaid portion as a receipt payment line `payment_type=on_account` at line 341. Existing `pos_receipt_payments` requires a `payment_method_id` at `apps/api/database/migrations/2026_01_08_190640_create_pos_receipt_payments_table.php:30`. The sync DTO requires `payment_method_id` and `repository_id` for every payment line at `apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php:37`. `ReceiptPaymentService` creates a Treasury `Payment` row for each receipt payment at `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:251`, then calls `GeneralLedgerService::createPOSPaymentEntry()` at `:269`. That GL method explicitly treats POS payments as direct-to-revenue with no AR at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1188`.  
**Issue:** `on_account` is not a payment repository inflow. It is the absence of cash/card settlement, converted into AR. The existing service stack will either reject it for missing repository/method, or force implementers to create a fake repository that posts it as direct revenue.  
**Impact:** Charge-to-account can double-credit revenue, debit a fake cash/bank account, or fail validation. The proposed "small GL extension" is not enough because the receipt payment service and sync DTO encode the wrong abstraction.  
**Suggested fix:** Separate settlement lines from cash-in payment lines. Add a receipt settlement type such as `cash`, `card`, `voucher`, `on_account`, where only real inflows create Treasury Payment rows. Post one receipt-level journal for goods revenue/VAT plus AR/cash split, and link the AR line to the partner.

### [P1] Deferring the event chain leaves account-status and override decisions non-inalterable

**Dimension:** architecture, compliance  
**Spec location:** §3.3 lines 216-231; §4.3 lines 279-287; §7.4 lines 558-568  
**Evidence:** v1.1 defers server-side event chaining until web/mobile payments become common or France certification is near-term at lines 281-285. It also allows offline charges against accounts later found Frozen at line 564 and records override decisions in `override_audit` at lines 218-228. Existing `AuditEvent` stores an `event_hash` but no previous hash or chain state at `apps/api/app/Modules/Compliance/Domain/AuditEvent.php:41`; the hash is just SHA-256 of event fields and payload at `apps/api/app/Modules/Compliance/Domain/AuditEvent.php:152`.  
**Issue:** `audit_events.event_hash` is not an inalterable chain. Anyone with database write access can update payload and event_hash together, delete rows, or insert missing rows without chain break evidence. The POS receipt chain only seals the final receipt; it does not seal the prior freeze, KYC enablement, override, or cash-out decision that made the receipt permissible.  
**Impact:** The specific scenario in the prompt remains unresolved: web admin freezes an account, the POS is offline, and a charge-to-account is finalized. There is no tamper-evident link between the freeze decision and the later receipt, only eventual notification.  
**Suggested fix:** Keep fiscal receipt hashing per-terminal if desired, but add a minimal tamper-evident server chain for account-status and override events before Phase 3/4. At minimum, define a hard measurable trigger such as "before enabling web account-status changes" or "before enabling offline charge-to-account," not "when web payments become common."

### [P1] `Payment.origin` backfill will mislabel existing POS payments

**Dimension:** implementation, consistency  
**Spec location:** §5.6 lines 408-419; §10.2 lines 735-740  
**Evidence:** v1.1 says `payment.origin` defaults to `'web_admin'` and all existing Payment rows are backfilled to `'web_admin'` at lines 412 and 739. Existing POS receipt payment processing already creates Treasury `Payment` rows with `payment_type = PaymentType::POS` at `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:251` and `:263`.  
**Issue:** Existing data is not all web-admin-originated. Backfilling every row to `web_admin` contradicts existing POS-generated Treasury payments.  
**Impact:** Unified payment listing, reporting, source filters, and future audit rules will misclassify historical POS receipts as web admin payments. Any later reconciliation from receipt to payment origin starts from corrupted source metadata.  
**Suggested fix:** Backfill origin conditionally: `payment_type='pos'` or references matching `POS Receipt ...` become `pos`; document/invoice payments created by web controllers become `web_admin`; ambiguous rows become `unknown_legacy` and are excluded from source-specific analytics until reviewed.

### [P1] Approval-token protocol assumes a keyring and signing layer that do not exist

**Dimension:** risk, implementation  
**Spec location:** §6.4 lines 477-490  
**Evidence:** v1.1 says PIN hashes are encrypted with a per-terminal key derived from a Tauri keyring secret at line 483. `apps/pos/src-tauri/Cargo.toml:15` lists Tauri plugins for SQL, store, HTTP, notification, OS, window state, log, and FS; no keyring/stronghold plugin is present. The existing crypto command stores a raw AES key in a local file `.izipos_key` under the app data directory at `apps/pos/src-tauri/src/commands/crypto.rs:10` and writes it with filesystem permissions at `:44`. Existing POS auth still returns all operator PIN hashes at `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:117`, and still accepts client-authored PIN hash sync at `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:158`.  
**Issue:** The protocol is not implementable as written on the current Tauri stack. It also does not specify signature algorithm, public-key distribution, key rotation, or how the existing client-authored hash endpoint is separated cleanly from fiscal approvals.  
**Impact:** Implementers may believe PIN hashes are keyring-protected and permission snapshots are tamper-evident when they are actually stored under a local file key and mutable SQLite state. A cashier with filesystem access can wipe counters or replace local state.  
**Suggested fix:** Specify the actual key-management mechanism for each target OS/POS appliance. If using the current AES file key, document its threat model and hardening. Add signed snapshot columns to `operator_pins`, define Ed25519 or RSA signature verification, bundle/rotate the public key, and disable `/pos/auth/sync-pins` for any user capable of fiscal overrides.

### [P1] Identity-map propagation is incomplete and conflicts with fiscal snapshots

**Dimension:** offline, data integrity  
**Spec location:** §3.5 lines 244-257; §7.5 lines 569-592; §9.4 lines 695-705  
**Evidence:** v1.1 says the identity map atomically rewrites every local reference and leaves printed fiscal snapshots with the temp UUID at lines 256 and 588-590. Existing local tables that can carry partner/customer references include `vouchers.partner_id` and `vouchers.issued_to_partner_id` at `apps/pos/src/lib/db/migrations.ts:536`, `receipt_qr_index.partner_id` at `apps/pos/src/lib/db/migrations.ts:621`, and future `offline_receipts` rows once v1.1 adds customer fields. Existing `pos_receipts.partner_id` is explicitly a query FK while denormalized customer fields remain for fiscal immutability at `apps/api/database/migrations/2026_03_09_100000_add_partner_id_to_pos_receipts.php:13`.  
**Issue:** "Rewrite every local reference" is too broad for sealed fiscal rows and too narrow for the actual local schema. If a temp UUID was printed and hashed as the customer identity, rewriting live FKs to a canonical UUID creates two identities: printed/hash snapshot and query FK. If the temp UUID is not hash-bound under V2, then the printed identity was never protected in the first place.  
**Impact:** Customer statements and receipt lookup may point to the canonical customer while the printed receipt and fiscal evidence show a different temp identity. Disputes and audit reconstruction will require an identity-alias trail that the spec does not define.  
**Suggested fix:** Do not rewrite sealed fiscal subject snapshots. Store `customer_identity_snapshot` and `identity_map_version` separately from live `partner_id`. Rewrites should update pending outbox/drafts only; sealed receipts should retain an alias link `temp_uuid -> canonical_uuid` with an append-only reconciliation record.

### [P1] CashOutPolicy has no configurable threshold model or enforced audit schema

**Dimension:** risk, implementation  
**Spec location:** §3.2 lines 204-214; §3.3 lines 216-231; §6.3 lines 461-475  
**Evidence:** v1.1 says sale-refund, drawer-payout, and large-change thresholds default by jurisdiction at lines 465-473. The proposed `tenant_account_policy` only contains charge-to-account and AML fields at lines 206-213; no cash-out thresholds are listed. `override_audit.context_json` is free-form at lines 218-226.  
**Issue:** The policy table cannot store the policy v1.1 describes, and the audit row does not schema-enforce core facts such as cash amount returned, sale total, change ratio, jurisdiction, threshold used, and policy version.  
**Impact:** Two terminals or tenants can apply different cash-out thresholds without a reproducible audit record. A manager approval can exist with context that is incomplete or malformed, making after-the-fact AML/cash-control review weak.  
**Suggested fix:** Add a `cash_out_policy` table or JSON schema with versioned per-jurisdiction thresholds and ratio rules. Promote required audit fields out of generic `context_json` into typed columns, or enforce `context_json` with a JSON schema/check plus application validation.

### [P1] Tunisian per-terminal numbering claim is over-applied to identified account sales

**Dimension:** compliance  
**Spec location:** §2.1 lines 91-101; §2.4 lines 138-162; Appendix A lines 843-846  
**Evidence:** The Tunisian Ministry of Finance FAQ says VAT taxpayers must use invoices numbered in an uninterrupted series at https://www.finances.gov.tn/fr/node/75 and says invoice numbers must be in an uninterrupted series at https://www.finances.gov.tn/fr/node/952. Jurisite's Code TVA Article 18 text likewise states that sales invoices must be numbered in an uninterrupted series at https://www.jurisitetunisie.com/tunisie/codes/tva/tva1060.htm. Profiscal's Tunisian fiscal literature says multiple establishments may have distinct series if individualized by establishment at https://www.profiscal.com/Etudiants/TCA/tca_ch9_06.htm.  
**Issue:** v1.1 treats every POS account sale as a ticket/receipt outside invoice numbering. That is not proven for an identified charge-to-account sale that includes partner identity, VAT, AR, balance footer, and may be demanded by the customer as proof of sale. The cited sources support uninterrupted invoice numbering and possibly individualized establishment series; they do not prove that per-terminal account receipts can replace invoices without Art. 18 treatment.  
**Impact:** A Tunisian DGI inspector could treat some account-sale receipts as invoices or documents in lieu of invoices, then ask for invoice-like numbering continuity and reconstruction. The spec currently has no branch for "this POS sale must issue a Facture."  
**Suggested fix:** Add a legal decision table: anonymous B2C ticket, identified B2C receipt, B2B/TVA invoice request, charge-to-account sale, and Encaissement/quittance. For each, state whether it is a ticket, receipt/quittance, invoice, or document in lieu of invoice, and what numbering scope applies. If legal counsel cannot exclude account sales from Art. 18, issue a real `Facture` for those cases.

### [P1] Offline allocation mismatch is still customer-visible because the printout includes allocations and balance

**Dimension:** offline, consistency  
**Spec location:** §7.4 lines 558-568; §8.8 lines 634-652; §9.1 lines 658-669  
**Evidence:** v1.1 says no amended receipt is needed because only internal allocation routing differs at lines 567-568. But the printed Encaissement is required to show allocated invoices and new balance at `docs/superpowers/specs/2026-05-13-pos-customer-accounts-design-v1.1.md:636`, and the offline flow prints immediately before server allocation at lines 664-668.  
**Issue:** The allocation is not purely internal if it appears on the customer-facing receipt. If the server reroutes payment to CustomerAdvance because another payment closed the invoice, the printed invoice allocations and new balance can be wrong.  
**Impact:** Customers can dispute invoices using a receipt that names allocations the server did not ultimately apply. AR statements and receipts diverge.  
**Suggested fix:** Either remove invoice allocation and new-balance claims from offline printed Encaissement, marking them as "allocation preview as of HH:MM", or produce a server reconciliation/amended receipt when authoritative allocation differs.

### [P2] AML offline behavior contradicts "accepting cash always allowed offline"

**Dimension:** risk, consistency  
**Spec location:** §2.5 lines 164-174; §6.2 lines 445-459; §6.5 lines 491-494; §9.4 lines 695-705  
**Evidence:** v1.1 says `OverAmlCap` is push-required and blocked offline at lines 451-454, while §6.5 says accepting cash, including on-account payment and charge-to-account tender split, is always allowed offline at line 493. §9.4 then shows a 6,000 TND offline cash sale exceeding the Tunisia cap, with local customer creation and sale close at lines 697-702.  
**Issue:** The spec uses "OverAmlCap" as a blocked offline override but also demonstrates an offline over-cap cash sale proceeding after identification. It does not distinguish "cap exceeded but legally allowed after identification" from "cap exceeded and must be refused/online-approved."  
**Impact:** Implementers can either block legitimate identified high-value sales offline or allow illegal over-cap cash acceptance. The cashier UX will be ambiguous at the moment tender pushes the transaction over the threshold.  
**Suggested fix:** Define AML states explicitly: below cap anonymous, above identification threshold requiring Partner/national ID, above hard legal cap blocked, and manager-override-only thresholds if any. The POS should evaluate before drawer open and before fiscal seal, with jurisdiction-specific hard caps.

### [P2] Dedup scoring lacks minimum offline identity requirements and validation gates

**Dimension:** offline, data integrity  
**Spec location:** §2.5 lines 164-174; §7.5 lines 569-592; §9.4 lines 695-705  
**Evidence:** v1.1's scoring accepts exact tax/national ID as high-confidence at line 577, uses normalized E.164 phone at line 573, and allows inline customer creation from the till at lines 170-172. The codebase has a Tunisian/French/Italian/UK tax ID validator in `apps/api/app/Modules/Partner/Domain/Services/TaxIdValidationService.php:17`, including Tunisian matricule pattern validation at `:67`, but v1.1 does not require it in the offline-create path.  
**Issue:** The spec does not define the minimum required fields for offline customer creation. If no phone and no validated national/tax ID are present, scoring degenerates to fuzzy name/address matching. If the cashier types an invalid tax ID, "exact match" can merge bad identifiers with bad identifiers.  
**Impact:** Offline-created customer accounts can be accepted with identifiers too weak to deduplicate safely, then later merged or rejected after fiscal receipts have already been printed.  
**Suggested fix:** Define minimum field sets by trigger. For ordinary account creation, require at least normalized phone or email plus name; for AML threshold, require validated national/tax ID format before fiscal seal. Run country-specific validation locally or block offline AML sales until validation can be performed online.

### [P2] Feature flags are listed but not tied to service gates or migrations

**Dimension:** implementation  
**Spec location:** §10.3 lines 742-750; §5.1-§5.6 lines 295-419  
**Evidence:** v1.1 lists `pos_customer_accounts_enabled` and sub-flags at lines 742-750. The service pseudocode for `EncaissementService`, `OnAccountChargeService`, `CustomerService`, and web payment retrofit contains no feature-flag checks at lines 301-419.  
**Issue:** The flags are operationally meaningless unless the spec states where they are enforced. Migration defaults alone do not stop routes, sync handlers, local UI, or receipt variants from being used.  
**Impact:** A partially deployed tenant can receive new receipt types or account-policy mutations before the UI, GL, sync, and reports are all compatible.  
**Suggested fix:** Add gate points: route middleware, POS config sync, local UI visibility, server command handlers, receipt sync ingestion, and migration seed behavior. Each phase flag should have explicit allowed/blocked operations and tests.

### [P2] V3 is treated as future work even though the codebase already has a V3 cutover path

**Dimension:** consistency, implementation  
**Spec location:** §1.3 lines 67-76; §4.4 lines 289-291; §10.4 lines 751-759  
**Evidence:** v1.1 says V3 hash payload is out and future at lines 71 and 289-291. The codebase already has terminal `fiscal_schema_version` defaulting to 2 and cutover to 3 at `apps/api/database/migrations/2026_05_01_000002_add_fiscal_schema_version_to_pos_terminals.php:14`; `ReceiptFinalizationService` dispatches to V2 or V3 at `apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:68`; the POS client computes V3 hashes when terminal state says version 3 at `apps/pos/src/lib/offline/receiptService.ts:402`.  
**Issue:** The spec frames V3 as a future architectural effort, but the repo already supports V3 for POS receipts. The real question is not whether to design V3 from scratch; it is whether customer-account receipt fields must be added to the existing V3 canonical payload and whether terminals must cut over before account features ship.  
**Impact:** Implementers may bolt customer-account semantics onto legacy V2 while a better existing V3 path is available, or they may miss necessary V3 changes because the spec says "no changes."  
**Suggested fix:** Reframe V3 as an existing capability. Add a decision: either require V3 cutover for all terminals before enabling account receipts, or explicitly extend both V2 and V3 payloads. Do not say "V3 future" while relying on V2 as the only customer-account proof.

### [P3] Override names and permission keys are not a single source of truth

**Dimension:** consistency  
**Spec location:** §3.3 lines 216-231; §6.1 lines 425-443; §6.2 lines 445-459; §6.6 lines 496-512  
**Evidence:** The override enum uses `OverAmlCap` at line 221; the permission key is `pos.override.aml_cap` at line 438; the approval endpoint returns `required_permission` from a mapping at lines 500-512.  
**Issue:** This is not wrong, but the spec does not declare the mapping table as normative. Future code can drift between enum names, UI labels, and Spatie permission keys.  
**Impact:** ApprovalService tests may pass for one string while seeders or UI use another, causing overrides to fail or authorize incorrectly.  
**Suggested fix:** Add an explicit mapping table: `override_type`, required permission, allowed channels, offline policy, audit-required fields, and translation key. Use it as the source for seeders, backend validation, and frontend rendering.
