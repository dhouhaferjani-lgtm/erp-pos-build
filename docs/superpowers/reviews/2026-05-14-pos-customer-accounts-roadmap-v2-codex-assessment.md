# Adversarial assessment — POS Customer Accounts Phased Plan (Roadmap v2)
**Reviewer:** Codex
**Assessment date:** 2026-05-14
**Document assessed:** `/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md`
**Verdict:** MAJOR-REVISION-NEEDED
**Total findings:** 0 CRITICAL, 3 MAJOR, 3 MINOR

## Executive summary
- The top-level phase order is broadly faithful to source-of-truth v3: Phase 1 correctly gates customer-facing work and correctly folds the receipt-chain clean rebuild into the fiscal-event foundation.
- The plan has one locked-foundation omission that is not optional: source-of-truth §8 off-device durability has no phase home. That is a D1 violation, not implementation detail.
- Phase 2's account-payment slice is directionally right, but the roadmap under-owns two prerequisites: `Payment.origin` / `Payment.fiscal_event_id` schema ownership, and the local balance snapshot needed for an `ACCOUNT_PAYMENT_RECEIPT`.
- The Z-report rebuild is independent at the chain-protocol level, but not physically isolated in the codebase. It shares local `terminal_state` and NF525 export/verification surfaces, so "parallel" needs a coordination caveat.
- Most concrete codebase claims are accurate when scoped to the offline Tauri POS. One claim is too broad: server-side POS already has partner/customer attachment fields and flows.

## Grounding fidelity
Roadmap v2 is mostly consistent with the locked source-of-truth. It preserves D1/D2/D3 device authority and canonical-bytes-verbatim rules in Phase 1 (`roadmap v2:17, 29-31`), keeps `ACCOUNT_PAYMENT` and `ACCOUNT_CHARGE` distinct (`roadmap v2:57, 71`; source-of-truth D6 `:253`), keeps charge-to-account later (`roadmap v2:67-73`; D9 `:256`), and correctly treats the receipt rebuild as foundational (`roadmap v2:37-44`; source-of-truth §12 `:216-226`, D10 `:257`).

Locked-decisions re-assessment:

| Decision | Re-assessment |
|---|---|
| D1 | Partially reflected. Device authority, clock, exception/recovery, and company-integrity are present, but §8 off-device durability is absent from all phases (`source-of-truth:152-164`, D1 `:248`). |
| D2 | Reflected. Server verify-only and no reserialize-for-hash are explicit (`roadmap v2:17, 31, 39-41`; source-of-truth `:95-99`, D2 `:249`). |
| D3 | Reflected. Canonical bytes are in Phase 1 schema and strict parser is server-side (`roadmap v2:29-31`; source-of-truth `:34-37`, D3 `:250`). |
| D4 | Reflected. Typed append-only fiscal ledger and chain-recovery events are Phase 1 (`roadmap v2:29-35`; source-of-truth D4 `:251`). |
| D5 | Reflected. Printable work is modeled as projection (`roadmap v2:59`; source-of-truth `:41-51`, D5 `:252`). |
| D6 | Reflected. `ACCOUNT_PAYMENT` and `ACCOUNT_CHARGE` are separate phases and event types (`roadmap v2:57, 71`; D6 `:253`). |
| D7 | Reflected. Reuse/rework/discard/new split matches source-of-truth §12, including the `Nf525DataProvider` correction (`roadmap v2:37-41`; source-of-truth `:222-225`). |
| D8 | Reflected. One fiscal pattern and clean rebuild are Phase 1 (`roadmap v2:24-46`; D8 `:255`). |
| D9 | Reflected. Charge-to-account remains Phase 3 (`roadmap v2:67-73`; D9 `:256`). |
| D10 | Partially reflected. Phase order matches, but first-slice deployability is weakened by the missing balance snapshot and ambiguous `Payment.origin` / `fiscal_event_id` ownership (`roadmap v2:55-59`; D10 `:257`). |
| D11 | Mostly reflected. Guardrails are named (`roadmap v2:17-20`), but the "no unverified codebase claims" guardrail needs one wording correction on customer attach. |
| D12 | Reflected. Phase 1 includes `signature_status`, signature object, async-capable interface, and hash-only provider (`roadmap v2:29, 32`; D12 `:259`). |
| D13 | Reflected. Phase 1 includes per-class exception path, `canonical_parse_failure`, quarantine/export reconciliation, and chain-recovery events (`roadmap v2:33-34`; source-of-truth `:125-148`, D13 `:260`). |
| D14 | Reflected. Phase 2 uses separate `ACCOUNT_PAYMENT_RECEIPT`, and Phase 3 separates charge-to-account (`roadmap v2:57-59, 67-73`; D14 `:261`). |
| D15 | Reflected. Tunisia general-retail / food-service boundary is inherited and restated (`roadmap v2:20, 112`; source-of-truth `:185-190`, D15 `:262`). |

The plan also faithfully reflects the codebase reality audit's receipt-chain scoping map. The actual code still supports that map: `ReceiptFinalizationService` locks `Terminal` and computes server hashes (`apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:54-82`), `ReceiptSyncService` throws and rolls back on offline/server hash mismatch (`apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:622-630`), `ReceiptHashService::verifyTerminalChain()` recalculates from receipt models (`apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:138-175`), and `Nf525DataProvider` reads `Receipt`/`ZReport` models and recalculates chains (`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:104-116,331-362`).

## Findings
### [MAJOR] Source-of-truth §8 off-device durability has no phase home
**Dimension:** completeness
**Location:** Roadmap v2 Phase 1 scope omits durability (`roadmap v2:28-46`); Phases 2-5 also omit it (`roadmap v2:50-85`).
**Evidence:** Source-of-truth §8 mandates at least one off-device durability path, separate key custody, unsynced-risk indicator, forced archive/export, maximum-unsynced threshold, and incident register (`source-of-truth:152-164`). D1 explicitly pairs device authority with §8 durability (`source-of-truth:248`).
**Issue:** The plan makes the device the fiscal source of truth in Phase 1 but does not assign the conservation controls that make device authority survivable when a terminal is lost, stolen, or destroyed before sync.
**Impact:** The Phase 1 spec can accidentally deliver an authoritative local ledger with no off-device conservation path. That corrupts the foundation specs and makes the Phase 1 + Phase 2 go-live slice non-compliant with the locked foundation.
**Suggested fix:** Add a Phase 1 scope bullet for §8 durability controls: encrypted removable archive/LAN peer/NAS/cloud-sync path, key custody outside the terminal disk, operator-visible unsynced age/count, forced archive/export threshold, maximum-unsynced escalation, and device-loss incident register. If implementation is split, make it a Phase 1 gate before Phase 2 customer-facing deployment, not a later roadmap phase.

### [MAJOR] `Payment.origin` / `Payment.fiscal_event_id` ownership is misplaced or under-specified
**Dimension:** sequencing
**Location:** Roadmap v2 Phase 2 server projection says `Payment` rows get `origin='pos'` and `fiscal_event_id` (`roadmap v2:58`), but Phase 1 omits the schema/writer change (`roadmap v2:28-46`).
**Evidence:** The current `Payment` model fillable fields have no `origin` and no `fiscal_event_id` (`apps/api/app/Modules/Treasury/Domain/Payment.php:70-94`). Existing POS payment creation writes `Payment` rows without those fields (`apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:251-266`). The audit explicitly classifies adding nullable `Payment.origin`, `Payment.fiscal_event_id`, `PaymentOrigin`, and writer updates as a Phase 1 design implication (`docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md:65-75,172`).
**Issue:** Roadmap v2 uses the fields in Phase 2 but does not make their schema and writer updates a clear phase deliverable. Worse, the audit says the fields belong in Phase 1 because the receipt-chain clean rebuild makes `SALE_RECEIPT` fiscal events foundational, and existing POS payment writers need origin/fiscal-event attribution too.
**Impact:** The Phase 2 spec can start from a projection contract that the database cannot represent. The Phase 1 receipt rebuild may also ship without payment rows linked back to their fiscal source, weakening idempotency, audit traceability, and reconciliation classification.
**Suggested fix:** Move the `Payment.origin` / `Payment.fiscal_event_id` migration, `PaymentOrigin` enum, and writer updates into Phase 1, or explicitly make them the first Phase 2 sub-gate before `ACCOUNT_PAYMENT` projection. The roadmap should state which existing writers are updated (`PaymentController::store`, `PaymentController::storeMultiple`, and `ReceiptPaymentService`) and how existing POS sale payments are attributed.

### [MAJOR] Phase 2 first slice does not explicitly own the balance snapshot required by `ACCOUNT_PAYMENT_RECEIPT`
**Dimension:** first-slice
**Location:** Roadmap v2 Phase 2 customer mirror / printable scope (`roadmap v2:55-59`).
**Evidence:** The printable architecture says `ACCOUNT_PAYMENT_RECEIPT` contains customer, previous balance, paid amount, remaining balance, and payment method (`/Users/houssamr/Downloads/pos_printable_documents_architecture.md:282-287`). The existing server `Partner` model has cached balance fields (`apps/api/app/Modules/Partner/Domain/Partner.php:96-103`), and `PartnerBalanceService` computes receivable/advance balances by GL aggregation per the audit (`docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md:136-140`). Roadmap v2 says only "local SQLite customer table" synced from `Partner`, without naming the balance snapshot or staleness/reconciliation contract (`roadmap v2:55`).
**Issue:** `ACCOUNT_PAYMENT` is useful only if the cashier can issue a receipt showing the balance context that the owner strategy requires. Server-side allocation is correctly `server_reconciles` (`roadmap v2:63`), but the offline receipt still needs an authoritative-at-capture snapshot: previous balance as known locally, paid amount, projected remaining balance, and a staleness marker if needed.
**Impact:** Phase 1 + Phase 2 may not be a deployable first customer-value slice: it can seal money received, but the printable can be incomplete or misleading, and reconciliation semantics will be pushed into implementation instead of owned by the phase.
**Suggested fix:** Expand Phase 2 scope to say the customer mirror includes the minimum balance projection needed for `ACCOUNT_PAYMENT_RECEIPT`: `receivable_balance`, `credit_balance` or an equivalent account balance snapshot, `balance_updated_at`, and local receipt fields for previous/remaining balance at seal time. If open-document details are not mirrored, state that FIFO allocation happens server-side after sync and that the printed remaining balance is the local snapshot, later reconciled.

### [MINOR] "No customer attach flow exists today" is too broad
**Dimension:** codebase-claim
**Location:** Roadmap v2 Phase 2 (`roadmap v2:56,61`).
**Evidence:** Offline Tauri POS lacks a local customer mirror: local migrations create products, payment tables, `offline_receipts`, `terminal_state`, and `receipt_qr_index`, but no customer/partner table (`apps/pos/src/lib/db/migrations.ts:8-145,596-625`). The current offline receipt locator explicitly removed "Find by customer" until local customer identifiers are mirrored (`apps/pos/src/components/pos/ReceiptLocatorScreen.tsx:78-83`). But server-side POS already filters/list receipts by `partner_id` (`apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:80-83,122-124`) and online receipt creation resolves `customer_id` / contact into `partner_id` (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:511-538,579-581`).
**Issue:** The claim is true for the offline Tauri customer search/create/attach flow, but false if read as "no POS customer attachment exists anywhere."
**Impact:** A Phase 2 spec could rebuild or ignore existing server-side partner attachment paths instead of cleanly extending them to the offline customer mirror and fiscal-event flow.
**Suggested fix:** Reword to: "No offline Tauri customer search/create/attach flow exists today; server-side POS receipt/order paths already carry `partner_id` and should be reused or adapted where appropriate."

### [MINOR] Z-report "parallel" claim needs a physical-coupling caveat
**Dimension:** sequencing
**Location:** Roadmap v2 Z-report section (`roadmap v2:89-91`) and Phase 1 out-of-scope line (`roadmap v2:46`).
**Evidence:** Source-of-truth §12 says the Z-report chain is independent and later (`source-of-truth:226,271`). Code confirms protocol separation server-side: receipt chain state is on `pos_terminals.last_hash/current_sequence` (`apps/api/database/migrations/2026_01_08_190429_create_pos_terminals_table.php:40-44`), while Z reports have their own `pos_z_reports.fiscal_hash/previous_z_hash/z_number` (`apps/api/database/migrations/2026_01_08_190644_create_pos_z_reports_table.php:33-39`) and `ZReportHashService` reads prior Z reports, not `pos_terminals.last_hash` (`apps/api/app/Modules/POS/Domain/Services/ZReportHashService.php:156-179`). But local code stores both receipt and Z state in `terminal_state`: receipt fields are selected at `apps/pos/src/lib/db/repositories/terminalStateRepository.ts:91-95`, receipt advancement updates `last_hash/hash_sequence` at `:222-225`, and Z advancement updates `z_last_hash/z_hash_sequence/z_number` at `:262-275`. NF525 export/verification also touches both receipt and Z verification paths (`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:104-116,331-362,467-475`).
**Issue:** "Independent" is accurate for chain semantics, but "schedulable alongside" can be misread as physically isolated. It is not: migrations, local terminal-state repository code, and export/verification code are shared surfaces.
**Impact:** Parallel Phase 1 and Z-chain specs could race on `terminal_state` migrations, repository methods, and `Nf525DataProvider` refactors.
**Suggested fix:** Keep Z-report chain rebuild separate, but add: "Independent at chain-protocol level; coordinate migrations and repository/export touchpoints with Phase 1 because both use local `terminal_state` and NF525 provider surfaces."

### [MINOR] Phase 1 should name the new typed fiscal-event outbox/ingestor explicitly
**Dimension:** completeness
**Location:** Roadmap v2 Phase 1 server mirror scope (`roadmap v2:31`).
**Evidence:** Current POS sync routes are per-resource (`/pos/reports/z/sync`, `/pos/receipts/sync`, `/pos/sync/pull`, `/pos/voucher-ledger/sync`, etc.) in `apps/api/app/Modules/POS/routes.php:75-94`; there is no generic typed-envelope fiscal-event endpoint. The audit explicitly says "Phase 1 adds a named `OutboxIngestor` + route, distinct from `/pos/receipts/sync`" (`docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md:98-100,170`).
**Issue:** "Server-side verify-only mirror — ingest sealed events verbatim" is directionally correct, but the roadmap does not name the new transport/ingestor boundary that the audit says must be explicit.
**Impact:** The Phase 1 spec could accidentally retrofit `/pos/receipts/sync` into a fiscal-event endpoint, preserving per-resource coupling and making later event types harder to add.
**Suggested fix:** Add a Phase 1 bullet: "New typed fiscal-event outbox endpoint and `OutboxIngestor`, distinct from `/pos/receipts/sync`; receipt sync is either rebuilt on top of it or explicitly bridged without becoming the source of truth."

## Codebase-claim verification
| Roadmap claim | Verdict | Evidence |
|---|---|---|
| "The POS has no customer mirror today" (`roadmap v2:55`) | Confirmed for offline Tauri POS. | Local migrations have no customer/partner table; `partner_id` appears only in `receipt_qr_index` for receipt lookup readiness (`apps/pos/src/lib/db/migrations.ts:8-145,596-625`). Audit agrees (`docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md:81-88,169`). |
| "`createPOSPaymentEntry` is cash→revenue only / AR GL path does not exist today" (`roadmap v2:71`) | Confirmed. | `ReceiptPaymentService` states POS payments are direct to revenue (`apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:34-43`) and creates Treasury `Payment` rows per payment line (`:251-266`). `GeneralLedgerService::createPOSPaymentEntry()` debits repository account and credits `ProductRevenue`, with no AR or partner line (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1187-1252`). |
| "No customer attach flow exists today" (`roadmap v2:56`) | Partially confirmed; wording too broad. | Offline Tauri lacks the flow and removed the customer tab (`apps/pos/src/components/pos/ReceiptLocatorScreen.tsx:78-83`), but server-side POS has `customer_id`/`partner_id` paths (`apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:80-83,122-124`; `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:511-538,579-581`). |
| Receipt-chain reuse/rework/discard/new classification (`roadmap v2:37-41`) | Confirmed. | Current server finalization locks and recomputes (`ReceiptFinalizationService.php:54-82`), sync throws on mismatch (`ReceiptSyncService.php:622-630`), verification recalculates from model fields (`ReceiptHashService.php:138-175,189-198`), and `Nf525DataProvider` reads model rows and recalculates chains (`Nf525DataProvider.php:104-116,331-362`). `Nf525XmlBuilder` is a DTO-to-XML serializer, not chain truth (`Nf525XmlBuilder.php:99-164`). This matches source-of-truth §12 (`source-of-truth:222-225`). |
| "`PaymentAllocationService` is reused with a refactor" (`roadmap v2:58,61`) | Confirmed. | The service exposes `applyAllocation()` (`apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:83-104`), but depends on `CompanyContext` (`:88-89`) and `Auth::user()` for advance GL creation (`:231-264`), and allocates against posted invoices/confirmed sales orders (`:340-356`). Command-DTO refactor is genuinely Phase 2, after the Phase 1 payment-origin schema issue is resolved. |
| `Partner` model + `CustomerCategory` exist server-side (`roadmap v2:61`) | Confirmed. | `Partner` fillable includes `customer_category`, credit/balance fields, phone/email, and active status (`apps/api/app/Modules/Partner/Domain/Partner.php:86-124`); it casts `customer_category` (`:129-134`) and has `isB2B()` (`:185-190`). `CustomerCategory` enum is `individual|business` (`apps/api/app/Modules/Partner/Domain/Enums/CustomerCategory.php:7-19`). |

## Completeness check
| Locked requirement | Phase home in roadmap v2 | Assessment |
|---|---|---|
| §1 preflight verification gate | Phase 1 (`roadmap v2:36`) | Owned. |
| Non-retrofittable `signature_status` enum + signature object | Phase 1 (`roadmap v2:29,32`) | Owned. |
| Per-anomaly-class exception path including `canonical_parse_failure` | Phase 1 (`roadmap v2:33`) | Owned. |
| Chain-recovery events | Phase 1 (`roadmap v2:34`) | Owned. |
| Clock/time model + normative closure-period rule | Phase 1 (`roadmap v2:35`) | Owned. |
| Off-device durability (§8) | No phase | Not owned. Major gap. |
| Immutable company-level manifests (§9) | Phase 1 scaffolding (`roadmap v2:42`) | Owned for engine-level record types; population correctly depends on terminal/closure rollout per source-of-truth Appendix A (`source-of-truth:301`). |
| Strict parser deriving structured payload from canonical bytes | Phase 1 (`roadmap v2:31`) | Owned. |
| Typed fiscal-event outbox/ingestor | Implied by Phase 1 server mirror, not named (`roadmap v2:31`) | Needs explicit naming because audit says no generic endpoint exists and Phase 1 adds one (`codebase reality:98-100,170`). |
| Payment origin/fiscal-event linkage | Mentioned as Phase 2 projection fields (`roadmap v2:58`) | Under-owned/misplaced. Audit places schema + writer updates in Phase 1 (`codebase reality:74,172`). |

