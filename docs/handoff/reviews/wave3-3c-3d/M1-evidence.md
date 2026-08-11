# M1 evidence — inventory GL seam and amended T11c gate

Authority for the split exit condition:
`docs/handoff/reviews/wave3-3c-3d/ORCHESTRATOR-RULING-2026-08-11-t11c-sequencing.md`.
The ruling requires pairs 1–6 green in M1, pairs 7–10 red for the missing T16d/T16e
change with their output captured here, and all ten green as a hard M2 gate.

## T11c instrument

`apps/api/tests/Feature/Inventory/InventoryGlLockOrderContentionTest.php` uses two independent
PostgreSQL connections. Each pair shares one inventory row and the exact per-company advisory
primitive used by `GeneralLedgerService`, `pg_advisory_xact_lock(hashtextextended(company_id, 0))`.
One shared row deliberately isolates I-1's owned cycle rather than introducing an unrelated
two-stock-row ordering cycle.

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
DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_test \
DB_CENTRAL_DATABASE=autoerp_test DB_USERNAME=autoerp DB_PASSWORD=*** \
php artisan test -c phpunit-pgsql.xml \
  tests/Feature/Inventory/InventoryGlLockOrderContentionTest.php \
  --filter='test_pairs_one_to_six|test_advisory_acquired'
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
✓ advisory acquired inside an aborted subtransaction is released
Tests: 7 passed (78 assertions)
```

The GR `Received` arm is independently production-driven by
`GoodsReceiptGlPostingOrderTest::test_goods_received_fires_after_the_purchase_order_reaches_received`:
two real receipt lines dispatch two real `GoodsReceived` events, and each listener observes the PO
already at `DocumentStatus::Received`. Actual result: `1 passed (3 assertions)`.

## Pairs 7–10 — required RED in M1

Command:

```text
DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_test \
DB_CENTRAL_DATABASE=autoerp_test DB_USERNAME=autoerp DB_PASSWORD=*** \
php artisan test -c phpunit-pgsql.xml \
  tests/Feature/Inventory/InventoryGlLockOrderContentionTest.php \
  --filter='test_pairs_seven_to_ten'
```

Actual output, reproduced on the ruled M1 tree:

```text
FAIL  Tests\Feature\Inventory\InventoryGlLockOrderContentionTest

pair 7 pos-refund-scrap_x_dn-confirm:
  missing T16d; SQLSTATEs=00000,40P01
pair 8 pos-refund-scrap_x_pos-sale:
  missing T16d; SQLSTATEs=40P01,00000
pair 9 voucher-pos-sale_x_dn-confirm:
  missing T16e; SQLSTATEs=40P01,00000
pair 10 voucher-pos-sale_x_pos-sale:
  missing T16e; SQLSTATEs=00000,40P01

Tests: 4 failed (48 assertions)
```

These are cause-specific reds. Every case completed table creation, transaction setup, the initial
inventory lock, the initial company advisory, and both asynchronous blocking sends; the failing
assertion is exclusively the desired no-`40P01` post-cutover assertion. A broken connection,
missing table, timeout, skipped driver, or fixture setup failure would fail earlier and would not
produce the named `40P01` plus the missing T16d/T16e diagnostic.

## Aborting subtransaction cannot-verify

The same test acquires the company advisory after a savepoint, proves a second connection cannot
take it, rolls back to that savepoint, then proves the second connection can take it while the root
transaction remains open. PostgreSQL therefore releases a transaction-scoped advisory acquired
inside an aborted subtransaction. The buffer design does not rely on that behavior: its explicit
`mark()` / `rollbackTo()` still discards savepoint-local contexts.
