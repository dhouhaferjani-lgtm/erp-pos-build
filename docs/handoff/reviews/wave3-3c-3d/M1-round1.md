# M1 adversarial merge-gate review — round 1

**Range:** `26b63f0ff..HEAD` (28 commits) · **Lenses:** inventory-costing, fiscal-pos · **Authority applied:** `ORCHESTRATOR-RULING-2026-08-11-t11c-sequencing.md`

Both lenses apply. Fiscal-pos is largely *clean* on the sealed-bytes question (see "Refuted" below); the failures are concentrated in the costing seam's evidence.

---

## Register

### P1-1 — T11c drives **no production code**; both the green and red arms are hardcoded synthetic lock orders — CONFIRMED
`apps/api/tests/Feature/Inventory/InventoryGlLockOrderContentionTest.php:126-184`, `:192-217`

`runTerminalOrder()` and `runAdvisoryFirstOrder()` create a scratch table `w3_t11c_<rand>` (`:200`), lock `id = 1 FOR UPDATE`, and take `pg_advisory_xact_lock(hashtextextended($key,0))` where `$key = 't11c:'.$pair.':'.bin2hex(random_bytes(8))` (`:198`) — a **random string, not the company id**. The `$pair` argument reaches nothing but the table name and the advisory key (`:192-198`); it never selects a lock order, and no writer (`DeliveryNoteService`, `ReturnNoteService`, `PosCoreReceiptProjection`, `GoodsReceiptService`, the buffer) is invoked. The green method hardcodes inventory→advisory; the red method hardcodes the reversed order.

Failure scenario, both directions:
- **The reds are broken-test reds, not cause reds.** The ruling requires the reviewer to verify each red "fails for the right reason — the missing T16d/T16e buffering/reorder — not because the test itself is broken." Pairs 7–10 fail because `runAdvisoryFirstOrder` *itself* issues `advisory → row` on connection B (`:164`, `:175`). Land T16d and T16e in M2 and these four tests still emit `40P01` — the M2 hard gate ("all ten pairs GREEN post-cutover") becomes unsatisfiable without editing the test, at which point the brief's stop rule (`3C STOPS`) would fire on a false signal.
- **The greens are vacuous.** Plan `plan-wave3.md:2726-2728` requires pairs 4 & 5 **RED before T5b, GREEN after**. Under this instrument they would have been green before T5b too — the instrument cannot reproduce the one red the wave already knows is real. Pair 6's second required assertion ("a two-DN confirm produces both DNs' `inventory_exit` rows in ONE flush", `plan-wave3.md:2731-2733`) is absent entirely.

The brief also names the required shape — "use the trace instrument … reuse the two-sided sensitivity-pair shape from closed F-3" (`CODEX-DISPATCH…:396-397`). The trace instrument exists and is production-driven (`GoodsReceiptGlPostingOrderTest::traceWriteOrderDuringReceipt`, `:855-871`); it was not reused. `M1-evidence.md:86-88` asserts the reds are "cause-specific"; the code does not support that claim.

### P1-2 — Three of four `postFor*` arms and three of four `MovementGlKind` dispatch arms are entirely untested — CONFIRMED
`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:72-77`; `InventoryGlPostingService.php:30-114`

`grep` over `tests/` for `postForEntry|postForCountCorrection|postForBatchWriteOff|MovementGlKind::Entry|::CountCorrection|::BatchWriteOff` returns **zero hits**. Every test builds `kind: MovementGlKind::Exit` (`InventoryGlPostingSeamTest.php:425`, `InventoryGlPostingBufferTest.php:47`). Plan T11 requires "one per ladder rung" and §2.1 declares "every branch is a pinned test".

Failure scenario: `postForCountCorrection` (`InventoryGlPostingService.php:47-50`) selects `InventoryGainIncome` vs `InventoryShrinkageExpense` from `directionForRow()`, and `debitInventory: $direction === 'in'` (`:79`). An inverted condition there books every shrinkage as a gain (Revenue credited instead of Expense debited) with a balanced, Posted, hash-sealed entry — nothing in the suite would notice. Likewise `postForBatchWriteOff`'s `InvalidArgumentException` guard (`:90-92`) and its "every V10 argument unchanged" delegation (D-21/D-23′, `:103-113`) are unproven, and `postForBatchWriteOff` never checks `isHistorical` or `affectsCOGS` — a divergence from the exit/entry arms that no test pins either way.

### P2-3 — Four explicitly named T11 buffer pins are missing; the named test file does not exist — CONFIRMED
`apps/api/tests/Unit/Inventory/InventoryGlPostingBufferTest.php` (whole file, 62 lines)

Plan `:2660-2673` names these; none are present:
- `enqueue` performs **zero** queries (assert with a query-log count of 0) — no `enableQueryLog` anywhere in `tests/{Unit,Feature}/Inventory` for the seam.
- "two sequential DN confirms in one request post exactly their own entries and no cross-contamination" — the **`scoped()`-binding regression**. Absent; nothing exercises the binding at `InventoryServiceProvider.php:31`.
- "a writer transaction that enqueues and then ROLLS BACK **posts nothing**, and a subsequent writer transaction in the same request posts **only its own** entries". `InventoryGlPostingSeamTest.php:126-140` asserts only `isEmpty()`; neither the "posts nothing" half nor the subsequent-transaction half is asserted.
- "`ConcurrencyFault`-classified exception re-thrown, a plain `RuntimeException` not" (§2.1's closing rule). `grep ConcurrencyFault` over the new seam + its tests: **no hits**.

Also `mark()`/`rollbackTo()` is pinned in a plain unit test with no transaction at all (`:17-33`), not "taken from a frame above the nested transaction" (D-9.5′), and the plan's named file `tests/Unit/Inventory/InventoryGlPostingServiceTest.php` does not exist.

### P2-4 — T15a's persisted-basis half has zero coverage — CONFIRMED
`apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:702-713`

`grep -rn return_cost_basis tests/` returns nothing. The only writer of `payload['return_cost_basis']` is untested end-to-end: no test asserts the records land, that `source`/`movement_ids`/`unit_cost` carry the resolver's values, or that `recordReturn` now receives `$basis->unitCost` instead of the deleted `getOriginalCost()`. T15a's task text names this as a deliverable (`plan-wave3.md:2834-2841`), and M3's D-g detector reads `return_cost_basis.source = 'current_cost'` off exactly this shape. `ReturnCostBasisResolverTest.php` tests the resolver in isolation only (2 tests).

Failure scenario: the accumulate-then-`update` loop rewrites the whole `payload` per line; a regression that drops earlier records (e.g. a stale `$returnNote` instance) leaves a 4-line RN with 1 basis record, and D-g silently under-reports unattributable returns.

### P2-5 — The blocking unique index is extended over `batch_write_off*`, whose shipped writer has **no** idempotency guard, and no duplicate probe was run — CONFIRMED
`apps/api/database/migrations/tenant/2026_08_11_000100_unique_journal_entries_source_inventory_movement.php:17-45`; `GeneralLedgerService::createInventoryWriteOffEntry:4769-4880`

`InventoryGlSourceTypes::ALL` includes `batch_write_off` and `batch_write_off_reversal`, and the migration `throw`s a bare `RuntimeException` if any duplicate `(source_type, source_id)` exists. `createInventoryWriteOffEntry` has **no** existence guard of any kind (contrast the new `createInventoryMovementEntry:4660-4667` and the GR-IR idiom at `:1977`), so duplicates are producible today by its two live callers (`BatchWriteOffService.php:106`, `ReturnScrapWriteOffService.php:191`) on any retry/replay. Memory also records that `journal_entries(source_type, source_id)` is not globally unique.

Failure scenario: push to `origin/dev` auto-deploys `tenants:migrate`; a tenant with one pre-existing duplicate write-off pair aborts this migration and every migration queued behind it for that tenant. `M0-evidence.md` contains no duplicate count for these two source types (R-11's probe is the unrelated non-physical-deliveries probe). The plan's "greenfield makes this a no-op" is an assumption, not evidence.

### P2-6 — M1's evidence contract "red-first + revert-replay **per task**" is not met — CONFIRMED
`docs/sessions/codex-dpa-wave3-3c-3d-report.md:47-94`; git log `26b63f0ff..HEAD`

M0 demonstrated the contract with real commits (`Revert "…"` / `Reapply "…"` × 5 rounds). M1 has four commits and **no** revert/replay pair. The report records exactly one red state, for V-10 (`:82-84`: "the red state was a missing `error.reason` and English fallback"). T11, T11e, T12, T13 and T15a have no red-before record and no revert-replay. The brief's evidence table requires "red-first + revert-replay per task" for M1 (`CODEX-DISPATCH…:540`).

### P3-7 — Migration omits the plan-mandated pgsql driver guard
`…2026_08_11_000100_unique…php:17`. Plan §3 M1 specifies `if (DB::connection()->getDriverName() !== 'pgsql') { return; }   // house pattern` and eight sibling tenant migrations carry it. Not fatal — SQLite supports partial indexes and `InventoryGlPostingSeamTest.php:82-89` handles the sqlite branch — but it is an unrecorded deviation from a literal spec sketch.

### P3-8 — `hasInventoryMovementAccounts` hardcodes `false` for `CountCorrection`
`GeneralLedgerService.php:4623-4632`. Currently dead (`MovementReason::CountCorrection::affectsCOGS() === false`, so rung 2 returns first), but it is a public predicate that will answer "unmapped" even after M4/T20 seeds the shrinkage/gain purposes. A future caller wiring the counting lane through this predicate silently posts nothing.

### P3-9 — Outer existence guard returns a possibly-Draft entry without the `postSynchronously` repair
`GeneralLedgerService.php:4660-4667` vs `:4747-4752`. The **inner** guard's `$existing` falls through to the sync-post block and gets posted; the **outer** guard returns it unposted. The GR-IR idiom the plan cites (`:1977-1979`) returns `null` from both. Narrow window (containment savepoints normally roll the Draft away), but the asymmetry is exactly the "Draft forever" shape the brief warns about at `:422-423`.

### P3-10 — `TransactionRolledBack` listener is not connection-scoped
`InventoryServiceProvider.php:91-101`. It fires on any connection reaching level 0 and unconditionally `reset()`s the buffer. Under database-per-tenant a `central`-connection rollback while a tenant root transaction holds buffered contexts would discard them — silent zero COGS, the exact failure D-28 exists to prevent. Only a `Log::warning`, not the `Log::critical` the leak path uses.

### P3-11 — `InventoryPaymentRepositoryLockDisjointness` is an untested whole-file substring grep
`apps/api/app/PHPStan/Rules/InventoryPaymentRepositoryLockDisjointness.php:22-40`. It `file_get_contents` the whole file and matches `payment_repositories` + `lockForUpdate` + `/stock_levels|inventory_countings|ProductCostLock/` anywhere, including comments; it never inspects the class node. There is no rule test (the repo has the pattern: `tests/PHPStan/ForbidFixedScaleQuantityLiteralRuleTest.php`), so nothing proves it can fire. This is the brief's own M1 anti-pattern ("an alarm test that cannot detect the alarm's absence") applied to I-2's structural half.

### P3-12 — `ReturnCostBasisResolver` does not net units already drawn by prior returns (plan-conformant; flagging, not a deviation)
`ReturnCostBasisResolver.php:69-89` treats `absoluteDeltaForRow()` as fully available on every call. Two successive RNs against the same invoice both drain from the earliest exit: DN1 = 1 @ 10, DN2 = 2 @ 20; RN-A returns 1 → basis 10.000000 (correct); RN-B returns 1 → basis 10.000000 again, when the only remaining units cost 20. D-24 step 2 as written has the same gap, so this is the plan's, not the implementation's — but it puts an unexplained residual in cost of sales, which is the exact reason D-24 rejected option (b).

---

## Bypasses I tried that FAILED (findings I could not sustain)

1. **RN payload writes corrupting the fiscal seal** — refuted. `receiveStockBack` runs at `ReturnNoteService.php:590`, before the seal; hash inputs are `document_number/posted_at/total/currency` only (`:605-610`), and `enforce_document_immutability()` takes its `OLD.fiscal_status != 'SEALED'` early return. Fiscal-pos lens clean here.
2. **Float touching money via the `decimal:4` cast on the synthetic `StockMovement`** — refuted. Laravel 12's `asDecimal` uses `Brick\Math\BigDecimal::of((string)$value)` (`vendor/…/HasAttributes.php:1512-1519`), no float. Rule 19 holds across the seam: `bcmul(..., $scale + 6)` → `CurrencyScale::bcround(..., $scale)` matches D-4 verbatim, and `currencyCode` is explicit and non-nullable on the DTO.
3. **`$direction !== $ctx->reason->getMovementType()` always true (enum vs string)** — refuted. `getMovementType(): string` returns `'in'|'out'` (`MovementReason.php:49-61`).
4. **`public const array ALL` fatal on PHP 8.2** — refuted. `composer.json` pins `config.platform.php = 8.3.30` and the idiom is already used in ~8 shipped files.
5. **`createInventoryMovementEntry` idempotency not company-scoped** — refuted as exploitable; `source_id` is a movement UUID and the partial index is global, so cross-company collision is not reachable.
6. **A second, production-driven T11c file hiding elsewhere** — searched `tests/Feature/Inventory` and `tests/Architecture`; none. `GoodsReceiptGlPostingOrderTest` is 3A/T5b's and covers the GR lane only. Its one new test (`test_goods_received_fires_after_the_purchase_order_reaches_received`, `:356-379`) **is** genuinely production-driven and does satisfy the brief's "GR pairs account for `GoodsReceived` firing with PO status `Received`" clause — that clause alone is met.
7. **The `InvoiceController` response reshape breaking the contract** — refuted. `{error:{code,message,reason}}` is a strict superset of `validationErrorResponse`'s `{error:{code,message}}` (`HandlesDocuments.php:298-306`); en+fr both present and asserted (`StandaloneInvoiceGuidedDeliveryTest.php:154-192`).

## What is solid

V-10 (a) + (b) is correctly and narrowly implemented and well tested through the real HTTP route, including the French remedy and the "no draft DN, no movement left behind" negative. The refusal ladder's rung order matches §2.1 exactly for the Exit arm, the one-rounding arithmetic pin (`3 × 1.6666666 → 5.000`) is exact, the leak alarm uses the mandated named mechanism (`connectionsToTransact(): []`) and **does** fail if the registration is removed, `flushIfOutermost()` is the single name everywhere, and the buffer is `scoped()`. T16c's "negative branch inapplicable" audit is recorded with the `applyScrapPair` evidence the brief demanded. The aborting-subtransaction cannot-verify is proven directly against PostgreSQL.

The blocking problem is that the milestone's single most important named test — the one the plan calls "the acceptance test for D-9 and the *only* evidence that closes F-1/C-1" — proves a property of PostgreSQL rather than a property of this codebase, and three of the four new posting arms ship unexercised.

VERDICT: CHANGES-REQUIRED
