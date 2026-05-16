# Adversarial review round 3 — POS Fiscal Event Engine spec v2.0

**Reviewer:** Codex (round 3)
**Review date:** 2026-05-14
**Spec reviewed:** apps/erp/docs/superpowers/specs/2026-05-13-pos-fiscal-event-engine-and-customer-accounts.md
**Round-1 review:** apps/erp/docs/superpowers/reviews/2026-05-13-pos-customer-accounts-codex-review.md
**Round-2 review:** apps/erp/docs/superpowers/reviews/2026-05-13-pos-customer-accounts-codex-review-round2.md
**Verdict:** BLOCK
**Total findings:** 4 BLOCKER, 8 P1, 6 P2, 1 P3

## Executive summary

- The charge-to-account path still routes an `on_account` marker through the current POS payment service, which creates Treasury Payments and direct-to-revenue GL entries for every payment row.
- The proposed V3 `SignatureProvider` is not the existing V3. Current server/client V3 is receipt-specific, hashes `hash(canonical_json)` with `previous_hash` as a JSON string, and does not bind fiscal event fields.
- The offline ACCOUNT_PAYMENT flow is not implementable as written: it claims a local POS write to `Treasury.Payment`, but the Tauri SQLite schema only mirrors payment methods/repositories and offline receipts, not Treasury Payment rows.
- v2.0 adds the right concepts for overrides, admin chains, identity aliases, and cash-out policies, but several enforcement points are still prose-only: fiscal-event ingestion does not re-run override policy, virtual admin terminals are underspecified, and cross-chain status ordering is not actually proven.
- Legal risk remains for Tunisian B2B/account sales. The appendix says conservative default is a Facture, while the actual charge-to-account data flow still emits a POS ticket.

## Adequacy of v2.0 responses to round-2 findings

- **R1 settlement vs payment lines:** REFRAMED-UNSAFELY — v2.0 still puts an `on-account-marker` in the existing receipt payment path, whose code creates Treasury Payment rows and direct-to-revenue GL entries for each tender row.
- **R2 account-status and override inalterability:** REFRAMED-DEFENSIBLY — moving `ACCOUNT_STATUS_CHANGED`, `OVERRIDE_AUDITED`, and `CASH_OUT_EXECUTED` onto `fiscal_events` addresses the no-chain problem, but cross-terminal ordering and ingestion-policy enforcement are still weak.
- **R3 origin backfill:** ADEQUATE — no backfill for first tenants plus a conditional future legacy rule is sufficient, provided forward writes add `origin`/`fiscal_event_id`.
- **R4 PIN crypto for actual Tauri stack:** REFRAMED-DEFENSIBLY — v2.0 now names the actual AES-GCM file-key threat model, but Ed25519 verification, trusted TTL anchoring, and `/sync-pins` rejection still need explicit implementation gates.
- **R5 sealed snapshot vs live FK:** REFRAMED-DEFENSIBLY — v2.0 forbids rewriting fiscal-event `partner_id` and uses read-time alias resolution, but alias closure and balance aggregation need stronger invariants.
- **R6 cash_out_policy table + audit field promotion:** REFRAMED-DEFENSIBLY — the table and typed fields exist now, but in-flight policy races and illegal-vs-approval semantics remain unresolved.
- **R7 Tunisian receipt classification:** REFRAMED-UNSAFELY — the appendix acknowledges Facture risk for B2B charge-to-account, but the actual §10.2 flow still prints a SALE_RECEIPT ticket.
- **R8 offline allocation mismatch:** REFRAMED-DEFENSIBLY — removing per-invoice allocations from offline receipts is the right fix, but the spec still needs a guaranteed delivery path for material amended printables.
- **R9 AML state machine:** ADEQUATE — v2.0 now evaluates cumulative cash in the current transaction before drawer open and fiscal seal.
- **R10 minimum offline identity:** REFRAMED-UNSAFELY — field minimums are better, but the named `TaxIdValidationService` validates business tax IDs, not B2C national IDs needed for walk-in AML.
- **R11 feature flag gate points:** NEW-RISK — the gate list is concrete, but it names a new `OutboxIngestor` that does not map to the existing receipt sync stack.
- **R12 V3 framing:** REFRAMED-UNSAFELY — v2.0 calls the new event payload "V3" even though the codebase already has a different V3 receipt hash.
- **R13 override mapping single source:** ADEQUATE — §6.3 is now a normative mapping table.

## New findings (round-3)

### [BLOCKER] Charge-to-account still runs the AR marker through the real-payment pipeline

**Dimension:** accounting, implementation  
**Spec location:** §9.1 lines 1004-1006; §10.2 lines 1071-1078  
**Evidence:** v2.0 says `pos_receipts` and `ReceiptPaymentService` remain unchanged except for bridge emission at lines 1004-1006, then models charge-to-account with a `20 TND cash + 80 TND on-account-marker` receipt payment line at lines 1071-1074. Current `ReceiptPaymentService::processReceiptPayments()` loops over every payment row, creates a Treasury `Payment` at `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:251`, calls `GeneralLedgerService::createPOSPaymentEntry()` at `:269`, and links the row as a `ReceiptPayment` at `:297`. `GeneralLedgerService::createPOSPaymentEntry()` explicitly states POS payments are "DIRECT TO REVENUE (no AR account)" at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1188` and credits revenue for the payment amount at `:1238`.  
**Issue:** `on_account` is a settlement allocation, not money received. v2.0 leaves it inside the exact service path that treats every row as a cash/bank inflow and revenue posting. The later `ACCOUNT_PAYMENT` event for the 80 TND "credit-line draw" does not stop the earlier payment row from becoming a Treasury Payment and GL entry.  
**Impact:** A 100 TND sale with 20 TND cash and 80 TND AR can create a fake 80 TND Treasury Payment, debit a repository or fake method, and credit revenue through the existing payment path before the proposed composite AR journal runs. That is unbuildable without replacing the current POS payment abstraction.  
**Suggested fix:** Split receipt settlement from tender payments before Phase 3. Keep `pos_receipt_payments` for real inflows only, add a typed `pos_receipt_settlements` or equivalent for `on_account`, and make charge-to-account call one composite posting service that debits Cash 20 + AR 80 and credits Revenue/VAT once. Do not emit an `ACCOUNT_PAYMENT` for the AR draw unless it is explicitly renamed to an account debit event and excluded from Treasury Payment creation.

### [BLOCKER] V3SignatureProvider is a different protocol from the existing V3 receipt hash

**Dimension:** architecture, implementation  
**Spec location:** §2.4 lines 214-228; §2.5 lines 239-243; §9.1 lines 1004-1006  
**Evidence:** v2.0 V3 binds `event_type`, tenant/company/terminal/operator IDs, sequence number, partner snapshot, payload, totals, VAT, and payment breakdown, then hashes `SHA-256(previous_hash_bytes || serialized_bytes)` at lines 214-228. Current backend V3 maps a `Receipt` to only `receipt_number`, `posted_at`, `previous_hash`, total, currency, VAT/payment/voucher/audit sub-hashes, and exchange group at `apps/api/app/Modules/POS/Application/Services/Fiscal/V3/V3ReceiptHashComputer.php:72`; the builder top-level keys are receipt-only at `apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/CanonicalPayloadBuilder.php:10`. It returns canonical JSON and the caller computes `hash('sha256', $canonical)` at `apps/api/app/Modules/POS/Application/Services/Fiscal/V3/V3ReceiptHashComputer.php:49`. The POS client mirrors the same receipt-only input at `apps/pos/src/lib/offline/receiptService.ts:211` and hashes the canonical JSON directly at `:232`.  
**Issue:** v2.0 reuses the name "V3" for a new fiscal-event signature protocol that is not byte-compatible with the existing V3 receipt chain. The previous hash is also represented differently: v2.0 says `BYTEA` and `previous_hash_bytes`, while current terminal state is 64-character hex and current V3 embeds `previous_hash` as a JSON string.  
**Impact:** Golden vectors can pass for existing receipt V3 while every fiscal-event V3 vector fails, or worse, implementers may silently use the receipt V3 provider for fiscal events and not bind the fields v2.0 relies on for compliance. Chain verification will be ambiguous at cutover.  
**Suggested fix:** Rename the new provider to `FiscalEventV1SignatureProvider` or `V3_FISCAL_EVENT` and keep existing receipt V3 as `ReceiptV3SignatureProvider`. Define one binary representation for hashes: either all stored hashes are hex strings or all are `BYTEA`; do not mix. Add separate golden-vector fixtures for receipt V3 and fiscal-event signature V1 before implementation.

### [BLOCKER] Offline ACCOUNT_PAYMENT claims a local Treasury.Payment write that cannot exist

**Dimension:** offline, implementation  
**Spec location:** §3.1 lines 297-301; §10.1 lines 1049-1059  
**Evidence:** v2.0 says ACCOUNT_PAYMENT side effects create a Treasury `Payment` row at lines 297-300. In the offline scenario, step 5 says the POS writes `fiscal_events_local` and is atomic with "write to `Treasury.Payment`" at lines 1049-1055. The Tauri SQLite schema only has mirror tables for `payment_methods` and `payment_repositories` at `apps/pos/src/lib/db/migrations.ts:34`, plus `offline_receipts` at `:91`; there is no local `payments`/Treasury Payment table, and the existing sync route is receipt-specific (`/pos/receipts/sync`) at `apps/api/app/Modules/POS/routes.php:85`.  
**Issue:** A desktop POS in offline mode cannot create the Laravel/PostgreSQL Treasury Payment row. The spec conflates local fiscal sealing with server-side accounting projection.  
**Impact:** Implementers have no atomic boundary for Phase 2. If they print the fiscal payment receipt offline before a server `Payment` exists, the AR ledger remains stale until sync. If they try to mirror Treasury Payment locally, they must add a new local accounting projection and replay semantics that v2.0 does not specify.  
**Suggested fix:** Treat offline ACCOUNT_PAYMENT as a sealed local fiscal event plus cash-drawer local state only. On server ingestion, create the Treasury Payment and allocations as an idempotent projection of the fiscal event. Add a local `account_payment_projection` only for UI/balance estimates, explicitly marked non-authoritative, and remove "atomic with Treasury.Payment" from offline steps.

### [BLOCKER] B2B charge-to-account still prints a ticket despite the spec's Facture caveat

**Dimension:** compliance, consistency  
**Spec location:** §5.3 lines 700-708; §10.2 lines 1061-1078; Appendix A lines 1215-1217  
**Evidence:** v2.0 requires B2B charge-to-account identity fields at lines 700-707 and acknowledges the conservative Tunisian default: issue a Facture for identified B2B charge-to-account at lines 1215-1217. The actual charge-to-account flow emits `SALE_RECEIPT` through `pos_receipts`, bridges it, and prints the existing SALE_RECEIPT at lines 1071-1078. Tunisian Ministry of Finance guidance says VAT taxpayers must use invoices numbered in an uninterrupted series (https://www.finances.gov.tn/fr/node/75) and that invoice numbers must be taken from an uninterrupted series (https://www.finances.gov.tn/fr/node/952). Jurisite's Code TVA Art. 18 text states sales invoices must be numbered in an uninterrupted series (https://www.jurisitetunisie.com/tunisie/codes/tva/tva1060.htm).  
**Issue:** The legal caveat is not wired into the data flow. A B2B account sale can follow the ticket path even though v2.0 admits the conservative treatment is an invoice.  
**Impact:** A Tunisian DGI inspector can classify the identified B2B account sale as a facture or invoice substitute and reject the per-terminal ticket sequence as insufficient. The fiscal event chain being strong does not fix issuing the wrong document type.  
**Suggested fix:** Add an explicit branch before fiscal seal: if `partner_kind=Business` or tax ID is present/requested in TN, route through the B2B `Facture` document flow and emit a future-compatible `INVOICE_POSTED` fiscal event or invoice bridge. The POS ticket path should be limited to anonymous/ordinary B2C sales unless legal counsel signs off otherwise.

### [P1] FiscalEventEngine append is not reconciled with existing transaction and lock boundaries

**Dimension:** architecture, implementation  
**Spec location:** §2.3 lines 162-185; §2.5 lines 239-243; §9.1 lines 1004-1006  
**Evidence:** v2.0 says `append()` runs inside the caller transaction and locks the terminal chain head at lines 164-184. Current `ReceiptFinalizationService::finalize()` already opens `DB::transaction()`, locks the `Terminal` row at `apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:54`, writes `receipt.previous_hash` and `chain_sequence` from terminal receipt counters at `:65`, updates `terminal.last_hash/current_sequence` at `:80`, and dispatches `ReceiptCreated` only after commit at `:88`. Current receipt sync nests finalization inside its own outer transaction and comments that Laravel will reuse savepoints at `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:612`.  
**Issue:** v2.0 says the legacy receipt path is "unchanged" but also requires bridge append inside finalization. It does not specify whether `FiscalEventEngine::append()` runs before or after `terminal.last_hash/current_sequence` are updated, whether it locks the same row again for new `fiscal_event_*` counters, or how nested transaction failures roll back both receipt and fiscal-event state.  
**Impact:** A bridge append inserted at the wrong point can seal an event for a receipt whose finalization later fails, or can fail after the receipt chain advanced. Reusing one `pos_terminals` row for two chains is workable, but only if the exact lock/update order is specified and tested.  
**Suggested fix:** Make the bridge call part of `ReceiptFinalizationService`'s existing transaction after receipt hash computation but before terminal save returns, using the already-locked terminal row. Add separate columns for receipt chain and fiscal-event chain, and document that both updates occur in one outer transaction with a failure test proving rollback of receipt, terminal counters, and fiscal_event insert.

### [P1] Fiscal-event immutability trigger omits TRUNCATE and schema-level bypasses

**Dimension:** architecture, compliance  
**Spec location:** §2.2 lines 117-160  
**Evidence:** v2.0 says `fiscal_events_immutability` rejects UPDATE and DELETE at line 158. PostgreSQL `CREATE TRIGGER` supports `TRUNCATE` as a distinct trigger event and only as statement-level; official docs list `INSERT`, `UPDATE`, `DELETE`, or `TRUNCATE` as trigger events and state TRUNCATE triggers may be defined only `FOR EACH STATEMENT` (https://www.postgresql.org/docs/15/sql-createtrigger.html). Existing receipt immutability likewise only creates a `BEFORE UPDATE OR DELETE` trigger at `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:183`.  
**Issue:** A trigger that only handles UPDATE/DELETE is not an append-only guarantee against TRUNCATE, partition detach/drop, disabled triggers, or privileged maintenance scripts.  
**Impact:** The strongest new compliance claim in v2.0 can be bypassed by operations outside row-level UPDATE/DELETE. This matters because `fiscal_events` is now the canonical fiscal store.  
**Suggested fix:** Add a `BEFORE TRUNCATE ON fiscal_events FOR EACH STATEMENT` trigger that raises, prohibit partition drops without archival verification, revoke TRUNCATE from app roles, add migration tests that TRUNCATE fails, and document DBA-only break-glass procedures with signed export before any maintenance operation.

### [P1] Offline override ingestion can seal policy bypasses as valid fiscal events

**Dimension:** risk, architecture  
**Spec location:** §6.2 lines 794-809; §6.3 lines 810-836; §6.6 lines 874-889; §8.3 lines 975-984  
**Evidence:** v2.0 says ApprovalService returns an approval token and downstream services emit `OVERRIDE_AUDITED` at lines 794-809. It also says offline approval emits to `fiscal_events_local` and queues for server replay at lines 887-889. Server-side ingestion verifies signature, chain linkage, and writes rows at lines 975-984; that list does not say it revalidates `override_mapping`, `allowed_channels`, permission snapshot signatures, or `offline_policy`.  
**Issue:** A buggy client or a terminal attacker can emit `OVERRIDE_AUDITED(over_aml_cap, channel=local_pin)` into `fiscal_events_local`. If ingestion only verifies hash/chain, the server will seal a semantically invalid override.  
**Impact:** The fiscal chain would prove the bypass happened, but it would also make the bypass look structurally valid. For high-risk override types, that is worse than a rejected sync because audit reviewers must distinguish authorized overrides from sealed unauthorized payloads.  
**Suggested fix:** Fiscal-event ingestion must be a semantic validator, not just a chain verifier. For `OVERRIDE_AUDITED`, re-run §6.3 mapping server-side: allowed channel, offline policy, approver permission at snapshot version, signed snapshot validity, and required audit fields. Reject invalid override events and require a compensating/error event only after manual incident review.

### [P1] Virtual admin terminal is not provisionable under the current terminal model

**Dimension:** implementation, architecture  
**Spec location:** §10.5 lines 1107-1117; §11.1 lines 1121-1128  
**Evidence:** v2.0 introduces a per-(tenant, company) virtual admin terminal with `is_virtual=true` at lines 1123-1128. The current `TerminalType` enum has only `web` and `physical` at `apps/api/app/Modules/POS/Domain/Enums/TerminalType.php:7`; the terminal model fillable has `type` but no `is_virtual` at `apps/api/app/Modules/POS/Domain/Terminal.php:90`. The table requires `location_id` at `apps/api/database/migrations/2026_01_08_190429_create_pos_terminals_table.php:31`, and the web-terminal migration enforces one web terminal per `(company_id, location_id)` at `apps/api/database/migrations/2026_02_19_000002_add_type_to_pos_terminals.php:26`.  
**Issue:** The proposed admin terminal is neither a physical terminal nor the existing per-location web terminal. The spec does not define its enum value, location binding, uniqueness constraint, provisioning path, genesis seed source, or whether it shares the existing web terminal.  
**Impact:** Server-originated `ACCOUNT_STATUS_CHANGED` and web-admin `ACCOUNT_PAYMENT` events can end up on arbitrary or duplicate terminal chains, making account history hard to reconstruct and potentially creating write hotspots or broken genesis records.  
**Suggested fix:** Add `TerminalType::VirtualAdmin` or a separate `fiscal_channels` table. Enforce `UNIQUE (tenant_id, company_id, type='virtual_admin')`, allow nullable `location_id` or assign a documented company HQ location, generate and store a signed genesis seed at tenant provisioning, and require every server-originated fiscal event to resolve through this provisioner.

### [P1] Cross-chain account-status ordering is not actually provable

**Dimension:** offline, architecture  
**Spec location:** §5.6 lines 737-760; §8.4 lines 986-997; §10.5 lines 1107-1117  
**Evidence:** v2.0 says an admin freeze emits on a virtual admin chain and an offline charge emits on the POS terminal chain; it claims the "freeze→offline-charge" sequence is provable from the chain at lines 1109-1117. The fiscal-events chain scope is per `(tenant_id, terminal_id)` at lines 239-243. Events carry wall-clock `event_timestamp` and `business_date` at lines 130-132.  
**Issue:** Per-terminal chains prove order only within each terminal. They do not prove a global order between the admin chain and the offline POS chain, and POS local clocks are not trusted enough to establish compliance-critical ordering across devices.  
**Impact:** A customer history can show both "Frozen at 10:00" and "charged offline at 09:58/10:03" depending on clock skew and sync timing. The audit trail proves both facts existed, but not the ordering v2.0 claims.  
**Suggested fix:** Reword the claim and add explicit timestamps: `origin_emitted_at`, `server_received_at`, `server_committed_sequence`, and `terminal_clock_offset_estimate`. Treat cross-chain ordering as a reconciliation read model with uncertainty, not as a hash-chain invariant. For Frozen accounts, require server-side compensating review when an offline charge syncs after a freeze commit.

### [P1] Dual event stores are left without a bridging contract

**Dimension:** architecture, implementation  
**Spec location:** §1.2 lines 75-78; §1.3 lines 82-88; §3.12 lines 545-558; §13 lines 1191-1195  
**Evidence:** v2.0 adds `fiscal_events` but also says existing `PaymentRecorded` continues firing and is bridged into the new chain for POS-originated Treasury Payment cases at line 75. It separately defers B2B document events and says existing `getHashableData()` stubs are future engine-compatible at lines 82-85 and 1191-1195. Current domain events extend Spatie `ShouldBeStored` at `apps/api/app/Shared/Domain/Events/DomainEvent.php:7`, persisted to `stored_events` by the Spatie table at `apps/api/database/migrations/2025_11_30_102448_create_stored_events_table.php:11`. Current audit subscriber writes `audit_events` and swallows failures at `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:803` and `:825`.  
**Issue:** There are now three records for some facts: Spatie `stored_events`, `audit_events`, and new `fiscal_events`. v2.0 does not define which service is allowed to bridge Spatie events into `fiscal_events`, whether bridging is synchronous or after-commit, or how to prevent duplicate fiscal rows.  
**Impact:** If `PaymentRecorded` is bridged via the existing async/after-commit event path, the round-1 atomicity problem returns. If it is bridged synchronously by Treasury code, then the Spatie event is not the source of truth. Without a contract, future InvoicePosted migration can double-record or miss fiscal events.  
**Suggested fix:** Declare `fiscal_events` as the source of truth for in-scope fiscal facts. Existing Spatie events remain domain/audit events only. Any fiscal bridge must be emitted synchronously by the command service before/inside transaction commit, with a unique `source_event_class/source_event_id` or `reference_document_id` constraint and tests proving no duplicate bridge rows.

### [P1] Payment.origin and fiscal_event_id are not in the current write model

**Dimension:** implementation  
**Spec location:** §1.2 line 75; §3.1 lines 297-301; §9.2 lines 1010-1019; §11.2 lines 1129-1139  
**Evidence:** v2.0 requires `payment.origin` and `payment.fiscal_event_id` forward-only at lines 1017-1019 and displays them in the unified listing at lines 1131-1138. Current `payments` migration has no `origin` or `fiscal_event_id` columns at `apps/api/database/migrations/2025_11_30_120000_create_treasury_tables.php:139`. The `Payment` model fillable lacks both fields at `apps/api/app/Modules/Treasury/Domain/Payment.php:70`. Existing web `PaymentController` writes Payment rows without those fields at `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:198` and `:571`; POS payment creation also lacks them at `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:252`.  
**Issue:** The spec states the target columns but not the write-path migration boundary. Until every Payment writer is updated, the unified listing will contain null origins or unlinked payments.  
**Impact:** The first Phase 2/3 tenant can get mixed rows where fiscal events exist but Treasury Payments cannot be traced back to them, especially for POS receipt payments still created by the legacy service.  
**Suggested fix:** In Phase 1, add nullable `origin` and `fiscal_event_id` columns plus a `PaymentOrigin` enum and update every Payment writer in the same PR. Phase 2 should make `fiscal_event_id` required for POS ACCOUNT_PAYMENT projections and leave legacy B2B web payments as `origin='web_admin', fiscal_event_id=null` until explicitly migrated.

### [P1] The national-id offline requirement uses the wrong validator

**Dimension:** risk, offline  
**Spec location:** §5.3 lines 700-708; §10.3 lines 1080-1093  
**Evidence:** v2.0 requires AML threshold flows to collect `national_id` and validate it locally via a POS port of `TaxIdValidationService` at lines 700-708. Current `TaxIdValidationService` validates country business registration/tax identifiers: French SIRET at `apps/api/app/Modules/Partner/Domain/Services/TaxIdValidationService.php:26`, Tunisian matricule fiscale at `:67`, Italian tax ID/VAT at `:93`, and UK VAT at `:17`. It does not validate Tunisian CIN or French CNI/passport identity numbers.  
**Issue:** AML for a B2C walk-in needs personal identity validation or capture rules, not business tax-registration validation. Porting the existing service gives false confidence and can block valid individuals or accept irrelevant business identifiers.  
**Impact:** The 6,000 TND walk-in scenario can fail offline for the wrong reason, or store a "national_id" that is actually a tax ID format. That weakens AML evidence and customer dedup.  
**Suggested fix:** Split `TaxIdValidationService` from a new `NationalIdentityValidationService`. For TN, define CIN/passport capture/format rules; for FR, define CNI/passport or refuse local validation and require online KYC. Use tax ID validation only for B2B charge-to-account.

### [P2] Fiscal event JSONB payloads lack typed DTO/schema ownership

**Dimension:** implementation  
**Spec location:** §2.2 lines 136-140; §3 lines 267-543; CLAUDE.md lines 21-23  
**Evidence:** v2.0 stores `partner_identity_snapshot`, `payload`, `totals`, `vat_breakdown`, and `payment_breakdown` as JSONB at lines 136-140, with per-event JSON examples throughout §3. Repository rules require JSONB columns to have corresponding PHP DTOs at `CLAUDE.md:21` and all status/type columns to use enums at `CLAUDE.md:39`.  
**Issue:** The spec says "JSON schema validation on emission" for cash-out at line 944, but it does not define PHP DTO classes, enum types, or a registry for every event payload.  
**Impact:** Implementers can push arrays/mixed payloads into `FiscalEventEngine::append()`, fail PHPStan level 8, and drift between TypeScript, PHP, and database constraints.  
**Suggested fix:** Add a `FiscalEventPayload` interface plus one readonly DTO per event type, Spatie Data serialization rules, PHP enums for `event_type`, `override_type`, `operation_type`, `origin`, and a generated TypeScript transform step. The JSON schema should be generated from or tested against those DTOs, not hand-maintained separately.

### [P2] Canonical JSON claims exceed the current encoder's supported value set

**Dimension:** implementation, consistency  
**Spec location:** §2.4 lines 214-228; §2.2 lines 136-140  
**Evidence:** v2.0 requires RFC 8785/JCS canonical JSON for arbitrary event payloads at lines 221-225. The current PHP encoder is hand-rolled for receipt V3, supports null/bool/int/string/array only, and throws on other value types at `apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/CanonicalJsonEncoder.php:51`. It also documents a PHP-vs-JS string escaping divergence for U+2028/U+2029 at `:98`. Existing receipt V3 avoids the problem by formatting monetary values as strings at `apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/CanonicalPayloadBuilder.php:27`.  
**Issue:** v2.0 event payloads are JSONB and include decimals, timestamps, nested objects, and free-text reason fields. Without a constrained DTO-to-canonical conversion contract, PHP and TypeScript can serialize different bytes.  
**Impact:** Offline POS may seal a local fiscal event whose server recomputation rejects on sync, or the server may accept payloads that cannot be reproduced by the verifier.  
**Suggested fix:** Define the canonical payload value grammar: no floats, all money integer cents, timestamps normalized UTC strings, free text normalized or escaped identically, arrays sorted where required. Either adopt a vetted JCS implementation for PHP and TS or extend the current encoder and add cross-language golden vectors for every event type.

### [P2] OutboxIngestor is named as a gate point but does not map to existing sync code

**Dimension:** implementation  
**Spec location:** §8.1 lines 950-968; §9.3 lines 1020-1038  
**Evidence:** v2.0 adds `POST /api/pos/sync/outbox` with typed envelopes at lines 950-968 and says server `OutboxIngestor` rejects disabled fiscal events at line 1036. Current POS routes expose `/pos/receipts/sync` and `/pos/sync/pull` at `apps/api/app/Modules/POS/routes.php:85`; `rg` finds no `OutboxIngestor` class in `apps/api/app/Modules/POS`. Existing receipt ingestion is `ReceiptSyncService`, and the client payload path is `receiptToPayload()` at `apps/pos/src/lib/sync/syncService.ts:1847`.  
**Issue:** The feature-flag gate is attached to a proposed component, but the spec does not state whether this replaces `ReceiptSyncService`, wraps it, or runs alongside it.  
**Impact:** Phase 1 can add fiscal_events while legacy receipt sync bypasses the new envelope and feature gates. Disabled tenants could still sync bridge-producing receipts through the old endpoint.  
**Suggested fix:** Define `OutboxIngestor` as a new class and route in Phase 1, then state the old `/pos/receipts/sync` endpoint is either unchanged for legacy receipts or internally adapted to emit a `SALE_RECEIPT_BRIDGE` through the same ingestion/gating pipeline. Add tests for flag-disabled fiscal event rejection on both routes.

### [P2] Permission snapshot TTL relies on an untrusted terminal clock

**Dimension:** risk, offline  
**Spec location:** §6.5 lines 859-873; §8.4 lines 986-997  
**Evidence:** v2.0 says signed permission snapshots are valid for 7 days offline and rejected after expiry at lines 867-872. The existing Tauri stack stores local state under SQLite/file storage and has no trusted time source in `apps/pos/src-tauri/Cargo.toml:15`. Existing crypto uses a local file key at `apps/pos/src-tauri/src/commands/crypto.rs:10`.  
**Issue:** A cashier with terminal access can roll back the local system clock unless the TTL is anchored to a monotonic counter or server-signed last-seen time.  
**Impact:** Revoked `pos.override.*` permissions can remain usable beyond 7 days offline on a manipulated terminal. The signed snapshot proves permissions were valid at `effective_at`, not that the terminal's current time is honest.  
**Suggested fix:** Store `last_server_seen_at`, `last_monotonic_tick`, and a signed `expires_at` in the permission snapshot. Reject approvals if wall-clock moves backwards beyond tolerance, if monotonic elapsed exceeds TTL, or if no server time anchor exists. Queue clock-tamper alerts in the outbox.

### [P2] FiscalDocument projection versioning does not define reprint semantics

**Dimension:** consistency, compliance  
**Spec location:** §4.1 lines 564-585; §4.4 lines 626-651  
**Evidence:** v2.0 defines `UNIQUE (fiscal_event_id, document_type, projection_version)` at lines 566-580 and says reprints mutate `reprint_count`/`last_reprinted_at` while `RECEIPT_REPRINT` remains future/audit-only at lines 583-585.  
**Issue:** When `projection_version` increments for a template or legal-mentions change, the spec does not say whether customer reprints use the original projection or latest projection. Both are plausible and have different legal meanings.  
**Impact:** A customer can receive a reprint that does not match the document originally handed over, or the system can keep printing an obsolete template after a legal change.  
**Suggested fix:** Store `first_printed_projection_version` and `active_projection_version`. Reprints of historical documents should default to the original rendered payload with "copie" metadata; legal amended documents should be new `*_AMENDED` projections referencing the original event and reconciliation/amendment event.

### [P2] Alias chains are not bounded or closed for balance aggregation

**Dimension:** data integrity, offline  
**Spec location:** §5.1 lines 672-684; §5.5 lines 724-735; §3.11 lines 525-543  
**Evidence:** v2.0's `partner_aliases` table has `PRIMARY KEY (temp_uuid)` and maps `temp_uuid -> canonical_uuid` at lines 672-681. It says reads follow the alias chain at line 684 and statements aggregate by canonical UUID at lines 728-733.  
**Issue:** The spec does not define whether `canonical_uuid` must be a root canonical partner, whether chained merges are collapsed, or how cycles are prevented.  
**Impact:** If temp A maps to B and B later maps to C, statements can aggregate A+B but miss C, or a bad manual merge can create a cycle that breaks account statements.  
**Suggested fix:** Add constraints and a resolver: `canonical_uuid` must not appear as `temp_uuid` unless a closure table is updated in the same transaction. Prefer `partner_alias_closure(alias_uuid, canonical_uuid, depth, reconciliation_event_id)` with a cycle check and tests for chained merge aggregation.

### [P3] `ACCOUNT_PAYMENT` is misnamed for credit-line draw events

**Dimension:** consistency  
**Spec location:** §3.1 lines 271-301; §10.2 lines 1071-1078  
**Evidence:** §3.1 defines `ACCOUNT_PAYMENT` as "customer pays toward existing receivables. No goods sold" at lines 271-273. §10.2 emits `ACCOUNT_PAYMENT` for the implicit 80 TND "credit-line draw" during a sale at lines 1071-1074.  
**Issue:** The same event type means both "cash received from customer" and "new AR created because customer did not pay." Those are opposite balance directions.  
**Impact:** Projectors, reports, and human auditors will misread account history unless they inspect an optional `linked_to` payload nuance every time.  
**Suggested fix:** Rename the sale-side event to `ACCOUNT_CHARGE` or `AR_CHARGE_CREATED`. Keep `ACCOUNT_PAYMENT` only for money received against AR/advance.
