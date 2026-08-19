# M2 adversarial merge-gate review — round 7

**Milestone:** M2 — THE CUTOVER COMMIT (T14+T15+T16+T16b+T16d+T16e+T17)
**Reviewed:** `git diff 26b63f0ff..HEAD`. M2 production commits = `f848dab39` (cutover) + `28a2d854b` (round‑1 fixes) + `55e03c025` (round‑3 fixes). Rounds 2/4/5/6 were tool errors; this is the first real review of the round‑3 fixes.
**Amending authority applied:** `ORCHESTRATOR-RULING-2026-08-11-t11c-sequencing.md` — M2 exit hardened to "all ten T11c pairs GREEN post‑cutover".
**Lenses:** inventory‑costing — applies. fiscal‑pos — applies (T16d/T16e byte identity, sealed‑bytes surface). tenancy‑authz / treasury — not named for this milestone; not applied beyond standing checks.
**Environment:** local PostgreSQL 5432, isolated scratch DBs (created and dropped by me). Tree untouched.

## Round‑3 P1s — all four verified closed

| Round‑3 finding | Status | Evidence |
|---|---|---|
| 1 — red legacy suites under the boundary guard | **CLOSED (executed)** | `StandaloneInvoiceGuidedDeliveryTest` 12/12; `InvoiceDeliveryNoteConfirmationTest` + `ReceiptReturnFlowTest` 28/28, on PG |
| 2 — C‑5 silent zero COGS | **CLOSED** | flush at `InvoiceController.php:1028`, after the frame's last inventory lock (`:1003`) and the invoice post (`:1006`); endpoint test `InventoryGlCompositeRootTest.php:224-245` green |
| 3 — interactive basis vacuous in production | **CLOSED in mechanism** | `PosCoreReceiptProjection.php:1225-1237,:1253` now writes the snapshot; the assertion at `PosCoreReceiptProjectionRefundDispositionStockTest.php:154-158` is on the projected value, not the fixture. *Residual → finding 1 below.* |
| 4 — `originalPosSaleBasis()` null‑cost ⇒ non‑historical | **CLOSED** | `PosCoreReceiptProjection.php:2418-2426` carries the computed `gl_is_historical` |

## Register

**1 — P2 — CONFIRMED — `app/Modules/POS/Application/Services/ReceiptReturnService.php:1526-1533` (with `database/migrations/tenant/2026_03_22_130333_add_unit_cost_to_pos_receipt_lines_table.php:14`)**
R‑1 option (a) is "resolve refund `unit_cost` from the **original sale's `stock_movements.unit_cost`**". The projection path does exactly that (`PosCoreReceiptProjection.php:2430`, a `numeric(19,6)` column). The interactive (v3 server‑side) path does not: it reads `pos_receipt_lines.unit_cost`, which is **`numeric(15,4)`** — confirmed against the live schema — so the 6‑dp snapshot written at `:1253` is rounded on insert (proved: `insert 1.234568 into numeric(15,4)` → `1.2346`). Two live POS refund paths, two bases, for the same receipt.
*Failure scenario:* 100 units sold at WAC `1.234568` relieve inventory `123.4568` (`inventory_exit`, 6‑dp basis). A server‑side return of the same 100 units restores `123.4600` (`inventory_entry`, 4‑dp basis) → a permanent `+0.0032` inventory / `−0.0032` COGS residual per line, and the amount differs from what the projection path would post for the identical refund. This is the residual R‑1 exists to eliminate, reduced but not removed. No test can catch it: every fixture uses an exactly‑representable cost (`3.0000`, `2.5000`).
*Remedy:* read the sale movement (as `originalPosSaleBasis()` already does) rather than the receipt‑line copy — the column widening is not needed.

**2 — P2 — CONFIRMED — `InventoryGlPostingBoundaryGuard.php:25-29`; `InventoryServiceProvider.php:40-42`**
Guard 4 is now **per‑test‑class opt‑in** (`enableTestBoundaryGuard()`, called in exactly the 6 files this wave edited). R‑2 makes it mandatory precisely because it is *"the only guard that does not depend on the register being right"*; opt‑in restores register‑dependence — the next composite root added in any suite that does not opt in is undetected, which is the program's fifth occurrence of the same class.
The opt‑in is also unnecessary. The real discriminator round 3 identified is the test wrapper transaction, and it is observable at boundary time: under `RefreshDatabase` `DB::transactionLevel() >= 1` at `RequestHandled`; under `connectionsToTransact(): []` and in production it is `0`. Gating `assertEmpty()` on `transactionLevel() === 0` keeps every legacy suite quiet **and** restores register‑independence for every real‑commit suite, present and future, with no per‑class annotation.
*Failure scenario:* a future writer registers a new root frame, its own feature test does not call `enableTestBoundaryGuard()`, the frame never flushes; production issues stock with zero COGS and only `Log::critical` fires — the exact silent‑zero‑COGS failure the M2 section calls "worse than the deadlock this design removes".

**3 — P3 — CONFIRMED — absent from `apps/api/tests`**
T16's named test list requires *"no `POSSale`/`POSReturn` movement above the watermark has `unit_cost IS NULL`"* (D‑13 ADDITION 4). No test asserts it. The path it guards is live: `enqueuePosMovement` coerces a null cost to `'0'` (`PosCoreReceiptProjection.php:2475`) and `postMovement()` then returns null with only a `Log::warning` (`InventoryGlPostingService.php:168-174`) — silent zero COGS with no ratchet. Unreachable in today's code (both snapshot helpers always return a numeric string), which is exactly why the ratchet is cheap and the omission is invisible.

**4 — P3 — CONFIRMED — `docs/sessions/codex-dpa-wave3-3c-3d-report.md` (file ends at line 192, "M1 adversarial round 4")**
The DELIVERABLE requires the report to carry, per task, files touched / tests + commands + **actual output** / decisions / deviations / concerns. There is **no M2 section at all** — T14, T15, T16, T16b, T16d, T16e, T17 have no report entry. `M2-evidence.md` is a different artifact and does not discharge this. Also missing for M2: any `deptrac` result against the house rule's baseline 111 (M1's report records base drift `116` vs `99`, unreconciled).

**5 — P3 — CONFIRMED — `f848dab39`, `28a2d854b`, `55e03c025`**
"T14 + T15 + T16 + T16b + T16d + T16e + T17, in ONE commit … nothing between them may be a separately deployable state." M2 is three production commits, and the intermediate states are deployable *and wrong*: `f848dab39` ships a boundary guard that reds three feature suites; `28a2d854b` ships C‑5 issuing stock with zero COGS. Squash before merge, or D‑13's cutover atomicity is an intention rather than a property of the history.

**6 — P3 — PLAUSIBLE — `tests/Feature/POS/ReceiptReturnFlowTest.php:450-461`, `:1095-1107`**
Two pre‑existing tests now wrap their fixture mutation in `ALTER TABLE pos_receipts DISABLE TRIGGER enforce_receipt_immutability`. Round 3 measured this file at `1 failed, 19 passed` on `28a2d854b`, so both were green *without* the workaround; the fix commit added it anyway. Disabling a fiscal immutability trigger removes the only thing asserting the fixture is legal, and `ALTER TABLE … DISABLE TRIGGER` requires table ownership — a least‑privilege CI role errors. The `finally` restore is correct; the necessity is unproven. I could not falsify this without mutating the tree, hence PLAUSIBLE.

**7 — P3 — CONFIRMED — `tests/Feature/Inventory/InventoryGlLockOrderContentionTest.php:131-158`**
Carried from round 3 and still true: `runTerminalOrder($pair)` uses `$pair` only to salt the advisory key and probe‑table name — all ten "pairs" execute byte‑identical SQL. Under the 2026‑08‑11 ruling ("all ten T11c pairs GREEN post‑cutover is a hard gate item") the gate is satisfied only on the **compositional** reading: each of the real writer frames is separately trace‑proven terminal (`CogsRelocationCharacterisationTest.php:457-472` for DN/RN/POS, `PosReturnScrapWriteOffTest.php:534-553` for the scrap frame, `InventoryGlVoucherLockOrderTraceTest.php:164-183` for the voucher frame, `GoodsReceiptGlPostingOrderTest` for GR), so no AB‑BA edge can exist between any two. `M2-evidence.md:159-163` now states this honestly. Flagged so the orchestrator rules on the reading rather than inheriting "ten distinct pair proofs".

**8 — P3 — CONFIRMED — `PosCoreReceiptProjection.php:2405-2415`**
`originalPosSaleBasis()` still returns `is_historical => false` when the original sale movement is **missing entirely**, as distinct from the null‑cost branch round 3 fixed at `:2419-2426`. Same conflation of "unknown" with "post‑cutover", one branch narrower. Narrow population (POS sales have always written movements), but the fix is symmetric with the one already made.

**9 — P3 — CONFIRMED — `ReceiptReturnService.php:1546-1551`**
Round‑3 finding 8, unchanged. `Product::withTrashed()->where(tenant)->where(company)->findOrFail()` throws out of `runReturnTransaction` and refuses the customer's refund when the product row is hard‑deleted or company‑mismatched. Less‑taken now (post‑cutover receipts carry `unit_cost`) but every legacy receipt still routes through it. Still no covering test.

**10 — P3 — NOTE — `…_journal_entries_source_inventory_movement.php:26-35`**
Round‑3 finding 9, unchanged. The fail‑closed duplicate pre‑check covers the pre‑existing `batch_write_off` source types, and `origin/dev` auto‑deploys `tenants:migrate`. Still owed as a hard cutover precondition with its integer count per tenant (M0 declared the local R‑11 probe vacuous).

## Bypasses attempted that FAILED (the implementation survived)

- **Legacy wrapper‑transaction suites still red** → failed: on PG, `StandaloneInvoiceGuidedDeliveryTest` 12/12, `InvoiceDeliveryNoteConfirmationTest` + `ReceiptReturnFlowTest` 28/28.
- **C‑5 still posting zero COGS** → failed: flush at `InvoiceController.php:1028`; endpoint test asserts exactly one `inventory_exit` keyed to the DN movement.
- **C‑5's flush violating I‑1** → failed: the frame's last inventory lock is inside `confirm()` (`:1003`); both the invoice post's company advisory (`:1006`) and the flush (`:1028`) follow it.
- **Interactive‑return basis still green‑by‑fixture** → failed: production writes the column and the assertion is on the projected value.
- **`movementCostSnapshot` moved into `writeLines` breaking lock order** → failed: it is a plain `find()` with no `lockForUpdate`, and index alignment between `writeLines` and `decrementStockForLines` is over the same `$view->lineItems` keys.
- **A wave‑owned test regressing when its directory runs whole** → failed: a clean isolated PG run of `tests/Feature/Inventory` (817 tests) shows **zero** buffer/GL failures and no wave‑owned file among the 37 errors / 39 failures. Sampled reds are base PG‑vs‑SQLite drift (`value too long for character varying(20)` on `inventory_countings.counting_number`; `sqlite_column_types_match_the_precision_contract`; a non‑UUID id in `WeightedAverageCostServiceTest`). *(An earlier directory run of mine showed `relation "tenants" does not exist` — that was my own contamination from two concurrent runs against one scratch DB, not a defect. Discarded and re‑run isolated.)*
- **New float on money/quantity** → failed: no `(float)` / `floatval` / `number_format` added anywhere in the diff; the only added casts are `(int)` on a boolean SQL flag.
- **A new named queue without Horizon coverage** → failed: no `onQueue` added.
- **Non‑additive or clock‑dependent cutover migration** → failed: `hasColumn` guard, `useCurrent()` default, idempotent backfill, tenant‑scoped.
- **Round‑3 finding 5 (stale PHPStan baseline slot) as a wave defect** → failed: the `precision-ok` marker is present at `ReceiptReturnService.php:1339`, and the `bcmul` slot was **already** stale on the base (`26b63f0ff` has no unmarked literal‑scale `bcmul` in that file) — pre‑existing baseline rot, out of M2's scope.
- **Round‑3 finding 7 (`'TND'` / null `entryDate` defaults)** → closed: `restoreStock()` parameters are now required (`ReceiptReturnService.php:1296-1297`).
- **Not independently re‑run:** Pint, full‑tree PHPStan, deptrac. Findings 1, 3, 4, 5, 7, 8, 9, 10 rest on code/schema reading and cited artifacts; findings 1's truncation and the round‑3 P1 closures were verified by execution.

## Required to clear the gate

Findings **1** and **2**. Finding 1 is a live money residual that contradicts the ruled R‑1 option (a) on one of the two POS refund paths, and the brief says "do not merge T16 with the question open"; it cannot be closed by the existing tests, which all use exactly‑representable costs. Finding 2 is a MANDATORY rider (R‑2) implemented in a way that reintroduces the register‑dependence the rider exists to remove, with a strictly better discriminator available in one line. Findings 3–5 should be closed as part of the same round (a ratchet test, the missing M2 report section, and the squash) since none requires design work.

VERDICT: CHANGES-REQUIRED
