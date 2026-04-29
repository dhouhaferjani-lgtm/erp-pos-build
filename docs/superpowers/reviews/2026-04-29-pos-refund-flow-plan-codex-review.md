# POS Refund Flow Plan — Codex Adversarial Review

Date: 2026-04-29
Reviewed plan: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md`
Authoritative base checked: `origin/dev`

## Verdict

**execute-with-changes** — the plan is directionally implementable, and `origin/dev` now contains H3/H4/H2, but the plan is not safe to execute as written. Phase A will not merge cleanly because it uses a non-existent `pos_receipts.status` column, reads `Terminal.fiscal_schema_version` before adding it, and does not actually migrate the create/payment/sync callers to finalization. The biggest implementation risk is not voucher logic; it is the receipt lifecycle cutover and all code that assumes receipts are fiscalized at creation time.

## Findings

### A. Blocker — Phase A schema names are wrong and Task 5 reads a column that does not exist yet

**Issue:** Task 4 creates/checks a `status` lifecycle on `pos_receipts`, but `origin/dev` uses `fiscal_status`, not `status`. Task 5 then reads `$terminal->fiscal_schema_version`, but Task 4 adds `fiscal_schema_version` to `pos_receipts`, not `pos_terminals`, and Task 32 does not add the terminal cutover field until Phase G. Phase A cannot compile or migrate independently.

**Plan evidence:**
- Phase A claims foundations can land first: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:20`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:22`.
- Task 4 adds `pos_receipts.fiscal_schema_version`: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:501`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:546`.
- Task 4 creates a check on `status`: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:549`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:551`.
- Task 4 trigger pseudocode references `OLD.status` and `NEW.status`: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:563`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:577`.
- Task 5 reads `$terminal->fiscal_schema_version`: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:635`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:640`.
- Task 32 only later introduces `Terminal.fiscal_schema_version`: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1190`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1194`.

**Repo evidence:**
- `pos_receipts` has `fiscal_hash` and `chain_sequence`, but no `status`: `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:38`, `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:47`.
- `fiscal_status` is added by the offline-sync migration: `apps/api/database/migrations/2026_04_18_211150_add_offline_sync_fields_to_pos_receipts.php:19`, `apps/api/database/migrations/2026_04_18_211150_add_offline_sync_fields_to_pos_receipts.php:21`.
- `Receipt` fillable includes `fiscal_status`, not `status`: `apps/api/app/Modules/POS/Domain/Receipt.php:150`, `apps/api/app/Modules/POS/Domain/Receipt.php:151`.
- `Terminal` has `current_sequence` and `last_hash`, but no `fiscal_schema_version`: `apps/api/app/Modules/POS/Domain/Terminal.php:36`, `apps/api/app/Modules/POS/Domain/Terminal.php:47`, `apps/api/app/Modules/POS/Domain/Terminal.php:89`, `apps/api/app/Modules/POS/Domain/Terminal.php:111`.
- `pos_terminals` migration has no schema-version column: `apps/api/database/migrations/2026_01_08_190429_create_pos_terminals_table.php:40`, `apps/api/database/migrations/2026_01_08_190429_create_pos_terminals_table.php:44`.

**Proposed change:** Move terminal cutover schema into Phase A before `ReceiptFinalizationService`: add `pos_terminals.fiscal_schema_version default 2`, model fillable/cast, and tests. Rename all pending lifecycle code to use `fiscal_status = pending_seal|fiscalized|voided`, or explicitly add a new `status` column and migrate every existing receipt query to it. Do not keep both names casually.

**Decision needed before implementation:** Decide whether receipt lifecycle state is `fiscal_status` or a new `status`; decide whether schema version lives on terminal only, receipt only, or both with clearly distinct meanings.

### B. Blocker — Phase A does not migrate the actual receipt finalization callers, so the checkpoint is false

**Issue:** Task 5 creates a `ReceiptFinalizationService` skeleton and tests it directly, but no Phase A task changes `ReceiptCreationService`, `ReceiptPaymentService`, `ReceiptController`, `ReceiptSyncService`, or `OrderToReceiptService` to use `pending_seal` plus finalize-after-payments. The Phase A checkpoint says an existing online cash sale should work, but the current endpoint still creates a fiscalized receipt before payment and the current payment endpoint updates a sealed receipt afterward. If Task 4’s immutability trigger is corrected to lock `fiscal_status`, `ReceiptPaymentService` updates to `change_due`/`tolerance_writeoff` can start failing.

**Plan evidence:**
- Task 5 files only create the finalization service and its test: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:605`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:609`.
- Task 5 implementation finalizes one passed-in receipt but does not call it from existing services: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:625`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:654`.
- Phase A checkpoint requires a working online cash sale: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:678`.
- The plan self-review says every phase produces working software: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:20`.

**Repo evidence:**
- Current receipt creation hashes `[]` for payments, writes `fiscal_hash`, saves, and advances terminal sequence immediately: `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:478`, `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:480`, `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:563`, `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:589`.
- Current payment processing appends `ReceiptPayment` rows later and updates receipt fields: `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:190`, `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:199`, `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:219`, `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:239`.
- `ReceiptController` still calls create and pay as two separate endpoints: `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:275`, `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:289`, `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:476`, `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:480`.
- `OrderToReceiptService` delegates to `ReceiptCreationService::createReceipt()`: `apps/api/app/Modules/POS/Application/Services/OrderToReceiptService.php:68`, `apps/api/app/Modules/POS/Application/Services/OrderToReceiptService.php:74`.

**Proposed change:** Split Task 5 into at least three tasks: (1) draft creation path with pending receipts and no chain advancement; (2) payment enqueue/finalize path with `ReceiptPaymentService` and controller changes; (3) compatibility adapters/tests for existing endpoints and `OrderToReceiptService`. The Phase A checkpoint should include an API-level test for `/pos/receipts` + `/pos/receipts/{id}/payments` proving only finalized receipts appear in the chain.

**Decision needed before implementation:** Decide whether the existing two-endpoint online flow remains public, or whether checkout becomes one atomic “create+pay+finalize” endpoint with the old endpoints preserved only as draft/payment substeps.

### C. Blocker — offline sync is in the blast radius, but the plan does not schedule its migration before cutover

**Issue:** The spec now correctly requires server-side v3 verification of offline hashes, but the plan only creates the TypeScript v3 encoder in Task 3 and punts local SQLite mirror/sync behavior to Task 46. No backend task modifies `ReceiptSyncService` or `SyncReceiptPayload` during Phase A/G, yet Task 32 can cut a terminal to v3 and Task 33 tests only a simple v2→v3 report chain. Pending offline v2 receipts synced after cutover will fail chain continuity or be finalized with the wrong version unless explicitly handled.

**Plan evidence:**
- Task 3 mirrors the v3 encoder in TS, but only for fixtures: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:383`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:389`.
- Task 32 cutover only tests open shifts and un-Z-reported receipts: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1190`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1194`.
- Task 33 has only one replay scenario: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1198`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1200`.
- Task 46, much later, is the only local-SQLite sync task: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1222`.
- Spec requires server sync to recompute and compare the offline hash: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:380`.

**Repo evidence:**
- `SyncReceiptPayload` already carries `offlineFiscalHash`: `apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php:51`, `apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php:53`.
- Validation requires the hash: `apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php:69`, `apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php:83`.
- Current sync checks client previous hash against terminal `last_hash`: `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:149`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:158`.
- Current sync recomputes the server payment hash as `[]`, saves the receipt, advances terminal, then inserts payments: `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:280`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:283`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:329`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:383`.
- Current Tauri offline receipt already hashes payments: `apps/pos/src/lib/offline/receiptService.ts:139`, `apps/pos/src/lib/offline/receiptService.ts:146`.

**Proposed change:** Add a Phase A or G task specifically for `ReceiptSyncService` v2/v3 handling: persist `fiscal_schema_version` in offline payloads, verify `offline_fiscal_hash`, reject or queue stale pre-cutover receipts deterministically, and add a cutover test matrix: empty shift, just-after-Z, and pending pre-cutover sync. Task 32 must refuse cutover while the terminal has unsynced local receipts, or the POS must auto-quiesce v3 until sync drains.

**Decision needed before implementation:** Decide the operational rule for pre-cutover offline receipts: force sync before cutover, accept v2 payloads after cutover in a compatibility window, or block checkout until the local queue is empty.

### D. Major — exchange idempotency cannot be represented by current receipt idempotency and no task adds an exchange table

**Issue:** Task 26 says idempotency on `exchange_request_id` returns the same `(returnReceiptId, saleReceiptId, voucherId?)` triple, but no task adds a persistent exchange table or a unique key spanning both receipts. Current `pos_receipts.idempotency_key` is unique per receipt, so the same exchange key cannot be stored on both fiscal documents. If a retry occurs after the return half is sealed but before the sale half finalizes, the plan has nowhere to record “exchange in progress” or recover the triple.

**Plan evidence:**
- Phase F claims both halves are finalized atomically: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:27`.
- Task 26 only says “new module-level service” and idempotency test: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1150`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1154`.
- Task 27 only asserts hash payloads include `exchange_group_id`: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1158`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1160`.
- Task 29 only tests the surplus-to-voucher path: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1170`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1172`.

**Repo evidence:**
- Existing receipt idempotency key is unique per `pos_receipts` row: `apps/api/database/migrations/2026_03_12_100000_add_idempotency_key_to_pos_receipts.php:19`, `apps/api/database/migrations/2026_03_12_100000_add_idempotency_key_to_pos_receipts.php:20`.
- Current sale and return paths each lock/update terminal sequence independently: `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:121`, `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:589`, `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:98`, `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:283`.

**Proposed change:** Add a `pos_exchange_requests` table in Phase F before `ExchangeService`: `exchange_request_id unique`, `exchange_group_id`, `status`, `return_receipt_id`, `sale_receipt_id`, `voucher_id`, timestamps, and failure metadata. The service should acquire/lock that row first, create both pending receipts, finalize both inside one DB transaction, and only then mark complete. Retrying an in-progress row should return a 409/retry-later or resume from a well-defined state.

**Decision needed before implementation:** Decide whether partial exchange state is allowed to exist; if not, enforce one transaction that creates both pending docs, finalizes both, and persists the idempotency triple atomically.

### E. Major — Phase E under-decomposes the highest-risk migration: `ReceiptReturnService`

**Issue:** Tasks 22–25 are four bullets for a service that currently builds negative lines, VAT, receipt number, hash, receipt row, receipt lines, VAT rows, terminal sequence, stock restoration, and events inline. Task 25 only says “sealed via finalization,” but does not split draft creation, voucher issuance-before-hash, payment/refund allocations, cash drawer operations, idempotency uniqueness, or finalization ordering.

**Plan evidence:**
- Task 22 only lists destination/audit/idempotency TDD: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1122`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1126`.
- Task 23 is one out-of-window line: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1130`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1132`.
- Task 24 is one manager-threshold line: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1136`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1138`.
- Task 25 has one test line for finalization: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1142`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1144`.

**Repo evidence:**
- Current return flow generates sequence/number, hashes empty payments, creates a return receipt, computes fiscal hash, saves, then creates lines/VAT: `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:206`, `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:262`.
- It advances terminal hash immediately: `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:280`, `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:283`.

**Proposed change:** Expand Phase E into separate tasks: return draft builder; refund destination resolver integration; voucher issuance prior to finalization; payment refund proration and cash drawer side effects; finalization with voucher ledger payload; DB-level idempotency on `refund_request_id`; chain/inventory rollback tests. Task 25 alone is not enough.

**Decision needed before implementation:** Decide whether `ReceiptReturnService` owns all side effects in one transaction or delegates to an orchestrator that calls draft/finalize/payment/voucher services in a fixed order.

### F. Major — backend compliance export population is promised but not scheduled

**Issue:** The plan says refund flow extends `Nf525DataProvider`, but there is no task that modifies it. Current `origin/dev` provider has docblocks for refund extension points, but `mapPayment()` does not pass `instrumentType`/`instrumentSerial`, and receipt mapping does not pass exchange/audit/voucher ledger fields. Final pre-merge only says to update the Compliance team, not to populate the provider.

**Plan evidence:**
- Plan says refund-flow extends the POS-side provider only: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:35`.
- Final checklist only says to update Compliance team after provider population: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1233`.
- No task heading includes `Nf525DataProvider`; the nearest compliance tasks are Z-report tasks: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1178`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1186`.

**Repo evidence:**
- Current provider explicitly says refund flow should populate the fields later: `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:52`, `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:61`.
- Current sale receipt DTO mapping stops at existing fields and does not pass new nullable refund fields: `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:488`, `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:511`.
- Current return receipt mapping only passes original receipt/reason: `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:542`, `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:567`.
- Current payment mapping passes only payment type and amount: `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:595`, `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:600`.

**Proposed change:** Add a backend task after Tasks 17/25/29 and before Phase G: populate `exchangeGroupId`, return audit fields, voucher ledger entries, and instrument fields in `Nf525DataProvider`; add contract/provider tests for each. Do not leave this as a final communication item.

**Decision needed before implementation:** Decide whether Compliance XML emission remains out of scope, but POS DTO population must be in scope for this PR.

### G. Major — frontend/offline dependency order is wrong for “no API hit during cashier interaction”

**Issue:** Task 39 scan dispatcher requires a verifier and receipt metadata before showing the confirmation sheet. Task 46, seven tasks later, creates the local SQLite voucher/receipt lookup mirror and states lookups never hit the API. Current POS receipt lookup API is network-only. If Task 39 lands before Task 46, it either cannot verify locally or it will violate §0.2 by calling the API during cashier interaction.

**Plan evidence:**
- Task 39 requires token verifier acceptance and a confirmation sheet: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1215`.
- Task 41 depends on refund flow/cart state after receipt loading: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1217`.
- Task 46 only later creates local SQLite voucher mirror and local-only receipt/voucher lookup: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1222`.
- The self-review asserts no API hit during cashier interaction: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1251`.
- Spec §0.2 requires every customer-facing lookup to use local SQLite first, every time: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:73`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:80`.

**Repo evidence:**
- Current POS receipt lookup is API-only: `apps/pos/src/api/receiptApi.ts:1`, `apps/pos/src/api/receiptApi.ts:11`.
- Current local-first checkout writes local receipts and triggers background sync, but this is sale checkout, not receipt/voucher lookup: `apps/pos/src/stores/paymentStore.ts:128`, `apps/pos/src/stores/paymentStore.ts:153`.

**Proposed change:** Move local SQLite receipt/voucher mirror schema and lookup repositories before Task 39. Add an explicit wiring task between Task 39 and Task 41: scan dispatcher emits a typed `ReceiptTokenDetected` event, the confirmation sheet loads receipt metadata from local SQLite, and only confirmed action hydrates the refund cart. Keep background API sync outside the cashier path.

**Decision needed before implementation:** Decide whether Task 39 can ship without receipt metadata, or move Task 46 before it and make local lookup a prerequisite.

### H. Major — PaymentRefundService idempotency lacks a DB constraint/task

**Issue:** Task 15 says idempotency on `(receipt_id, refund_request_id)`, but the only listed migration is a payment-type backfill. Current `payments` has no `original_payment_id` or `refund_request_id` in fillable, and app-layer checks are insufficient under concurrent refund requests.

**Plan evidence:**
- Task 15 migration is only a backfill for payment type: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1001`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1007`.
- Task 15 mentions idempotency but no unique index: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1013`.

**Repo evidence:**
- Current refund payments do not set `payment_type`, `original_payment_id`, or `refund_request_id`: `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:54`, `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:69`, `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:132`, `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:147`.
- Current `Payment` fillable has no `original_payment_id` or `refund_request_id`: `apps/api/app/Modules/Treasury/Domain/Payment.php:66`, `apps/api/app/Modules/Treasury/Domain/Payment.php:85`.
- Existing payment type default is `document_payment`, which Task 15 correctly plans to fix: `apps/api/database/migrations/2025_12_06_100002_add_payment_type_to_payments.php:13`, `apps/api/database/migrations/2025_12_06_100002_add_payment_type_to_payments.php:15`.

**Proposed change:** Add a Task 15 migration for `payments.original_payment_id`, `payments.refund_request_id`, audit fields, and a unique partial index on `(company_id, original_payment_id, refund_request_id)` for refund rows. Add a race test with two concurrent calls.

**Decision needed before implementation:** Decide the exact uniqueness scope: per original payment, per original receipt, or both via a separate refund allocation table.

### I. Minor — Task 13 points at the wrong settings class name

**Issue:** Task 13 lists `CompanyReservationSettings.php`, but `origin/dev` uses `App\Modules\Company\Domain\ValueObjects\ReservationSettings`. This is not a design blocker, but it will waste implementation time and cause an agent to create a parallel settings type by mistake.

**Plan evidence:**
- Task 13 files name a non-existent data class path: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:974`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:980`.

**Repo evidence:**
- Actual value object is `ReservationSettings`: `apps/api/app/Modules/Company/Domain/ValueObjects/ReservationSettings.php:13`, `apps/api/app/Modules/Company/Domain/ValueObjects/ReservationSettings.php:23`.
- `CompanyController` constructs that value object directly: `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:251`, `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:266`.

**Proposed change:** Rename Task 13 file target to `apps/api/app/Modules/Company/Domain/ValueObjects/ReservationSettings.php`; include a test that no new `pos_refund_settings` or parallel settings class exists.

### J. Minor — eco-tax Phase 1 columns are in the spec but unmapped in the plan

**Issue:** The updated spec narrows eco-tax to “columns + DTO fields only,” but the plan has no task for those migrations/model/DTO nullables. If accepted as out of scope, remove the self-review claim that every spec section is mapped; otherwise add a small schema task.

**Spec evidence:**
- Spec requires Phase 1 columns and DTO fields only: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:310`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:318`.
- Spec migration summary includes eco-tax fields: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:804`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:873`.

**Plan evidence:**
- Plan self-review claims every spec section is mapped: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1240`.
- Backend schema tasks cover pending seal, vouchers, policy settings, instrument rename, signing keys, and customer search, but no eco-tax task: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:501`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:682`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:974`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1030`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1078`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1099`.

**Proposed change:** Add a small Phase A/B migration task for nullable `eco_tax_amount`, `eco_tax_rate`, `eco_tax_category` on `pos_receipt_lines` and document lines, plus model fillable/casts/resources defaulting null. No writer integration required.

## Test coverage gaps

- **Voucher redemption:** Task 10 covers wrong terminal, expiry, voided, wrong partner, duplicate, residuals, and TND/EUR. It does not explicitly test mismatched currency, different tenant/company, or offline-stale local balance vs server-current. Add these to Task 10/46. Evidence: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:889`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:908`.
- **QR lookup:** Task 19 says “full matrix,” but only names token format, key rotation, cross-tenant rejection, and constant-time MAC. Add tampered MAC, retired `kid`, expired token if expiry exists, wrong company same tenant, and replay behavior. For receipt lookup, replay should be allowed for lookup but not authorize refund by itself. Evidence: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1089`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1096`.
- **Z-report cutover:** Task 33’s single replay is not enough. Add empty-shift cutover, immediately-after-Z cutover, pending pre-cutover offline sync, and mixed v2 receipt/v3 report refusal. Evidence: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1198`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1200`.
- **Cart sections UX:** Task 39 covers stray receipt scan cart unchanged, and Task 41 covers sections/draft, but there is no wiring test that Task 39’s confirmation sheet hydrates Task 41 only after confirmation. Evidence: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1215`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1217`.
- **Legacy hash tests:** Existing tests hard-code legacy payload assumptions and fixture fields. The plan should include an audit/update task so these fail intentionally, not silently. Evidence: `apps/pos/src/lib/fiscal/__tests__/hashService.test.ts:89`, `apps/pos/src/lib/fiscal/__tests__/hashService.test.ts:101`, `apps/api/tests/Unit/POS/ReceiptReturnServiceTest.php:315`, `apps/api/tests/Unit/POS/ReceiptReturnServiceTest.php:320`, `apps/api/tests/Feature/POS/ReceiptPdfGenerationTest.php:292`, `apps/api/tests/Feature/POS/ReceiptPdfGenerationTest.php:295`.

## Missing or weak spec mapping

- **§0.2 offline-first:** Task 46 states the rule, but it is too late and too broad; it must be split and moved before Task 39/41. Evidence: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:73`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1222`.
- **§3.2.1 restaurant-voucher rejection:** Task 17 covers backend rejection. Add frontend guard to Task 44 so no restaurant-ticket tender button or permissions accidentally enable it in Phase 1. Evidence: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:211`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:227`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1030`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1064`.
- **§4.6 RefundDestinationResolver:** mapped to Task 14, but Task 42 depends on its server output; add API contract/resource task if not implicit. Evidence: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:991`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1218`.
- **§4.7 voucher rate limiting:** mapped to Task 11; one task is acceptable if it stays a rate-limiter-specific task with all counters and Redis tests. Evidence: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:940`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:953`.
- **§5.5 residual/RoundingAdjustment:** partially mapped to Task 7 enum and Task 10 tests; add explicit GL rounding account migration/settings dependency. Evidence: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:684`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:702`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:777`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:905`.
- **§3.5 settings backfill:** mapped to Task 13 Step 4, but wrong class path needs correction. Evidence: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:982`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:985`.
- **§3.7 eco-tax columns:** unmapped; see Finding J.
- **§4.8 Nf525DataProvider population:** unmapped; see Finding F.

## Top 5 risk-ranked issues

1. **Blocker — Phase A schema/version mismatch.** Task 4 uses `status`, Task 5 reads `Terminal.fiscal_schema_version`, but `origin/dev` has `fiscal_status` and no terminal schema version. Proposed change: add/correct schema before finalization. Decision needed: lifecycle column and schema-version ownership. Evidence: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:551`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:636`, `apps/api/app/Modules/POS/Domain/Receipt.php:151`, `apps/api/app/Modules/POS/Domain/Terminal.php:89`.
2. **Blocker — finalization service is not wired into real checkout.** Task 5 tests the service directly but does not migrate create/pay/sync callers. Proposed change: split Phase A into draft creation, payment enqueue/finalize, and endpoint compatibility tasks. Decision needed: keep two endpoints or create atomic checkout endpoint. Evidence: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:605`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:678`, `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:275`, `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:476`.
3. **Blocker — offline sync cutover is underspecified.** Task 32/33 do not cover pending pre-cutover local receipts and Task 46 comes too late. Proposed change: add explicit `ReceiptSyncService` v2/v3 compatibility and cutover queue-drain tests. Decision needed: force sync before cutover vs compatibility window. Evidence: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1194`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1200`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:149`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:158`.
4. **Major — exchange idempotency needs a persistent exchange request table.** Current receipt idempotency is unique per row and cannot store one exchange key on two fiscal documents. Proposed change: add `pos_exchange_requests` with status and receipt ids before `ExchangeService`. Decision needed: partial exchange state policy. Evidence: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1154`, `apps/api/database/migrations/2026_03_12_100000_add_idempotency_key_to_pos_receipts.php:19`, `apps/api/database/migrations/2026_03_12_100000_add_idempotency_key_to_pos_receipts.php:20`.
5. **Major — local lookup/frontend task order violates offline-first.** Task 39 needs local verification/receipt metadata before Task 46 creates the local mirror. Proposed change: move Task 46 before scan/cart tasks and add a wiring task. Decision needed: whether receipt-token scans may ship without local metadata. Evidence: `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1215`, `docs/superpowers/plans/2026-04-28-pos-refund-flow.md:1222`, `apps/pos/src/api/receiptApi.ts:10`, `apps/pos/src/api/receiptApi.ts:11`.
