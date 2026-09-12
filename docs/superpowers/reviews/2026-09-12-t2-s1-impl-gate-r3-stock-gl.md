# T-2 S1 receipt spine — implementation gate r3 — stock-gl-interaction-reviewer

Lane: `lane/t2-receipt-spine`
Base: `de3007fe94d97b4d16e5103a08fd084d6db11cb3` (`git merge-base dev lane/t2-receipt-spine`)
Tip reviewed: `208449350` (fix round 2; round-2 delta `d7123fa30..208449350`)
Dev at review time: `f90ece298` (carries G0 guardrails + RBAC wave 0a, both merged AFTER this lane's base)
Date: 2026-09-12
Reviewer: Claude Opus `stock-gl-interaction-reviewer`, read-only. Every `path:line` is at `208449350` under `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/`. Thirteen PostgreSQL classes run by me on a PRIVATE database `autoerp_test_s`, serially, one PHPUnit process at a time, never `--parallel`, never the full suite. No file was created, edited, committed, merged or pushed in the lane worktree.

## Verdict

VERDICT: ACCEPT
BLOCKER=0 MAJOR=0 MINOR=3

All twelve of my gate-r2 items (G-1..G-8 closed at r2, G-9..G-12(r2) issued at r2) hold closed or are resolved-as-ruled at this tip. **Fix round 2 changes nothing on the stock↔GL seam**: `StockTransferReceiptService.php` and `StockTransferMovementSupport.php` are **byte-identical** to `d7123fa30` (`git diff --stat d7123fa30..208449350 -- <file>` empty for both), and the whole round-2 `apps/api/app` + `apps/api/database` delta (5 files) contains no match for `journal_entries|JournalEntry::|postEntry|->update(|::table(|glBuffer|StockMovement|stock_levels|inventory_batch_stock|(float)|floatval|number_format|parseFloat`. The freight zero-journal pins of plan §15 are untouched: `StockTransferCloseTest.php:122` (`return_to_source` → `journalCount() === 0`) and `StockTransferCompleteConcurrencyPostgresTest.php:138-140` (freight complete → journal count unchanged) both re-run green on PG by me.

My three minors are one new forward hazard (G-13), one test-infrastructure hazard introduced by the round-2 isolation change (G-14), and one informational record (G-15). None blocks merge.

---

## Per-item status, G-1..G-12(r2)

| Item | r2 severity | Status at `208449350` | Evidence re-verified this round |
|---|---|---|---|
| **G-1** — GL side pinned only by a row count | MAJOR (r1) | **CLOSED, holds** | `StockTransferReceiveDamageTest.php:26-39` and `StockTransferReceiveLotsTest.php:83-95`: `JournalEntry…->with('lines')->sole()`, scale from `CurrencyScaleResolverInterface::getScale($this->company->currency)`, amount by `bcmul`, purpose map via `Account::…->system_purpose`, `assertSame` on both sides, `JournalEntryStatus::Posted`, `isChained()`, `Event::assertDispatched(JournalEntryPosted::class)`. Both classes green on PG by me (6/23 and 3/32). Files unchanged in round 2 except the single G-10 line |
| **G-2** — account-mappability preflight | MINOR→ADOPTED | **CLOSED, holds** | `StockTransferReceiptService.php:140-142` still sits inside the same `foreach ($postings …)` loop as the valuation-mode preflight (`:133-145`), which closes at `:145` **before** `post()` at `:147`; nothing is written at that point (`lockTransfer` `:113` and `costLock->acquire` `:126` take locks only; receipt numbering is not consumed until `:334`). Refusal pinned on BOTH forms now — receipt form `StockTransferReceiveDamageTest.php:82-99`, close form `StockTransferCloseTest.php:61-76` (this is the G-9 fix). Checklist disclosure `PROMOTION-CHECKLIST-2026-09-09-t2t3.md:12` |
| **G-3** — nest guard | MINOR→ADOPTED | **CLOSED, byte-identical** | `StockTransferReceiptService.php:97-99`, still the first three statements of `transact()`, ahead of the key check `:100`, the uuid guard `:104`, the identity read `:107` and `DB::transaction` `:110`; evaluated once because it sits outside the `attempts: 3` closure. Pin `TransferReceiptGlBoundaryTest.php:15-36` (seven-table snapshot, exact message `:30`) green on PG (2/16) |
| **G-4** — destination attribution of in-transit loss | MINOR (disclose) | **CLOSED** | `PROMOTION-CHECKLIST-2026-09-09-t2t3.md:13` |
| **G-5** — orphan docblock | MINOR | **CLOSED** | Gone; `StockTransferService.php` round-2 diff touches only `initiate`/`complete`/`cancel`/`moveSourceToInTransit` signatures and the G-11 comment |
| **G-6** — handback asserting reviewer verdicts | MINOR | **CLOSED, holds** | `HANDBACK-T2-receipt-spine-2026-09-09.md` fix-round-2 section ends "Reviewer verdicts remain in the orchestrator registers. No reviewer verdict is assigned by this handback." |
| **G-7** — GL/subledger freight divergence | MINOR (disclose) | **CLOSED** | `PROMOTION-CHECKLIST-2026-09-09-t2t3.md:14`, naming both directions and the owning ticket |
| **G-8** — SQLite installs no receipt CHECK constraint | MINOR (record) | **CLOSED, holds** | `2026_09_09_100100_create_stock_transfer_receipt_tables.php:83` still returns early for non-pgsql before `addForeignKeys()`/`addChecks()`. Consequence re-stated below as G-13 |
| **G-9(r2)** — close/write-off form had no `GL_ACCOUNTS_UNMAPPED` test | MINOR | **FIXED** | `StockTransferCloseTest.php:61-76`: unmaps `SystemAccountPurpose::InventoryShrinkageExpense`, drives `POST /close disposition=write_off`, asserts `error.code = GL_ACCOUNTS_UNMAPPED` **and** the identical seven-table snapshot (`stock_levels, stock_movements, stock_transfers, stock_transfer_lines, stock_transfer_receipts, journal_entries, journal_lines`) byte-unchanged. Green on PG in the 10/44 run |
| **G-10(r2)** — batch invariant never asserted on both sides in one test | MINOR | **FIXED** | `StockTransferReceiveDamageTest.php:60-61`: the same mixed-lot receipt now asserts `BatchStock…quantity === '3.0000'` **and** `$this->destinationQuantity() === '3.0000'` (`stock_levels`, helper at `StockTransferReceiveTest.php:155-157`). This is the per-location `SUM(inventory_batch_stock) == stock_levels.quantity` invariant, asserted in a class the PG lane runs (`ci.yml:1146` filter list) and re-run green by me on PG |
| **G-11(r2)** — `freight_uncapitalized = transfer_cost` precondition unstated/unpinned | MINOR | **FIXED, both halves** | Comment: `StockTransferService.php:365` — "canBeCancelled() excludes partial/terminal receipts, so no freight has been allocated." Test: `StockTransferCloseTest.php:53-59` — receive 5 of 12, `POST /cancel` → `assertUnprocessable()`, status stays `partially_received`, destination stock stays `5.0000` (i.e. the precondition now breaks a test, not the money, if it is ever relaxed) |
| **G-12(r2)** — both G-1 assertions run under `Event::fake([JournalEntryPosted::class])` | MINOR (optional) | **DEFERRED AS RULED** | Handback fix-round-2 "Isolation and scope consequences": "G-12 is optional and deferred: event dispatch assertions do not establish downstream audit-chain consumption. No additional event consumer or fake was introduced." I accept the deferral — the generic GL lanes cover `DomainEventSubscriber::handleJournalEntryPosted` |

---

## Round-3 requirement checks

### 1. Fix round 2 introduces no stock, valuation or GL path

`git diff --name-status d7123fa30..208449350 -- apps/api/app apps/api/database` = **five files**: `StockTransferLineData.php`, `TransferReconciliationSummaryData.php`, `StockTransferService.php`, `TransferReconciliationService.php`, `StockTransferController.php`. Classified from my seam:

| File | Round-2 change | Seam verdict |
|---|---|---|
| `StockTransferService.php:310,322,393` | `complete`/`cancel`/`moveSourceToInTransit` take `StockTransfer $identity` instead of a bare id; `:365` gains the G-11 comment | **No new write.** The scope is not re-derived from the id: `lockTransfer()` re-resolves under the row lock with BOTH predicates (`StockTransferMovementSupport.php:44-52` — `where('tenant_id', $identity->tenant_id)->where('company_id', $identity->company_id)->lockForUpdate()->findOrFail($identity->id)`), so no stale model is written. Pinned by `TransferReceiptAuthorityGateTest.php:54-70` (a forged tenant/company on the identity → `ModelNotFoundException` with a byte-identical denial snapshot, for both `complete` and `cancel`) |
| `StockTransferController.php:240,280` | passes the already-scoped `$existing` model | Presentation-only; improves rule 20 (no `CompanyContext` read on the service path) |
| `TransferReconciliationSummaryData.php:15-17` + `TransferReconciliationService.php:43,52-55,93` | I-12: six cross-unit `total_*` quantity strings deleted; only `lines`, `lines_with_discrepancy`, `freight_uncapitalized` remain | **Read model only.** No consumer left dangling: `grep -rn "total_sent\|total_received\|total_damaged\|total_written_off\|total_returned\|total_remaining" apps/web/src packages/shared/types apps/api/app apps/api/tests` returns only unrelated PurchaseOrder/GoodsReceipt hits |
| `StockTransferLineData.php:68` | I-13: `QuantityScale::decimalPlacesForUnit($product?->unitOfMeasure?->decimal_places)` | Presentation precision metadata; no value path |

**I-11 option-(a) package, verified against the authority rather than the brief.** Plan rev 10 §0AB line 59 rules the confirm copy "becomes **line-count** bearing per frontend F-4 (supersedes the 'remaining total' wording), rendered only for `partially_received`, string comparison against `'0.0000'`, en/fr". The implementation matches exactly: `StockTransferDetailPage.tsx:277-282` renders `detail.confirmComplete.description` with `shortLines`/`totalLines` only when `transfer.status === 'partially_received'`, filtering on `line.quantity_remaining !== '0.0000'` — **exact string comparison, no `parseFloat`/`Number`/`toFixed`**. That comparison is safe because `quantity_remaining` is always a scale-4 bcmath string: `StockTransferLineData.php:74` → `StockTransferLine::remainingQuantity()` (`StockTransferLine.php:133-141`, four unconditional `bcsub(…, QuantityScale::SCALE)`), so zero is always literally `'0.0000'`. en/fr copy at `apps/web/src/locales/{en,fr}/stock-transfers.json` `detail.confirmComplete.description` states "Confirming books the entire outstanding quantity as received at the destination; use Close to write off or return a short shipment", with the old wording preserved as `inTransitDescription`. Checklist disclosure at `PROMOTION-CHECKLIST-2026-09-09-t2t3.md:16`. Backend data-meaning test at `StockTransferCloseTest.php:37-51` (receive 5 of 12 → `POST /complete` → destination `12.0000`, `completed`, `freight_uncapitalized '0.0000'`, freight identity `Σ allocated + uncapitalized == transfer_cost`) — green on PG by me. **The brief's phrase "quantity-bearing confirm copy" is superseded by plan rev 10 §0AB; I gate on the plan.**

**T-9 revert and the nest-guard test-side recovery introduce no seam change**: `git diff --name-status d7123fa30..208449350 -- 'apps/api/app/Modules/*/Domain/Events/*'` is **empty**, and across the whole slice the Events directory is 4 files, all `A`, 0 deletions — rule 8 intact.

**Freight zero-journal pins (plan §15).** Untouched and re-verified green: `StockTransferCloseTest.php:122` (`close return_to_source` → `assertSame(0, $this->journalCount())`, plus source restock `+12.0000`, batch stock `20.0000`, exactly one `transfer_in` movement at the source, and a replay that does not move stock again) and `StockTransferCompleteConcurrencyPostgresTest.php:136-141` (freight capitalization at complete → `journal_entries` count unchanged, `DB::transactionLevel() === 0`).

### 2. Receipt-spine invariants and transaction boundaries

**Atomicity — stock and GL commit together or not at all.** One transaction opens at `StockTransferReceiptService.php:110` (`DB::transaction(…, attempts: 3)`), guarded to be the ROOT by `:97-99`. Inside it, in order: header row lock `:113`; product cost locks `:126`; pre-write refusals `:133-145`; stock movements written through the single writer `StockAdjustmentService` (`:450` receive-land, `:461` issue-scrap, and `StockTransferMovementSupport::restockAtSource` `:191-248` for `return_to_source`, itself only `stockAdjustmentService->receive`); counters `:371-384`; receipt/line/lot rows `:391-398`; terminal status `:414`; freight `:415-417`; stored events `:419`; **GL flush `:420`, inside the same transaction at level 1**. `InventoryGlPostingBuffer::flushIfOutermost()` is called with `$contained` defaulting to `false` (`InventoryGlPostingBuffer.php:56`), so a GL failure propagates and aborts the whole receipt — no stock without value, no value without stock. Announcement events are deferred to `DB::afterCommit` `:421-429`, so a rollback announces nothing. Failure path unwinds the GL buffer symmetrically: `mark()` `:111` / `rollbackTo(min(marker, mark()))` `:150`.

**GL posts through the announcing path, never a direct row.** `grep -nE "^\+.*(JournalEntry::|journal_entries|postEntryAndDispatch)"` over the whole slice `apps/api/app` + `apps/api/database` diff is **empty**. The only GL emission is one `glBuffer->enqueue(new MovementGlContext(…))` at `:468-475`, and the buffer dispatches to `InventoryGlPostingService::postForExit` / `postForBatchWriteOff` (`InventoryGlPostingBuffer.php:73-84`) → `GeneralLedgerService::createInventoryMovementEntry` / `createInventoryWriteOffEntry` (`InventoryGlPostingService.php:73,117`). The `Posted` + `isChained()` + `JournalEntryPosted` assertions in G-1 are the empirical proof that the advisory-lock/closed-period/fiscal-chain path was actually used, not bypassed.

**Same-key replay produces no duplicate movement and no duplicate journal.** Key resolution `:114` (`findReceipt` scoped `tenant_id + company_id + idempotency_key`, `:164-167`) → `replay()` `:169-176`, which refuses a reused key across a different transfer/kind/payload hash (`Failure::IdempotencyKeyReused`). The race loser is caught at `:154-161` on `UniqueConstraintViolationException` against `stock_transfer_receipts_idempotency_unique (tenant_id, company_id, idempotency_key)` and replays the winner's receipt. Empirically green on PG this round: `StockTransferReceiveConcurrencyPostgresTest` (3/43), `StockTransferIdempotencyCollisionPostgresTest` (2/13), `StockTransferCompleteConcurrencyPostgresTest` semantics re-pinned inside `StockTransferEdgeCasesTest`/close suite, and the replay legs in `StockTransferCloseTest.php:86-87` (second `close` → `meta.replayed: true`, `journalCount()` still `1`) and `:123-124` (`return_to_source` replay leaves source stock unchanged).

**Partial receipts cannot double-capitalise freight.** `capitalizeFreight` is called only at `:415-417`, gated on `$transfer->status->isTerminal()`, and a transfer reaches a terminal status exactly once (later calls replay `:115-117` or are refused by `canReceive()` `:118-120`). `recordCostAdjustment` *adds* to WAC rather than setting it, so the gate is load-bearing — and it holds. Freight weights are the LANDED-GOOD quantities only (`:496-500` sums `$line->quantity_received`), so damaged/written-off units carry no freight and the residual is retained (`:502-503`, truncate-toward-zero at `MONEY_SCALE = 4`). Pinned at `StockTransferCloseTest.php:78-88` (receive 5 of 10 with freight 140 → `freight_uncapitalized '70.0000'`, one journal) and `:90-111` (two-line worked example, `49.9999 + 40.0001 + 30.0000 == 120.0000`).

**The close path books nothing the receipt path already booked.** `TransferReceiptGlBoundaryTest.php:38-51` is the direct proof: receive 3 good + 2 damaged → `journalCount() === 1` and the buffer is empty; then `close(write_off)` on the remaining 7 → `journalCount() === 2` and the buffer is empty again; `Log::shouldNotHaveReceived('critical')` (no leak alarm) and `DB::transactionLevel() === 0`. Two journals for two disjoint destroyed quantities (2 and 7); the 3 good units are never expensed. Re-run green by me on PG.

**WAC denominator (dimension 6).** The slice's only `WeightedAverageCostService` change is `companyOwnedQuantity` `:121-128`: the in-transit term moves from `SUM(stock_transfer_lines.quantity)` over `InTransit` to `COALESCE(SUM(StockTransferLine::REMAINDER_SQL), 0)` over `StockTransfer::CARRYING_STATUSES`. `REMAINDER_SQL` (`StockTransferLine.php:143`) is `quantity − received − damaged − written_off − returned` and `CARRYING_STATUSES` (`StockTransfer.php:212`) is `[in_transit, partially_received]`. This is the **correct** basis and it closes a phantom-denominator hazard that `partially_received` would otherwise have created: at a partial receipt the landed units are already in `stock_levels`, so counting the full sent quantity would double-count them. The divisor basis is shared with every other in-transit reader and ratcheted: `TransferInTransitReadersUseRemainderTest.php:17,30,39` requires `CARRYING_STATUSES` + `REMAINDER_SQL` at an exact site count and forbids a residual `TransferStatus::InTransit`, across `LocationStockQueryService`, `StockMatrixQueryService` and `WeightedAverageCostService`. Pinned by data meaning at `StockTransferEdgeCasesTest::test_wac_counts_transit_exactly_once_before_and_after_terminal_action` (`:279-283`, cost `6.000000` → `7.000000` with journal count unchanged) and `StockTransferCompleteConcurrencyPostgresTest.php:136-141` ("ten owned units not fourteen"). I ran that WAC case on **both** drivers (PG in the 27/115 class run; SQLite `--filter 'wac_counts_transit'` 2/14) to prove the new `selectRaw`+`is_numeric`+`bcadd` path does not hand a float to bcmath on either driver.

**Document-per-action (dimension 1) and append-only (dimension 9).** The scrap movement carries a real justifying document: `referenceType: StockMovementReferenceType::StockTransferReceipt`, `referenceId: $receipt->id` (`:464`), with the enum case added additively at `StockMovementReferenceType.php:114`. The receipt row is inserted in the same transaction (`:391`) and no FK on `stock_movements` forces ordering. The DPA baseline **shrinks by exactly one row** — `StockTransferService::markMovementAsTransfer::stock_movements::update#1` is DELETED from `tests/Architecture/baselines/document-per-action-baseline.json` and **no row is added** — i.e. the lane removes the repo's transfer-side post-insert movement UPDATE and its new writers are not baselined. `DocumentPerActionBaselineRatchetTest` with `DPA_BASELINE_PROTECTED_BLOB=1381983d…c597` green by me (2/109). `StockTransferEdgeCasesTest::test_transfer_movements_are_created_with_final_linkage_and_never_updated` (`:84-94`) green on PG.

### 3. Rule 19 / rule 20

- Full-slice `apps/api/app` + `apps/api/database` diff grep for `(float)|floatval|number_format|round(` returns two benign hits only: `(int) $lot['batch_id']` (an integer PK, `ReceiveStockTransferRequest.php:78`) and `QuantityScale::round()` (bcmath-based, `QuantityScale.php:80`). No `parseFloat|Number(|toFixed(` anywhere in the slice's `apps/web` diff.
- No bare `getScale()`: every scale resolution on the seam passes the entity currency — `StockTransferReceiptService.php:492` and `StockTransferMovementSupport.php:69,100` all use `getScaleSafe($transfer->company->currency, 3)`; the GL context carries `currencyCode: $transfer->company->currency` (`:470`).
- Money vs quantity scales are named and separated: `StockTransferMovementSupport::MONEY_SCALE = 4` (`:29`, docblock naming `allocated_transfer_cost` and `freight_uncapitalized`), `ALLOCATION_SCALE = 6` (`:26`), `QuantityScale::SCALE` used only on quantity paths.
- FormRequest ceilings present: `ReceiveStockTransferRequest.php:25` `['required','numeric','regex:/^\d+(\.\d{1,4})?$/']` for every quantity and lot quantity.
- Rule 20: the slice adds **no** queued job, listener or named queue (`grep -nE "^\+.*(ShouldQueue|onQueue|Event::listen)"` over the slice `apps/api/app` diff is empty), so no worker context is introduced; the service no longer reads `CompanyContext` at all (the controller resolves it and passes `tenantId`/`companyId` — `StockTransferController.php:308,334` → `StockTransferReceiptService.php:95,107`). No projection `apply()` test exists in the lane, so the "clear `CompanyContext` before `apply()`" rule has no subject here.

### 4. Nest-guard design note (r2) honoured

All three halves of the r2 ruling are in the tree:
1. **Guard kept, byte-identical** — `StockTransferReceiptService.php:97-99`; the whole file is unchanged in round 2.
2. **Runtime recovered on the test side** — `tests/Support/TruncatesRootTransactionDatabase.php:12-72`: migrate once per class (`:24-27`), then row cleanup with no wrapping transaction, preserving absolute `DB::transactionLevel() === 0`. Adopted by `StockTransferEdgeCasesTest.php:42`, `InventoryTransferServiceTest.php:40`, `StockTransferVariantTest.php:37`, all of which had `connectionsToTransact(): []` + per-test `RefreshDatabaseState::$migrated = false` deleted. My PG measurement: `StockTransferEdgeCasesTest` **27 tests / 115 assertions / 2 documented ticket skips in 29.885s**, versus my r2 SQLite measurement of 01:22.886 and the implementer's round-1 02:32.698. No assertion was weakened — the round-2 diff of that class is signature-only (`complete($transfer, …)` / `cancel($cancelled, …)`).
3. **`--parallel` forbidden in writing** — `.github/workflows/ci.yml:1144`: "Receipt fixtures migrate/truncate a shared database: never --parallel this step." (the only round-2 ci.yml change, +1 line).

### 5. Second of everything (convention 09)

`TransferReceiptSecondOfEverythingTest.php:30-76` is a genuine data-meaning test and covers all three legs for the receipt entity:
- **Second company** `:41-49` (new `Company`, membership, `X-Company-Id`, its own write-off accounts) with its own **two new locations** `:44-45` and its own product/stock.
- **Per-company numbering restarts**: `:52` asserts company B's first receipt number equals company A's first receipt number (the `(tenant_id, company_id, receipt_number)` unique at `2026_09_09_100100…:100` makes this meaningful).
- **Re-run/idempotency on every receipt writer**: `receive` replay `:53`, `close write_off` replay `:55-57`, `close return_to_source` replay `:60-62`, `complete` replay `:64-66`, each with the resulting stock quantity asserted after the replay.
- **Cross-company isolation asserted as data, not status codes alone**: after five 404s `:67-71`, company A's destination stock `:72`, its `stock_transfer_receipts` rows byte-for-byte `:73`, its source stock `:74`, and `journal_entries WHERE company_id = A` **= 0** `:75`. `journalCount()` itself is company-scoped (`StockTransferReceiveTest.php:161-164`).

Green on PG by me (2 tests / 30 assertions). Migration re-run idempotency additionally pinned by `TransferReceiptSchemaRerunPostgresTest` (2/11, green).

---

## Round-3 findings

### BLOCKER
None. No stock mutation without its justifying document; no value-bearing transition without its GL consequence, and none booked twice; no second writer to `stock_movements`/`stock_levels`/`inventory_batch_stock` (every write goes through `StockAdjustmentService`); no GL row created outside the buffer→`InventoryGlPostingService`→`GeneralLedgerService` announcing path; no post-insert `stock_movements` UPDATE (the lane deletes the only one the repo had); no float on the seam; no event class modified.

### MAJOR
None.

### MINOR

**G-13(r3) — the "a receipt line must carry a positive quantity" invariant is enforced only at the HTTP boundary and by a PostgreSQL-only CHECK; the service accepts a zero line.** `ReceiveStockTransferRequest.php:54-56` rejects a `0/0` line at the edge, and `2026_09_09_100100_create_stock_transfer_receipt_tables.php:193-196` installs `CHECK (quantity_received + quantity_damaged + quantity_written_off + quantity_returned > 0)` — but only on PostgreSQL, because `:83` returns before `addChecks()` on any other driver (G-8). `StockTransferReceiptService::validateReceive` (`:202-264`) has no equivalent guard: it refuses `NothingToReceive` only when the LINE's remainder is already zero (`:216-218`) and `OverReceipt` above the remainder (`:219-221`). No production path can reach it today — the two internal payload builders both skip exhausted lines (`completionPayload` `:183-185`, `validateClose` `:283-285`) — so this is a forward hazard, not a live defect. **Why it matters:** the next internal caller (S2 blind receiving, S4 receiver UI) that submits a zero line gets an unhandled `CheckViolation` 500 on PostgreSQL and, on SQLite, silently persists a receipt-line document with no movement and no counter change — a document-per-action violation the suite cannot see. **Other side verified:** there is no GL consequence either way (a zero line produces `land == 0` and `scrap == 0`, so `writeMovements` `:438-479` writes no movement and enqueues nothing), so this is a document-integrity hazard, not a GL one. **Fix:** one `bccomp(received + damaged + written_off + returned, '0', QuantityScale::SCALE) <= 0 → TransferReceiptFailureException(Failure::NothingToReceive, $details)` inside `validateReceive`'s loop, plus one case in `StockTransferReceiveValidationTest`.

**G-14(r3) — the new truncation trait suspends PostgreSQL triggers for cleanup, and nothing asserts the session role is restored before the test body runs.** `tests/Support/TruncatesRootTransactionDatabase.php:55-63` reads the current `session_replication_role`, sets it to `'replica'`, runs a single `DO $cleanup$ … DELETE FROM … END $cleanup$;`, and restores in `finally` with `set_config(…, false)` (session scope). The environment guard at `:50-52` (testing + `_test` in the database name) is correct, the `finally` is correct, and I confirmed the disarm window does **not** touch my seam: `information_schema.triggers` on the migrated database shows immutability triggers on `fiscal_events` and `pos_receipts` only — `stock_movements`, `journal_entries`, `journal_lines` and `stock_transfer_receipts` carry none. So no stock/GL append-only defence is weakened today. **The residual hazard is adoption:** the trait is a general-purpose `Tests\Support` helper; the first class that adopts it while writing `pos_receipts` or `fiscal_events` inherits a window in which a failed restore (connection recycled during the `DO` block, a non-superuser role, a `set_config` that itself errors) would leave the session in `replica` and let a test UPDATE a sealed receipt while the suite stays green. There is no read-back assertion anywhere. (Tenancy T-18(r3) already records the superuser dependency; this is the other half — the unasserted restore.) **Fix:** one line after the `finally` — `assert`/`throw` unless `SHOW session_replication_role` reads back the captured value — and one sentence in the trait docblock restricting it to classes that write no immutable ledger table. Test-only.

**G-15(r3) — informational, not a lane defect: `stock_movements` and `journal_entries` have no database-level immutability trigger, so the lane's append-only guarantee rests entirely on application-layer guards.** Verified directly against the migrated schema (`information_schema.triggers` → only `fiscal_events` and `pos_receipts`). The lane's own behaviour is exemplary here — it DELETES the repo's only post-insert transfer-movement UPDATE from the DPA baseline and asserts the property (`StockTransferEdgeCasesTest.php:84-94`) — so this is recorded, not charged: the guarantee is enforced by `DocumentPerActionBaselineRatchetTest` (green, 2/109, protected blob honoured) and by that one class, and both are lane-scoped rather than schema-scoped. No action required in S1; worth naming for whoever owns the movement/ledger seam after this merges.

---

## Verification run by the reviewer

PostgreSQL native **15.15 (Homebrew)** at `127.0.0.1:5432` (recorded honestly: this is NOT PG-16 execution evidence, matching the implementer's T-13 correction). Private database **`autoerp_test_s`** (created for this review), set for BOTH `DB_DATABASE` and `DB_CENTRAL_DATABASE`. One PHPUnit process at a time, serial, by path, never `--parallel`, never the full suite.

```sh
DB_DATABASE=autoerp_test_s DB_CENTRAL_DATABASE=autoerp_test_s DB_USERNAME=houssamr \
DB_HOST=127.0.0.1 DB_PORT=5432 DB_PASSWORD='' \
apps/api/vendor/bin/phpunit -c apps/api/phpunit-pgsql.xml <FILE>
```

| Class (PostgreSQL) | Tail | Time |
|---|---|---|
| `tests/Feature/Inventory/TransferReceiptGlBoundaryTest.php` | `OK (2 tests, 16 assertions)` | 00:09.377 |
| `tests/Feature/Inventory/StockTransferReceiveDamageTest.php` | `OK (6 tests, 23 assertions)` | 00:31.091 |
| `tests/Feature/Inventory/StockTransferCloseTest.php` | `OK (10 tests, 44 assertions)` | 00:50.414 |
| `tests/Feature/Inventory/StockTransferReceiveLotsTest.php` | `OK (3 tests, 32 assertions)` | 00:16.887 |
| `tests/Feature/Inventory/TransferReceiptSecondOfEverythingTest.php` | `OK (2 tests, 30 assertions)` | 00:10.982 |
| `tests/Feature/Inventory/StockTransferEdgeCasesTest.php` | `OK, but some tests were skipped!` / `Tests: 27, Assertions: 115, Skipped: 2.` | 00:29.885 |
| `tests/Feature/Inventory/TransferReceiptAuthorityGateTest.php` | `OK (9 tests, 23 assertions)` | 00:44.821 |
| `tests/Feature/Inventory/TransferReceiptEventStreamTest.php` | `OK (3 tests, 38 assertions)` | 00:44.844 |
| `tests/Feature/Replenishment/TransferCloseReplenishmentSettlementTest.php` | `OK (1 test, 8 assertions)` | 00:06.117 |
| `tests/Feature/Inventory/StockTransferReceiveConcurrencyPostgresTest.php` | `OK (3 tests, 43 assertions)` | 00:20.050 |
| `tests/Feature/Migrations/TransferReceiptSchemaRerunPostgresTest.php` | `OK (2 tests, 11 assertions)` | 00:04.190 |
| `tests/Feature/Inventory/StockTransferReceiveTest.php` | `OK (4 tests, 24 assertions)` | 00:19.266 |
| `tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php` | `OK (2 tests, 13 assertions)` | 00:02.073 |

Every count matches the handback's fix-round-2 table exactly where it lists one (`StockTransferEdgeCasesTest` 27/115/2, `TransferReceiptAuthorityGateTest` 9/23, `StockTransferCloseTest` 10/44, `StockTransferReceiveDamageTest` 6/23, `StockTransferReceiveConcurrencyPostgresTest` 3/43). **No class the handback calls green is red.**

SQLite legs (`DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_CENTRAL_DATABASE=:memory:`, `-c phpunit.xml`):

| Command | Tail | Time |
|---|---|---|
| `phpunit tests/Feature/Inventory/StockTransferEdgeCasesTest.php --filter 'wac_counts_transit'` | `OK (2 tests, 14 assertions)` | 00:04.750 |
| `DPA_BASELINE_PROTECTED_BLOB=1381983d463e6c546535be907d4aa1c7ca94c597 phpunit tests/Architecture/DocumentPerActionBaselineRatchetTest.php` | `OK (2 tests, 109 assertions)` | 00:17.688 |

Static checks run by me (no process beyond grep/git):

| Check | Result |
|---|---|
| `git diff --stat d7123fa30..208449350 -- …/StockTransferReceiptService.php` and `…/StockTransferMovementSupport.php` | **empty** — both byte-identical |
| Round-2 `apps/api/app`+`database` diff grepped for `journal_entries\|JournalEntry::\|postEntry\|->update(\|::table(\|glBuffer\|StockMovement\|stock_levels\|inventory_batch_stock\|(float)\|floatval\|number_format\|parseFloat` | **no match** |
| Slice diff grepped for `ShouldQueue\|onQueue\|Event::listen` and for `JournalEntry::\|journal_entries\|postEntryAndDispatch` | **no match** in either |
| `git diff --name-status … -- 'apps/api/app/Modules/*/Domain/Events/*'` round 2 / whole slice | empty / 4 files all `A`, 0 deletions |
| `information_schema.triggers` on the migrated `autoerp_test_s` | immutability triggers on `fiscal_events`, `pos_receipts` only (basis for G-14/G-15) |

Not run by me and not claimed: Vitest, ESLint, PHPStan, preflight, the manifest checker, and any full-suite or CI run.

---

## Merge-order note

The lane's two declared conflicts with `dev` are the only ones: `git merge-tree $(git merge-base dev lane/t2-receipt-spine) dev lane/t2-receipt-spine` reports exactly **two** `changed in both` entries, matching `.github/workflows/ci.yml` and `apps/api/tests/feature-lane-manifest.json` (the integration owner's job, not mine). In particular `apps/api/app/Http/Middleware/RequireAnyPermission.php` — which this lane edits (message text only) — has **no commit on `dev` since the base**, so there is no hidden middleware conflict.

**Nothing merged into `dev` since the base changes a conclusion on this seam, and I checked the two that could have:**

1. **RBAC wave 0a route-coverage ratchet** (`a4bc7ed6d`, in `dev` at `f90ece298`). `RoutePermissionCoverageRatchetTest` runs GROWTH live and fails on any uncovered route absent from its baseline, with `UNCOVERED_WRITE_CEILING = 152` / `UNCOVERED_READ_CEILING = 146`. This lane adds three routes and widens two (`apps/api/app/Modules/Inventory/Presentation/routes.php`: `POST /stock-transfers/{transfer}/receive` → `can:inventory.transfers.complete`; `POST …/close` → `['can:inventory.transfers.reconcile','can:inventory.transfers.close']`; `GET …/reconciliation` → `can:inventory.transfers.reconcile`; index/show → `require.any.permission:…view,…complete,…reconcile`). **`RouteCoverageClassifier::GATING_ALIASES` (`dev:apps/api/tests/Architecture/Support/RouteCoverageClassifier.php:31-37`) contains both `can` and `require.any.permission`**, and the dev baseline has no `stock-transfers` entry, so all five routes classify as GATED and neither uncovered counter moves. **No ceiling raise is owed and the ratchet cannot go red on this merge.**
2. **G0 pilot guardrails** (`97adecf7e`, `4734590a3`) hardened `apps/api/tools/feature-lane-manifest-check.php` and `scripts/preflight.sh` to fail closed. This lane raises manifest ceilings in `apps/api/tests/feature-lane-manifest.json` — one of the two declared conflict files. After the integration owner resolves it, `php tools/feature-lane-manifest-check.php` must be re-run against the MERGED file under the hardened checker; the lane's own exit-0 evidence predates it. Not a seam finding; a merge-integration step.
3. The queued supplier-balance currency fix (`477c877a3`) is in a different module and this lane introduces no queued job, so rule 19/20 conclusions are unaffected.

**This seam does not depend on any other lane landing first.**

---

**What to fix before merge:** nothing on the stock↔GL seam — take G-13 (one positivity guard inside `validateReceive` + one validation case) and G-14 (one read-back assertion in `TruncatesRootTransactionDatabase` + a docblock restriction) as cheap follow-ups, and record G-15 for the movement/ledger seam owner.

Files referenced (absolute):
- `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/app/Modules/Inventory/Application/Services/StockTransferReceiptService.php`
- `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/app/Modules/Inventory/Application/Services/StockTransferMovementSupport.php`
- `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`
- `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php`
- `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php`
- `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/app/Modules/Inventory/Presentation/Requests/ReceiveStockTransferRequest.php`
- `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/database/migrations/tenant/2026_09_09_100100_create_stock_transfer_receipt_tables.php`
- `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/tests/Support/TruncatesRootTransactionDatabase.php`
- `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/tests/Feature/Inventory/TransferReceiptGlBoundaryTest.php`
- `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/tests/Feature/Inventory/StockTransferCloseTest.php`
- `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/tests/Feature/Inventory/StockTransferReceiveDamageTest.php`
- `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/tests/Feature/Inventory/TransferReceiptSecondOfEverythingTest.php`
- `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md`
- `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/docs/handoff/HANDBACK-T2-receipt-spine-2026-09-09.md`

VERDICT: ACCEPT
BLOCKER=0 MAJOR=0 MINOR=3

Orchestrator: session_01Kogdxvb7MMkERPtF6T22yn (reviewer = Claude Opus `stock-gl-interaction-reviewer` agent).
