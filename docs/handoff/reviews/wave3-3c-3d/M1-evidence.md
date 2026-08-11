# M1 evidence — inventory GL seam and amended T11c gate

Authority for the split exit condition:
`docs/handoff/reviews/wave3-3c-3d/ORCHESTRATOR-RULING-2026-08-11-t11c-sequencing.md`.
The ruling requires pairs 1–6 green in M1, pairs 7–10 red for the missing T16d/T16e
change with their output captured here, and all ten green as a hard M2 gate.

## T11c production traces and sensitivity control

The acceptance evidence has two independent halves:

1. Real writers are traced through `DB::listen`. The trace identifies the company GL advisory by
   both its SQL and its bound company id, and identifies the last `stock_levels` / `stock_movements`
   statement in the complete writer loop. The assertion is `first company advisory > last inventory
   statement`, which flips without editing the test when T16d buffers scrap posting or T16e moves
   voucher redemption after stock projection.
2. `InventoryGlLockOrderContentionTest` uses two independent PostgreSQL connections to prove that
   the observed advisory-first shape is capable of producing `40P01`, while the terminal-advisory
   target does not. This is a sensitivity control, not the source of the production order claim.

- Target order: both roots take inventory before company GL. The second root waits on inventory,
  then takes GL after the first commits. Both statements return SQLSTATE `00000`.
- Broken order: one root takes inventory then requests GL while the other takes GL then requests
  inventory. Both blocking queries are sent before either result is collected; PostgreSQL must
  resolve the AB-BA cycle and returns `40P01` on one connection.
- The pair labels are derived from the M0 D-28 caller sweep and ten-pair register, not reconstructed
  from the plan's original five lanes.

## Pairs 1–6 — required GREEN

Command (real PostgreSQL, local port 5432):

```text
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=autoerp_test DB_USERNAME=autoerp DB_PASSWORD=*** \
php artisan test tests/Feature/Inventory/InventoryGlLockOrderContentionTest.php
```

Actual result:

```text
PASS  Tests\Feature\Inventory\InventoryGlLockOrderContentionTest
✓ pair 1 — DN confirm x DN confirm
✓ pair 2 — DN confirm x RN confirm
✓ pair 3 — DN confirm x POS projection
✓ pair 4 — counting listener x GR (PO is Received before GL)
✓ pair 5 — POS projection x GR (PO is Received before GL)
✓ pair 6 — multi-DN composite x invoice post
✓ pair 7 sensitivity control reproduces 40P01
✓ pair 8 sensitivity control reproduces 40P01
✓ pair 9 sensitivity control reproduces 40P01
✓ pair 10 sensitivity control reproduces 40P01
✓ advisory acquired inside an aborted subtransaction is released
Tests: 11 passed (126 assertions)
```

The GR `Received` arm is independently production-driven by
`GoodsReceiptGlPostingOrderTest::test_goods_received_fires_after_the_purchase_order_reaches_received`:
two real receipt lines dispatch two real `GoodsReceived` events, and each listener observes the PO
already at `DocumentStatus::Received`. Actual result: `1 passed (3 assertions)`.

Pair 6's D-28 composition arm is independently pinned by
`InventoryGlPostingSeamTest::test_one_root_flush_posts_both_dn_contexts_without_cross_contamination`:
two DN-attributed contexts produce two distinct `inventory_exit` rows through exactly one root
`flushIfOutermost()` call, and leave the scoped buffer empty. Actual result: `1 passed (4 assertions)`.

## Pairs 7–10 — required RED in M1

Command:

```text
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=autoerp_test DB_USERNAME=autoerp DB_PASSWORD=*** \
php artisan test tests/Feature/POS/PosReturnScrapWriteOffTest.php --filter=t11c_scrap

DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=autoerp_test DB_USERNAME=autoerp DB_PASSWORD=*** \
php artisan test tests/Feature/Fiscal/PosCoreReceiptProjectionRefundDispositionStockTest.php \
  --filter=t11c_voucher
```

Actual output, reproduced on the ruled M1 tree:

```text
FAIL  Tests\Feature\POS\PosReturnScrapWriteOffTest

pair 7 pos-refund-scrap_x_dn-confirm:
  T16d missing; first_company_advisory=53, last_inventory=79
pair 8 pos-refund-scrap_x_pos-sale:
  T16d missing; first_company_advisory=53, last_inventory=79

Tests: 2 failed (6 assertions)

FAIL  Tests\Feature\Fiscal\PosCoreReceiptProjectionRefundDispositionStockTest

pair 9 voucher-pos-sale_x_dn-confirm:
  T16e missing; first_company_advisory=13, last_inventory=30
pair 10 voucher-pos-sale_x_pos-sale:
  T16e missing; first_company_advisory=13, last_inventory=30

Tests: 2 failed (6 assertions)
```

These are cause-specific production reds. Pairs 7/8 process a real two-line interactive scrap
return: line one reaches inline movement-keyed GL at query 53, then line two continues inventory
persistence through query 79. Pairs 9/10 project a real voucher-funded POS sale: voucher GL takes
the company advisory at query 13, then stock projection continues through query 30. The four
two-connection controls separately return one `40P01` apiece for the corresponding reversed order.
After M2, the same writer tests must turn green without edits; the separate sensitivity controls
remain green by continuing to prove that a deliberately reversed order is detected.

## Round-1 remediation and revert/replay

- All four `MovementGlKind` dispatch arms are now pinned, including inbound/outbound count
  correction account direction and batch write-off argument/idempotency behavior.
- `enqueue` executes zero queries; a real savepoint rollback discards only its frame; a root rollback
  posts nothing and the next root posts only its own context; a non-default-connection rollback does
  not clear the tenant buffer.
- A two-line return confirmation persists both `return_cost_basis` records and uses each exact cost
  on its resulting stock movement.
- Existing Draft movement entries are repaired on synchronous replay. Both `batch_write_off` and
  `batch_write_off_reversal` are idempotent under their new blocking unique-index predicate.
- The partial-index migration has the pgsql house guard. The local duplicate deploy probe returned
  integer `0` across all five indexed source types.
- I-2 is now an AST rule with positive and comment-decoy/disjoint fixtures, not a whole-file grep.

Actual revert/replay: commit `2a4c67c4b` reverted the production hardening while leaving the tests
installed. The focused run failed three ways: duplicate `batch_write_off` hit `23505`, a replayed
movement remained Draft, and a central-connection rollback erased the tenant buffer. Commit
`a8c797222` reapplied the implementation; after resetting only the dedicated `autoerp_test` public
schema (the first replay run exhausted PostgreSQL's test-schema DDL lock budget), the same run passed
`3 tests (19 assertions)`.

## Aborting subtransaction cannot-verify

The same test acquires the company advisory after a savepoint, proves a second connection cannot
take it, rolls back to that savepoint, then proves the second connection can take it while the root
transaction remains open. PostgreSQL therefore releases a transaction-scoped advisory acquired
inside an aborted subtransaction. The buffer design does not rely on that behavior: its explicit
`mark()` / `rollbackTo()` still discards savepoint-local contexts.
