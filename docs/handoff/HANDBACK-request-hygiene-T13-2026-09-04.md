# HANDBACK — Request Hygiene Phase A, Task 13 (transfer/adjustment keys + transfer collision replay, ID-3/ID-4)

- **Plan:** `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 13: Transfer/adjustment keys and transfer collision replay (ID-3, ID-4)` (revision 11, gate r10 = dispatch-ready)
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t13`
- **Branch:** `lane/rh-t13-transfer-idempotency` (based on local `dev` `a97631051`)
- **Commits:** `62044b5e7` (backend), `fd8fef0d3` (web), plus this handback commit.
- **Result:** every named check in Step 6 passed. **Browser double-click probes were NOT run** (no local stack tonight) — see *Not done / promotion-owed*.

## What landed

| # | File | Change |
|---|---|---|
| 1 | `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php` | `initiate()` wrapped in `try { DB::transaction(...) } catch (UniqueConstraintViolationException)`; two new protected seams `findExistingTransfer()` / `insertTransfer()` carrying the pre-existing pre-check query and header INSERT verbatim. |
| 2 | `apps/api/tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php` | **new.** PG-only, no `RefreshDatabase`. Real dual-unique-index failing INSERT at transaction depth 0 and depth 1, plus the `InsertCollidingStockTransferService` harness. Bootstraps an empty database itself. |
| 3 | `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php` | Two rethrow tests (no key; different index with a no-row reread) + `uniqueViolation()`/`throwingService()` helpers + the `ThrowingStockTransferService` harness. |
| 4 | `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx` | Page-scope `useIdempotencyKey()`; `idempotency_key` first key of the existing `CreateStockTransferInput` payload; `resetIdempotencyKey()` immediately after the awaited `mutateAsync`, before toast/navigate. |
| 5 | `apps/web/src/features/stock-adjustments/pages/CreateStockAdjustmentPage.tsx` | Same, on the `submit()` path; reset lands before `setRefusal(null)`/navigate. |
| 6 | `apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx` | Hoisted reset spy + `@/hooks/useIdempotencyKey` mock (`transfer-key`); exact-payload assertion now starts with `idempotency_key`; reset-called-once `waitFor`. |
| 7 | `apps/web/src/features/stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx` | Separate hoisted reset spy + mock (`adjustment-key`); payload type extended with `idempotency_key: string`; assertion + reset `waitFor`; `beforeEach` gains `vi.clearAllMocks()` and the explicit spy reset. |

Not touched, as the plan requires: `features/stock-transfers/api/queries.ts`, `features/stock-adjustments/api/queries.ts`, `QuickStockAdjustmentModal`, `ReplenishmentFulfillmentService.php` (verify-only consumer), any DTO or generated type (`CreateStockTransferInput` and the adjustment create body already declared `idempotency_key`).

## Environment

- Lane-private PostgreSQL, because the shared 5433 instance is down and its port is held by another project's container:
  ```
  docker run -d --name autoerp_pg_t13 --shm-size=1g -p 127.0.0.1:5463:5432 \
    -e POSTGRES_USER=autoerp -e POSTGRES_PASSWORD=autoerp_secret \
    -e POSTGRES_DB=autoerp_test_t13 timescale/timescaledb:latest-pg16
  ```
  `pg_isready` → `/var/run/postgresql:5432 - accepting connections`. **Left running** at the end of the lane.
- PG legs run as `cd apps/api && DB_HOST=127.0.0.1 DB_PORT=5463 DB_DATABASE=autoerp_test_t13 DB_CENTRAL_DATABASE=autoerp_test_t13 php artisan test -c phpunit-pgsql.xml <paths>`. There is no `.env.testing` in this worktree; `.env` supplies the rest and Laravel's immutable dotenv loader lets the shell overrides win.
- SQLite legs use the default `phpunit.xml` via `./vendor/bin/phpunit <paths>`. The full suite was never run.

## Step 1/2 — red first (PG collision harness against unrefactored code)

The collision test file was written **before** the service refactor so the red run is real. Command:

```
cd apps/api && DB_HOST=127.0.0.1 DB_PORT=5463 DB_DATABASE=autoerp_test_t13 DB_CENTRAL_DATABASE=autoerp_test_t13 \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php
```

```
   FAIL  Tests\Feature\Inventory\StockTransferIdempotencyCollisionPostgresTest
  ⨯ initiate rereads committed winner after dual generated number and k… 9.71s
  ⨯ initiate inside outer transaction rereads dual collision after save… 0.51s
  ────────────────────────────────────────────────────────────────────────────
  Failed asserting that two strings are identical.
  -''
  +'01a069c9-c750-72dd-ae58-8f926567ae2c'

  at tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php:200
  ➜ 200▕         self::assertSame($service->winnerId, $winner->id);
  ...
  at tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php:239
  ➜ 239▕         self::assertSame($service->winnerId, $winner->id);

  Tests:    2 failed (4 assertions)
  Duration: 10.27s
```

Exactly the failure mode the plan predicts: the file parses, but production never calls the seams, so `winnerId` stays `''`. **`ensureCentralSchemaMigrated()` bootstrapped the empty database successfully on this first run** — the 9.71s on the first method is the `migrate --force`. No finding, no helper fix needed.

Then Step 1's refactor was applied and the same command went green:

```
   PASS  Tests\Feature\Inventory\StockTransferIdempotencyCollisionPostgresTest
  ✓ initiate rereads committed winner after dual generated number and k… 0.90s
  ✓ initiate inside outer transaction rereads dual collision after save… 0.46s

  Tests:    2 passed (13 assertions)
  Duration: 1.40s
```

## Step 3 — rethrow tests + falsification

```
cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/InventoryTransferServiceTest.php --filter 'rethrows'
...
OK (2 tests, 6 assertions)
```

These two tests are written after the seam extraction (the plan orders Step 3 after Step 1), so they were green on first run. To prove they are not vacuous, both rethrow guards were temporarily mutated to `throw new \RuntimeException('MUTANT-…')` and the pair re-run:

```
FF                                                                  2 / 2 (100%)

1) …::test_initiate_rethrows_unique_violation_when_no_key_was_supplied
Failed asserting that exception of type "RuntimeException" matches expected
exception "Illuminate\Database\UniqueConstraintViolationException".
Message was: "MUTANT-no-key" at …/StockTransferService.php:182

2) …::test_initiate_rethrows_collision_on_different_unique_index_when_idempotency_reread_finds_no_row
Failed asserting that exception of type "RuntimeException" matches expected
exception "Illuminate\Database\UniqueConstraintViolationException".
Message was: "MUTANT-no-row" at …/StockTransferService.php:187

FAILURES! Tests: 2, Assertions: 2, Failures: 2.
```

The mutation was reverted immediately (`git diff --stat` re-checked); the committed service has both original rethrows.

## Step 6 — verification

### PG collision, both methods together and serially

Together (the plan's exact command, self-contained on the empty database):

```
cd apps/api && DB_HOST=127.0.0.1 DB_PORT=5463 DB_DATABASE=autoerp_test_t13 DB_CENTRAL_DATABASE=autoerp_test_t13 \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php

   PASS  Tests\Feature\Inventory\StockTransferIdempotencyCollisionPostgresTest
  ✓ initiate rereads committed winner after dual generated number and k… 0.90s
  ✓ initiate inside outer transaction rereads dual collision after save… 0.46s
  Tests:    2 passed (13 assertions)
```

Serially, one method per process:

```
### test_initiate_rereads_committed_winner_after_dual_generated_number_and_key_collision
  ✓ initiate rereads committed winner after dual generated number and k… 1.18s
  Tests:    1 passed (5 assertions)

### test_initiate_inside_outer_transaction_rereads_dual_collision_after_savepoint_rollback
  ✓ initiate inside outer transaction rereads dual collision after save… 1.05s
  Tests:    1 passed (8 assertions)
```

**Both depths proven:** depth 0 records `secondLookupTransactionLevel === 0`; depth 1 records `1`, with `DB::transactionLevel()` still `1` inside the caller's closure after `initiate()` returns and `0` after commit — i.e. the failed inner `DB::transaction()` rolled back only its savepoint. Exactly one row survives at `(tenant_id, company_id, idempotency_key)` in both cases, and the returned transfer is the writer connection's committed `winnerId`.

### Named backend paths (SQLite, default config)

```
cd apps/api && ./vendor/bin/phpunit \
  tests/Feature/Inventory/InventoryTransferServiceTest.php \
  tests/Feature/Inventory/StockTransferAutoAllocateFefoTest.php \
  tests/Feature/Inventory/StockTransferLocationAccessRuleTest.php \
  tests/Feature/Inventory/StockTransferLocationScopeTest.php \
  tests/Feature/Inventory/StockTransferRestrictedMembershipTest.php \
  tests/Feature/Inventory/StockTransferShowBatchAllocationsTest.php \
  tests/Feature/Inventory/StockTransferVariantTest.php \
  tests/Feature/Replenishment/ReplenishmentActionsTest.php

................................................................. 65 / 77 ( 84%)
............                                                      77 / 77 (100%)

Time: 01:03.721, Memory: 183.00 MB

OK (77 tests, 245 assertions)
```

`ReplenishmentActionsTest` is the ID-4 consumer check: `ReplenishmentFulfillmentService` calls the refactored `initiate()` inside its grouped fulfilment transaction, which is precisely the depth-1 case the PG harness covers.

### PHPStan (level 8)

```
cd apps/api && DB_HOST=127.0.0.1 DB_PORT=5463 DB_DATABASE=autoerp_test_t13 DB_CENTRAL_DATABASE=autoerp_test_t13 \
  ./vendor/bin/phpstan analyse \
    app/Modules/Inventory/Application/Services/StockTransferService.php \
    tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php \
    app/Modules/Replenishment/Application/Services/ReplenishmentFulfillmentService.php

 3/3 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
 [OK] No errors
```

### Pint

```
cd apps/api && ./vendor/bin/pint --test \
  app/Modules/Inventory/Application/Services/StockTransferService.php \
  tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php \
  tests/Feature/Inventory/InventoryTransferServiceTest.php

{"result":"pass"}
```

### Frontend test paths

```
cd apps/web && pnpm vitest run \
  src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx \
  src/features/stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx \
  src/features/stock-transfers/__tests__/queries.test.tsx

 ✓ src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx (8 tests) 1598ms
   ✓ CreateStockTransferPage line entry bar > submits the complete header and line payload with decimal strings intact  409ms

 Test Files  3 passed (3)
      Tests  21 passed (21)
```

Falsification: removing `resetIdempotencyKey()` from the transfer page and `idempotency_key` from the adjustment payload turned exactly the two intended tests red and left the other 19 green —

```
   × CreateStockAdjustmentPage — payload > sends a SIGNED string delta and the fresh anchor, and posts immediately
   × CreateStockTransferPage line entry bar > submits the complete header and line payload with decimal strings intact
AssertionError: expected undefined to be 'adjustment-key' // Object.is equality
    241|     expect(payload.idempotency_key).toBe('adjustment-key')
```

Both mutations were reverted and the 21/21 green re-confirmed.

### Typecheck

```
cd apps/web && pnpm typecheck
> tsc --noEmit
```

(clean, no output)

### ESLint (touched files)

```
cd apps/web && pnpm exec eslint \
  src/features/stock-transfers/pages/CreateStockTransferPage.tsx \
  src/features/stock-adjustments/pages/CreateStockAdjustmentPage.tsx \
  src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx \
  src/features/stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx

✖ 7 problems (0 errors, 7 warnings)
```

**Zero errors.** All 7 warnings are pre-existing lines this lane did not author: three `restrict-template-expressions` on the adjustment page (335/387/410), the two `createMutate.mock.calls[0]?.[0] as {…}` casts in the adjustment test (both present in `HEAD`, verified with `git show`), the transfer test's batch-allocation cast, and the transfer page's `DraftLine & { product }` filter predicate.

## Deviations from the plan

1. **Step order: the Step-2 collision test file was authored before the Step-1 refactor.** The plan lists the refactor first, but the Global Constraints require a failing test first and the plan's own Step-2 text asserts the file is red against current code. Writing it first is what produced the recorded red run above. No content differs from the plan's snippet.
2. **The transfer payload keeps its existing `cleanLines.map((l) => …)` parameter name** rather than the plan snippet's `(line) => …`. Only `idempotency_key: idempotencyKey,` was inserted as the first key; the rest of the payload is byte-identical to what was there, keeping the diff to one line.
3. **`insertTransfer()` keeps the original `/** @var StockTransfer $transfer */` annotation and returns `$transfer`** instead of returning `StockTransfer::create([...])` directly. `StockTransfer::create()` is statically typed as `Model`, so returning it inline would fail PHPStan level 8; the plan's snippet omitted the annotation the original code carried. Behaviour is identical.
4. **Explanatory comments added.** The catch block carries a comment recording *why* the constraint name is never inspected, and both seams carry a docblock saying why they are protected. Two one-line comments were added on the frontend for the same reason (`// ID-3: only an AWAITED success starts a new logical attempt.`). No plan logic changed.
5. **`ensureCentralSchemaMigrated()` needed no fix.** The plan flagged a possible finding if the bootstrap failed on the empty database; it did not. Recorded here because the plan asked for the outcome either way.
6. **`--filter` used for the serial PG legs and the mutation runs.** The plan's Step 6 command (whole class, no filter) is the one recorded as the primary result; the per-method runs are the "serially" half of the same requirement.

## Not done / promotion-owed

- **Browser double-click probes on both forms were NOT run.** No local stack was available tonight (the shared PostgreSQL on 5433 is down and Docker was only brought up for a lane-private test database; no API server, no vite dev server, no seeded tenant). Step 6's "Browser double-click both forms" is therefore **outstanding and owed before promotion**. The automated coverage proves the payload carries the key, that reset fires exactly once after an awaited success, and that the server replays a real committed winner — but it does not prove the browser-level double-click behaviour end to end.
- **Gate not yet run.** Step 6 names `inventory-costing-reviewer` **plus** `frontend-conventions-reviewer`; neither has been dispatched by this lane.
- **Lane not rebased/merged.** Base is local `dev` `a97631051`; the plan requires a rebase before review.
- The `pre-commit` react-doctor hook printed "React Doctor found staged regressions" on the web commit (it does not block). Running `react-doctor` on the four touched files reports 10 findings, **all pre-existing structural issues** at lines 341/484/604/611/627/913 plus the two giant-component warnings — none on a line this lane authored. No action taken.
- The lane-private container `autoerp_pg_t13` (port 5463) is **still running** for the reviewer.

## For the reviewer

- The load-bearing decision is **catching outside `DB::transaction()`, not inside**. Catching inside would run the reread on a connection PostgreSQL has already put into the aborted-transaction state. `StockTransferIdempotencyCollisionPostgresTest::$secondLookupTransactionLevel` is the assertion that pins this: `0` at top level and `1` under a caller's transaction.
- **Do not reintroduce constraint-name matching.** `test_initiate_rethrows_collision_on_different_unique_index_when_idempotency_reread_finds_no_row` is the guard that catching *any* `UniqueConstraintViolationException` still does not swallow an unrelated company-number collision — the discriminator is the committed row, not the index name.
- Different-key `COUNT()+1` transfer-number races are untouched and remain **S-19 debt for Phase B lane B-4**.
- The FE contract to check: the key lives at **page** scope, not in the mutation hook, and reset happens only after `await mutateAsync` resolves. The adjustment refusal/acknowledge/resubmit loop deliberately reuses the same key — a refused adjustment writes no row, so the server-side reread misses and the resubmit proceeds.

## Fix round 1 (2026-09-04, orchestrator)

Inventory-costing gate r1 (`docs/superpowers/reviews/2026-09-04-request-hygiene-t13-gate-inventory-costing.md`) found one merge-gating item: `StockTransferIdempotencyCollisionPostgresTest` skips on SQLite and was absent from the PostgreSQL class allowlist in `.github/workflows/ci.yml` (`backend-test-pgsql` job), so the ID-4 collision proof ran in zero automated lanes. Fix: the class name is appended to that `--filter` alternation. Standing caveat (ci.yml ~:1016-1020): `backend-test-pgsql` does not run on `push -> dev`, so this arms PRs and `main` pushes only. Non-blocking N-1..N-8 from the gate are recorded as follow-ups (notably N-1: add a stock-conservation assertion on the replay path; N-2: second-company key-reuse test; N-6: adjustments still lack collision replay — declared residual).
