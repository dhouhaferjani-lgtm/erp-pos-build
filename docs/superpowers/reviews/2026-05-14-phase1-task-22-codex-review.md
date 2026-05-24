# Codex Review — Phase 1 Task 22 (`18df84988`)

**Verdict: BLOCK**

Task 22 is not safe to merge as-is. The §13 writer inventory is mostly stamped, and the new bridge keeps the Treasury operational work out of POS-core, but the production projector ordering is the reverse of the bridge's assumption. With the Task 23 success semantics, `treasury_receipt_bridge` can run before `pos_core_receipt`, return normally because the `pos_receipts` row is missing, and then be marked `applied` with no Treasury `Payment` rows or GL entries. The refund writers also create new post-Task-22 `payments` rows with `origin = NULL` when refunding legacy NULL-origin payments, conflicting with the `unknown_legacy` contract and the "every writer stamps origin" invariant.

| Severity | ID | File:Line | Description | Required Fix |
|---|---|---|---|---|
| BLOCKER | T22-B1 | `apps/api/bootstrap/providers.php:65`, `apps/api/bootstrap/providers.php:76`, `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php:25`, `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:165`, `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:1772` | The bridge assumes POS-core projects first, but providers tag Treasury before POS and the registry preserves registration order. The bridge returns normally when the POS receipt is missing; Task 23 marks normal return as `applied`. | Ensure POS-core is ordered before the Treasury bridge and test that order, or make a missing POS receipt a retryable projection failure so Task 23 cannot mark the bridge applied without effects. |
| BLOCKER | T22-B2 | `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:88`, `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:178`, `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:468`, `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:576` | Refund writers inherit NULL from legacy payments, producing new `payments` rows without a real origin. Spec §13 says pre-existing rows map to `unknown_legacy`; §17.6 says every writer stamps origin. | When the original payment origin is NULL, stamp refund rows as `PaymentOrigin::UnknownLegacy`, and add NULL-origin refund tests for full, partial, and receipt-proration refunds. |
| P1 | F1 | `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:63`, `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:1772` | The bridge documents race safety as depending on Task 23 `lockForUpdate()`, but the Task 23 plan does not actually specify `lockForUpdate()` on `fiscal_event_projections`. | Amend Task 23 before implementation: claim the projection row with `lockForUpdate()` before resolving/running the projector, and add a concurrency regression. |
| P3 | F2 | `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php:24`, `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php:37`, `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:82` | Stale comments still describe pre-Task-22 state or misname the legacy caller surface. | Update comments/docblocks to say both production projectors are now tagged, and that `ReceiptPaymentService::processReceiptPayments()` is reached from `storePayments`, not `/pos/receipts/sync`. |

---

## Findings

### T22-B1 — Treasury bridge can be marked applied before POS-core creates the receipt row

**File:line:** `apps/api/bootstrap/providers.php:65`, `apps/api/bootstrap/providers.php:76`, `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php:25-29`, `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:142-170`, `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:1772`

The bridge says POS-core is enqueued ahead of Treasury by tagged-set iteration order (`TreasuryReceiptBridge.php:142-149`). The actual provider order is the opposite: `TreasuryServiceProvider` is registered at `bootstrap/providers.php:65`; `POSServiceProvider` is registered later at `:76`. The registry explicitly preserves registration order (`FiscalEventProjectionRegistry.php:25-29`) and materializes the tagged iterable without sorting (`:88-116`).

That means production can create/enqueue `treasury_receipt_bridge` before `pos_core_receipt`. When the bridge runs first, it looks for `pos_receipts.fiscal_event_id = $event->id`; if not found, it logs and returns normally (`TreasuryReceiptBridge.php:165-170`). Task 23's plan says a normal `projector.apply($event)` return is success and flips the row to `applied` (`plan:1772`). No exception is thrown, so Horizon will not retry. Result: the fiscal event is stored, POS-core may later create the receipt, but Treasury `payments` and GL entries are permanently skipped.

The new tests catch presence of both tags but not their order: `TreasuryReceiptBridgeTest.php:188-209` and `FiscalEventProjectionRegistryTest.php:220-230` assert `contains`, not ordered `['pos_core_receipt', 'treasury_receipt_bridge']`. The bridge's own missing-receipt test (`TreasuryReceiptBridgeTest.php:397-412`) encodes the wrong success semantics by asserting "does not throw" instead of "retryable failure".

**Required fix:** either enforce and test projector ordering so POS-core always precedes Treasury, or make missing `pos_receipts` a retryable exception that Task 23 records as a failed attempt. The stronger fix is both: deterministic ordered projectors plus fail-retry on unmet downstream prerequisite.

### T22-B2 — Refund writers can create new NULL-origin Payment rows

**File:line:** `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:69-75`, `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:88`, `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:162-179`, `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:449-468`, `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:558-576`, `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:767-768`

`refundPayment()`, `partialRefund()`, and `refundReceiptPayments()` all set the refund row origin directly from `$payment->origin` / `$original->origin`. If the original is a pre-Task-22 legacy row with `origin = NULL`, the new refund row also has `origin = NULL`.

That conflicts with the Task 22 contract. Spec §13 defines `PaymentOrigin` values including `unknown_legacy` and says pre-existing rows map to `unknown_legacy` (`spec v7:558-576`). §17.6 then says every writer in the §13 table stamps `origin` (`:767-768`). A new refund row written after this task with NULL origin is not stamped with a real origin. The tests cover POS and web-admin inheritance (`PaymentOriginWriterInventoryTest.php:309-365`) plus POS proration (`:371-389`), but they do not test NULL-origin originals.

**Required fix:** normalize inheritance through a helper such as `originForRefund(Payment $payment): PaymentOrigin`, returning `$payment->origin ?? PaymentOrigin::UnknownLegacy`. Use it in full refunds, partial refunds, and receipt-proration refund rows. Add regression tests for all three NULL-origin cases.

### F1 — Bridge race-safety relies on a Task 23 lock that is not in the Task 23 plan

**File:line:** `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:63-71`, `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:173-183`, `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:1707`, `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:1772`

The bridge admits its idempotency is check-then-insert and that concurrent `apply()` calls can duplicate `Payment` rows unless Task 23 acquires `lockForUpdate()` on the projection row (`TreasuryReceiptBridge.php:63-71`). The implementer specifically asked to verify this premise. The Task 23 plan only says queue locking generally at `:1707`; the concrete implementation instruction at `:1772` says load row + event, set running, run projector, mark applied/failed. It does not say `lockForUpdate()`.

Because `payments.fiscal_event_id` is intentionally not UNIQUE for split tenders, the projection row lock is the only durable single-flight primitive. If Task 23 follows the current plan literally, two workers/manual replays can both pass the outer and inner existence probes and write duplicate `payments` + GL entries.

**Required fix:** amend Task 23's handle contract to claim the `fiscal_event_projections` row in a transaction with `lockForUpdate()` before resolving and running the projector; add a regression that two workers cannot both run `treasury_receipt_bridge`.

### F2 — Stale comments/docblocks now misdescribe production state

**File:line:** `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php:24-27`, `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php:37-40`, `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:82-89`

`FiscalServiceProvider` still says the tagged projector set is empty until Tasks 21/22, but both tasks are now landed. `FiscalEventProjectionRegistry` says Task 22 "will add" the Treasury bridge. `TreasuryReceiptBridge` says legacy `ReceiptPaymentService::processReceiptPayments()` is invoked from `/pos/receipts/sync`; the actual retained legacy caller is `ReceiptController::storePayments()` / `POST /pos/receipts/{id}/payments`, while `/pos/receipts/sync` goes through `ReceiptSyncService`.

This is not a behavior bug by itself, but the stale comments point reviewers at the wrong ordering and legacy-surface assumptions.

**Required fix:** update those comments in the Task 22 correction commit.

---

## Implementer-Flagged Concerns

**C1 — Does Task 23 plan actually use `lockForUpdate()` on `fiscal_event_projections`?** No. The plan mentions queue-owned "locking" generally at line 1707, but the concrete implementation instruction at line 1772 does not say `lockForUpdate()`. Raised as F1 because Task 22's bridge relies on that exact lock for race safety.

**C2 — Provider order and registry order:** Actual provider order is Treasury before Fiscal before POS (`bootstrap/providers.php:65`, `:74`, `:76`). The registry preserves insertion/registration order; it does not sort (`FiscalEventProjectionRegistry.php:25-29`, `:88-116`). This is part of T22-B1.

**C3 — Is `pos_receipts.cashier_id` NOT NULL or only FK `restrictOnDelete`?** It is NOT NULL at the column level because the migration uses `foreignUuid('cashier_id')` without `nullable()` and then `->constrained('users')->restrictOnDelete()` (`2026_01_08_190637_create_pos_receipts_table.php:52-55`). The bridge's "cashier non-null" premise is clean.

---

## Section 13 Writer Inventory Cross-Check

`Payment::create(` grep across `apps/api/app/Modules/Treasury`, `apps/api/app/Modules/POS`, and all `apps/api/app` found:

- `ReceiptPaymentService::processReceiptPayments()` stamps `origin => PaymentOrigin::Pos`; `fiscal_event_id` remains NULL on the retained legacy server path.
- `TreasuryReceiptBridge::projectPaymentLine()` stamps `origin => PaymentOrigin::Pos` and `fiscal_event_id => $event->id`.
- `PaymentController::store()` and `storeMultiple()` stamp `PaymentOrigin::WebAdmin`.
- `MultiPaymentService::createSplitPayment()`, `recordDeposit()`, and `recordPaymentOnAccount()` stamp `PaymentOrigin::WebAdmin`.
- `VendorRefundService::refundPrepayment()` stamps `PaymentOrigin::WebAdmin`.
- `PaymentRefundService::{refundPayment, partialRefund, refundReceiptPayments}` inherit origin, but fail the NULL-origin legacy case in T22-B2.
- `App\Modules\Billing\Domain\Payment` appears in `AdminBillingController.php` and is out of §13 scope per spec.

Repo-wide grep for `payments.origin` / `payments.fiscal_event_id` found no legacy verifier/audit reader that consumes those exact DB columns. Reads are currently model/test/bridge-level. No immediate alarm path like Task 21's `pos:verify-chains` issue was found.

`ReceiptPaymentService::processReceiptPayments()` is in the spec §14 disposition table at `spec v7:674` as a `(b)` path retired with backend new-sale routes. D8 coexistence is therefore not raised here.

---

## Other Verification Notes

- **Idempotency inner re-check:** the bridge does perform a fresh inner re-check inside `DB::transaction()` (`TreasuryReceiptBridge.php:173-183`). It is different from the outer probe in timing, but it is not a lock and does not close the concurrent insert race by itself.
- **GL atomicity:** bridge `Payment::create`, GL entry creation/posting, and `journal_entry_id` update run inside the same outer `DB::transaction()` (`TreasuryReceiptBridge.php:173-199`, `:283-326`). Clean for ordinary exceptions.
- **CI PG merge-gate filter:** `.github/workflows/ci.yml:391-392` includes both `TreasuryReceiptBridgeTest` and `PaymentOriginWriterInventoryTest` in the server PG filter. Syntax is one `--filter="A|B|..."`; clean.
- **No `app()` helper in bridge:** `rg -n "\bapp\s*\(" apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php` returned no matches.
- **Cross-task wiring test:** tests assert both `pos_core_receipt` and `treasury_receipt_bridge` are tagged (`TreasuryReceiptBridgeTest.php:205-208`, `FiscalEventProjectionRegistryTest.php:229-230`), but not their order. This misses T22-B1.
- **Split-payment coverage:** `TreasuryReceiptBridgeTest::test_split_payment_lines_each_produce_distinct_treasury_payment_rows()` covers two `Payment` rows for one event (`TreasuryReceiptBridgeTest.php:215-255`). Clean for the Task 22 split-tender matrix.

---

## Verification I Ran

```bash
git -C /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1 status --short && git -C /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1 branch --show-current && git -C /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1 rev-parse HEAD
sed -n '1,220p' docs/superpowers/reviews/2026-05-14-phase1-task-21-codex-review.md
sed -n '1630,1745p' docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md
rg -n "13\.6|D16|5\.0|7\.3|7\.4|7\.5|^## 13|^# 13|4\.2|4\.3|ReceiptPaymentService::processReceiptPayments|payments\.origin|payments\.fiscal_event_id" docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md
git show --stat --oneline 18df84988 && git show --name-only --format=fuller 18df84988
nl -ba docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md | sed -n '233,275p'
nl -ba docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md | sed -n '340,475p'; nl -ba docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md | sed -n '552,680p'; nl -ba docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md | sed -n '742,768p'
nl -ba docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md | sed -n '552,580p'
nl -ba docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md | sed -n '101,140p'; nl -ba docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md | sed -n '122,138p'
git show --unified=80 18df84988 -- apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php
nl -ba apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php | sed -n '1,430p'
nl -ba apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php | sed -n '1,260p'; nl -ba apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php | sed -n '1,340p'
nl -ba apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php | sed -n '1,380p'; nl -ba apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php | sed -n '1,240p'
nl -ba apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php | sed -n '260,620p'
nl -ba apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php | sed -n '380,620p'
nl -ba apps/api/bootstrap/providers.php; nl -ba apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php | sed -n '1,180p'; nl -ba apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php | sed -n '1,80p'; nl -ba apps/api/app/Modules/POS/Providers/POSServiceProvider.php | sed -n '1,80p'; nl -ba apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php | sed -n '1,80p'
rg -n "Payment::create\(" apps/api/app/Modules/Treasury apps/api/app/Modules/POS apps/api/app -g '*.php'
rg -n "payments\.origin|payments\.fiscal_event_id|origin\)|origin,|->origin|\['origin'\]|where\('origin'|where\(\"origin\"|fiscal_event_id|where\('fiscal_event_id'|where\(\"fiscal_event_id\"" apps/api/app apps/api/database apps/api/tests -g '*.php'
nl -ba apps/api/tests/Feature/Fiscal/TreasuryReceiptBridgeTest.php | sed -n '1,760p'
nl -ba apps/api/tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php | sed -n '1,660p'
nl -ba .github/workflows/ci.yml | sed -n '380,430p'
rg -n "cashier_id|foreignId\('cashier|uuid\('cashier|constrained\('users'|restrictOnDelete" apps/api/database/migrations apps/api/app/Modules/POS -g '*.php'
nl -ba apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php | sed -n '1,150p'; nl -ba apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php | sed -n '1,100p'; nl -ba apps/api/database/migrations/2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php | sed -n '1,110p'
nl -ba apps/api/tests/Feature/Fiscal/FiscalEventProjectionRegistryTest.php | sed -n '220,340p'; nl -ba apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php | sed -n '1080,1145p'; nl -ba apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php | sed -n '1080,1145p'
nl -ba docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md | sed -n '1705,1775p'
nl -ba apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php | sed -n '400,625p'
rg -n "\bapp\s*\(" apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php || true
rg -n "lockForUpdate\(\)|fiscal_event_projections|projection_status|ApplyFiscalEventProjectionJob" docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md apps/api/app/Modules/Fiscal apps/api/tests/Feature/Fiscal -g '*.php'
git show --check 18df84988
git diff --check 18df84988^ 18df84988
cd apps/api && php artisan test --filter=TreasuryReceiptBridgeTest
cd apps/api && php artisan test --filter=PaymentOriginWriterInventoryTest
cd apps/api && php artisan test --filter=FiscalEventProjectionRegistryTest
```

Test results:

- `php artisan test --filter=TreasuryReceiptBridgeTest`: 12 warnings, 37 assertions. No failures.
- `php artisan test --filter=PaymentOriginWriterInventoryTest`: 11 warnings, 26 assertions. No failures.
- `php artisan test --filter=FiscalEventProjectionRegistryTest`: 14 warnings, 20 assertions. No failures.

The warnings are the existing PHPUnit metadata/file-manifest warnings surfaced during bootstrap; they did not fail the filtered suites.
