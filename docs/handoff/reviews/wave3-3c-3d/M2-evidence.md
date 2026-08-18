# M2 evidence — atomic inventory-movement COGS cutover

M2 implements T14 + T15 + T16 + T16b + T16d + T16e + T17 in the single
cutover commit. The amended all-ten-green gate is governed by
`docs/handoff/reviews/wave3-3c-3d/ORCHESTRATOR-RULING-2026-08-11-t11c-sequencing.md`.

## Pre-commit citation inventory

The inventory was re-run immediately before the cutover and committed as
`90b8f57e7`:

```text
artifact: docs/handoff/reviews/wave3-3c-3d/M2-citation-inventory.csv
rows: 266
mapped: 266
unresolved: 0
```

## Red-first and atomic revert-replay

The pre-cutover red evidence established the missing behavior before production
edits:

- the invoice-keyed `PostCOGSOnInvoice` listener remained registered;
- DN/RN/POS movement-keyed entries were absent;
- T11c pairs 7/8 observed the scrap advisory before the remaining inventory
  loop, and pairs 9/10 observed voucher GL before stock projection;
- a POS refund after live cost moved from 3 to 12 restored and wrote off at 12,
  rather than the original sale movement cost of 3.

During red/green development, `git revert --no-commit f848dab39` (the original
pre-squash cutover identifier) was applied while retaining the new covering
tests. The selected replay produced
`10 failed (17 assertions)` for cause-specific expectations:

```text
legacy listener: PostCOGSOnInvoice unexpectedly present
pair 1 DN: production writer did not reach movement-keyed GL
pair 3 POS: production writer did not reach movement-keyed GL
pre-cutover replay: movement is_historical was false
refund basis: expected 3.000000, got 12.000000
contained POS failures: expected the original inventory_exit, got zero
pair 9: first_company_advisory=13, last_inventory=51
pair 10: first_company_advisory=13, last_inventory=51
```

`git revert --abort` restored the cutover tree. This proves the green result is
caused by the atomic M2 implementation rather than by a pre-existing pass.

## Review round 1 fixes

- Restored the plan's server-created-time rule: queued/offline receipts always
  create post-cutover movements and post COGS regardless of device event time.
- Classified refund reversals from the original sale movement with PostgreSQL's
  literal `stock_movements.created_at < companies.inventory_gl_cutover_at`
  comparison, preventing a one-sided entry for pre-cutover sales.
- Threaded the interactive receipt line's immutable `unit_cost` through both
  restock and scrap paths; live product cost is only a legacy fallback.
- Added the test-only D-28 boundary guard on every handled request and completed
  or failed queue job. It preserves an after-commit leak until the boundary,
  resets it, and throws; removal of either registration makes its behavioral
  test fail.
- Expanded the terminal-order PostgreSQL matrix to all ten named T11c pairs:
  every target-order pair returns `00000,00000`, while pairs 7–10 retain the
  reversed-order controls that each reproduce a `40P01`.

Round-one fix verification (isolated PostgreSQL, by path):

```text
CogsRelocationCharacterisationTest: 15 passed (54 assertions)
PosCoreReceiptProjectionRefundDispositionStockTest: 13 passed (62 assertions)
PosReturnScrapWriteOff + seam + composite + voucher: 38 passed (186 assertions)
InventoryGlLockOrderContentionTest: 15 passed (174 assertions)
InventoryGlPostingSeam boundary mutation: request and job guards both red on leak
InventoryGlPostingSeamTest: 25 passed (100 assertions)
PHPStan targeted changed production files: [OK] No errors
```

The original round-one fix commit was also revert-replayed with
`git revert --no-commit 28a2d854b` before the D-13 squash, while retaining its covering tests. The
selected replay produced five expected failures: delayed device events were
again classified historical, pre-cutover refunds were again classified live,
interactive returns used the mutated live product cost (12 rather than 2.5),
and neither the request nor job boundary detected a leaked buffer. After
capturing those failures, `git revert --abort` restored the clean fix tree.

## Review round 3 fixes

- Scoped D-28 Guard 4 to tests that opt out of `RefreshDatabase`'s wrapper
  transaction and therefore exercise real production root commits. Those
  suites enable the guard explicitly; wrapper-transaction suites discard their
  necessarily deferred test state at the boundary instead of false-failing.
- Pulled C-5's root-tail flush forward from M3 because M2 otherwise shipped the
  guided standalone-invoice path with a stock exit and no COGS. The real HTTP
  composite now asserts the movement-keyed entry exists.
- Persisted each projected sale line's immutable cost snapshot in
  `pos_receipt_lines.unit_cost` and passed the same captured value into the sale
  movement, so interactive returns do not fall through to live WAC.
- Preserved PostgreSQL's original-sale historical classification even when a
  legacy pre-cutover movement has a null cost snapshot; its refund remains
  historical and cannot create a one-sided inventory entry.
- Corrected two pre-existing POS regression fixtures that intentionally mutate
  sealed receipts: PostgreSQL's immutability trigger is disabled and restored
  only around the controlled injection.

The `.env` file points at Docker PostgreSQL on port 5433, which the dispatch
marks broken. Exploratory 5433 results were discarded. All gate results below
explicitly used local PostgreSQL on port 5432:

```text
M2 real-root regression group: 68 passed (308 assertions)
Document/POS wrapper regression group: 40 passed (214 assertions)
InventoryGlLockOrderContentionTest: 15 passed (174 assertions)
InventoryGlPostingBufferTest: 2 passed (4 assertions)
Targeted changed-production PHPStan: [OK] No errors
Pint + git diff --check: pass
```

The original round-three fix commit was revert-replayed with
`git revert --no-commit 55e03c025` before the D-13 squash, while retaining its covering tests. On the
required PostgreSQL port 5432, the replay produced the four expected behavioral
failures: projected receipt cost was null, the legacy null-cost refund was not
historical, C-5 leaked its buffered movement at the request boundary, and a
wrapper-transaction guided request again false-failed at that boundary. After
capturing the failures, `git revert --abort` restored the clean fix tree.

## PostgreSQL by-path acceptance

All database evidence ran against the isolated PostgreSQL database
`autoerp_wave3_test`; no SQLite result is counted as green.

The accumulated cutover set covered the posting seam, original return basis,
DN/RN/POS writers, all three composite roots, contained failure mechanisms,
interactive and projected scrap, voucher ordering, all ten lock-order pairs,
and GR ordering:

```text
Tests: 90 passed (484 assertions)
Duration: 155.31s
```

The shipped voucher/document regressions plus the structural direct-posting
rule and buffer unit tests then passed independently:

```text
Tests: 66 passed (294 assertions)
Duration: 137.84s
```

An additional broad document run passed 71 tests before encountering three
pre-existing `ReturnNoteIntegrationTest` fixture failures. The fixture on the
pinned base inserts a SEALED invoice without the fiscal hash/chain fields
required by the already-existing `chk_fiscal_mandatory_core` constraint; M2
does not touch that constraint or fixture. The required current document paths
were therefore re-run explicitly in the 66-test green command above.

## M2 hard gates

- All ten T11c labels are green in the two-connection terminal-order matrix.
  The load-bearing production-frame traces cover DN, RN, POS, buffered scrap,
  and the voucher statement reorder; the matrix composes those proven frames
  pairwise, while the sensitivity controls reproduce `40P01` only for
  deliberately reversed order.
- T16 contained-failure coverage forces closed-period, posting-direction, and
  PostgreSQL check-constraint failures. The signed POS receipt and stock truth
  survive while the savepoint leaves zero `inventory_entry` /
  `batch_write_off` entries for the failed receipt.
- T16d pins the projected three-entry arithmetic (`inventory_exit`,
  `inventory_entry`, `batch_write_off`) at the original sale cost and pins the
  interactive restore/write-off pair at identical amount and `entry_date`.
- T16e pins the shipped voucher bytes after the statement reorder:
  `voucher_ledger.amount=-10.00000`, currency `EUR`, resulting balance
  `40.00000`, unchanged terminal/user coordinates, GL source/id, server-date
  `entry_date`, balanced 10.000 debit/credit totals, and replay idempotency.
- Pre-watermark POS replay persists a historical movement and no GL entry; a
  newly created company receives a cutover watermark at its creation instant.
- A third-line DN posting failure rolls back all movements, fiscal seal, and
  chain sequence; retry produces sequence 1 exactly once.
- The legacy invoice COGS listener and provider registration are deleted.

## Static and formatting checks

```text
Pint (all changed PHP files): pass
PHPStan (all 12 changed production PHP files): [OK] No errors
git diff --check: pass
```

## Round 8 — scoped STOP-A remediation

Round 8 is governed by
`docs/handoff/reviews/wave3-3c-3d/ORCHESTRATOR-RULING-2026-08-18-m2-stop-a.md`.
The progress register records this as the single authorized
`stop_a_scoped_fix_round`; `max_fix_rounds` remains 5.

R-1 now follows the ruled option (a). An interactive refund resolves its cost
from the original `POSSale` movement at receipt + product + variant grain and
uses `receiptLineUnitCost()` only when that movement does not exist. The new
fixture deliberately stores `1.234568` in the movement while the receipt line
rounds it to `1.2346`; before the production change it failed with
`expected 1.234568, got 1.234600`, and it now proves that both the restore and
write-off use the movement basis. The scale-6 migration docblock now describes
the receipt-line field as a projected WAC snapshot and legacy fallback.

Guard 4 is no longer test opt-in. `InventoryGlPostingBoundaryGuard` evaluates
every handled request/job and asserts only when `DB::transactionLevel() === 0`;
the two opt-in methods and all eight calls in the six real-root suites are gone.
The buffer preserves leaks in unit-test application instances so the root
boundary can observe them, while wrapper-transaction tests defer the assertion
until the actual root. The initial round-8 focused run was red at the R-1
assertion and all three request/job boundary cases:

```text
Tests: 4 failed, 1 passed (11 assertions)
R-1: expected 1.234568, got 1.234600
Guard 4: nested, request, and job leak probes did not throw
```

The identical focused run after the implementation passed:

```text
Tests: 5 passed (16 assertions)
```

Reverting the round-8 implementation while retaining its tests reproduced the
same `4 failed, 1 passed (11 assertions)` result. A separate D-13 ratchet
sensitivity mutation set an above-watermark projected sale movement cost to
NULL and failed with `1 !== 0`; restoring the implementation returned
`1 passed (2 assertions)`. The ratchet covers both `POSSale` and `POSReturn`.

The ruling's nine-class regression was split into two fresh PostgreSQL
processes because the six real-root classes deliberately commit data that can
contaminate the wrapper classes when PHPUnit reuses one database process:

```text
Six formerly-opt-in real-root classes: 69 passed (310 assertions)
StandaloneInvoiceGuidedDeliveryTest + InvoiceDeliveryNoteConfirmationTest
  + ReceiptReturnFlowTest: 41 passed (218 assertions)
Total ruled regression: 110 passed (528 assertions)
GoodsReceiptGlPostingOrderTest: 12 passed (42 assertions)
```

For diagnostic transparency, a single-process aggregate was also run. Its six
real-root classes passed, after which committed rows caused five fixture-count
failures in the wrapper classes (`5 failed, 105 passed (528 assertions)`). The
two clean-process results above are the acceptance evidence.

### T11c compositional register

Per the round-8 ruling, the pair-salted two-connection harness is retained only
as scaffold. The all-ten-green gate is supported by terminal traces of every
participating production writer:

| Pair | Writers | Terminal trace evidence |
|---|---|---|
| 1 | DN × DN | `CogsRelocationCharacterisationTest::test_t11c_pair_one_delivery_writer_posts_only_after_its_inventory_loop` covers each DN writer. |
| 2 | DN × RN | COGS trace pair-one (DN) + pair-two (RN). |
| 3 | DN × POS sale | COGS trace pair-one (DN) + pair-three (POS). |
| 4 | counting × GR | `GoodsReceiptGlPostingOrderTest::test_the_company_gl_advisory_is_not_held_while_later_lines_take_row_locks` and `test_the_gl_phase_runs_after_every_row_lock_including_the_purchase_order` cover the GR writer. The current counting writer takes inventory/product locks and has no inventory-GL company advisory before its planned M5 work. |
| 5 | POS sale × GR | COGS trace pair-three (POS) + both GR terminal traces. |
| 6 | multi-DN × invoice post | `InventoryGlCompositeRootTest::test_c3_and_c1_production_roots_flush_their_nested_writers` covers the composite root; the COGS DN trace covers every nested DN writer. |
| 7 | scrap refund × DN | `PosReturnScrapWriteOffTest::test_t11c_scrap_company_advisory_is_terminal_to_the_full_inventory_loop` + COGS trace pair-one. |
| 8 | scrap refund × POS sale | POS-return scrap terminal trace + COGS trace pair-three. |
| 9 | voucher POS × DN | `InventoryGlVoucherLockOrderTraceTest::test_voucher_company_advisory_is_terminal_to_stock_projection` + COGS trace pair-one. |
| 10 | voucher POS × POS sale | voucher terminal trace + COGS trace pair-three. |

### Deptrac reconciliation and tickets

The current ratchet reports 127 violations against the checked-in baseline of
99. M1 recorded 116, so the M2 delta is +11. All 11 are the planned
Domain-to-Inventory-Application calls introduced by the atomic buffer seam:
six in `DeliveryNoteService`, one in `RefundService`, and four in
`ReturnNoteService`. The four other violations reported in those touched
Domain files already existed at M1 (three Delivery Note stock/WAC/batch edges
and one Return Note WAC edge). No baseline was changed.

The four ruling-approved ship-with-ticket findings are recorded before review:

- `docs/superpowers/tickets/2026-08-18-pos-receipt-immutability-trigger-test-bypass.md` (P3-6)
- `docs/superpowers/tickets/2026-08-18-pos-refund-missing-sale-movement-history.md` (P3-8)
- `docs/superpowers/tickets/2026-08-18-pos-refund-hard-deleted-product-refusal.md` (P3-9)
- `docs/superpowers/tickets/2026-08-18-inventory-gl-cutover-duplicate-precondition.md` (P3-10)

P3-10 remains a hard per-tenant pre-promotion deploy check over all five
inventory-GL source types. The vacuous local M0 probe is not cited as deploy
evidence. No file under `.github/workflows/**` was touched.

Round-8 static checks before the history squash:

```text
Pint (all changed PHP files): pass
PHPStan (five touched production/migration files): [OK] No errors
deptrac ratchet: 127 current, 116 at M1, 99 baseline
git diff --check: pass
```

The D-13 rewrite then combined the original cutover and all three remediation
commits into the single atomic commit `2bd9595d9`; the four superseded commit
identifiers are no longer ancestors of `HEAD`. The final tree matched the
pre-squash tree exactly. Fresh verification from the rewritten history produced:

```text
Five real-root files in one PostgreSQL process: 58 passed (268 assertions)
PosReturnScrapWriteOffTest in an isolated PostgreSQL process: 11 passed (42 assertions)
Ruled six-file total: 69 passed (307 + 3 = 310 assertions)
Three wrapper-transaction files: 41 passed (218 assertions)
GoodsReceiptGlPostingOrderTest: 12 passed (42 assertions)
Pint (atomic-commit PHP paths): pass
PHPStan (round-8 production/migration paths): [OK] No errors
deptrac ratchet: 127 current, 116 at M1, 99 baseline
git diff --check: pass
```

The first combined post-squash run reached 68 passes before one POS test's
`setUp()` collided on a Faker-generated tenant slug committed by earlier
real-root classes. Running that class in its required isolated process passed
all 11 tests; no product assertion failed.
