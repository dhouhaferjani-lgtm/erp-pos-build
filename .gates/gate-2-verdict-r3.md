# Gate 2 — Wave 2 Inventory Visibility — Round 3 Review

> Controller-run 2026-07-20. Reviewer: inventory-costing-reviewer (Opus). Rounds 1–2: `.gates/gate-2-verdict.md`, `.gates/gate-2-verdict-r2.md` (both REJECT).

Worktree `/Users/houssamr/Projects/syneriva/apps/erp.multiloc`, tip `c1541a288`, fix commit `65ef90bb6`. Sole round-2 IMPORTANT was the per-class pg-pinning hack that ERRORed the five inventory Feature classes under the default SQLite runner while no CI lane ran them on PostgreSQL.

## Verification results

**1. Hacks removed — CONFIRMED.** Grep for `beforeRefreshingDatabase`/`connectionsToTransact` across all seven test classes returns nothing. Commit `65ef90bb6` is a pure 8-line removal per class (e.g. `apps/api/tests/Feature/Inventory/StockMatrixEndpointTest.php` diff deletes the `connectionsToTransact = ['pgsql']` property and `beforeRefreshingDatabase()` `config(['database.default' => 'pgsql'])` flip; identical deletion in the other four). No residual `database.default` mutation.

**2. Green under default SQLite runner — CONFIRMED.** `./vendor/bin/phpunit -c phpunit.xml` over the five paths → `OK (19 tests, 82 assertions)`, config resolved to `phpunit.xml`. No errors (round 2 produced `role "root" does not exist` errors here). Regression fully cleared.

**3. CI genuinely gates on PostgreSQL and triggers PR→dev — CONFIRMED.**
- Workflow trigger: `.github/workflows/ci.yml:6-7` — `pull_request: branches: [main, dev]`, so PRs targeting `dev` run the workflow.
- Job gate fixed: `backend-test-pgsql` `if` at `ci.yml:293` now reads `... || github.base_ref == 'dev' || ...` (round 2 found this arm absent at the old `:291`). On a PR→dev the job evaluates true.
- Filter: `ci.yml:556` `--filter=` now appends all seven classes — `StockRebalanceEndpointTest|StockMovementLocationFilterTest|StockMatrixEndpointTest|StockThresholdTest|GoodsReceiptDestinationTest|PaymentRepositoryLocationTest|PosBridgeLocationAttributionTest`. All seven files exist with matching `final class` names (verified). Alternation is well-formed (bare class names, no regex-special chars); `phpunit --filter` matches class-name substrings, the established pattern for the other ~30 entries.
- Real-PG execution: lane now invokes `php artisan test -c phpunit-pgsql.xml` (`ci.yml:555`), and `phpunit-pgsql.xml:60` forces `DB_CONNECTION=pgsql` with `force="true"` — no longer relying on a step-env override. The `-c phpunit-pgsql.xml` invocation form is the established working pattern (identical to the green T6 lane at `ci.yml:651`).
- Note (informational, not a defect): the sqlite `backend-test` lane runs `--testsuite=Unit` only and still skips PR→dev, so these Feature tests are gated *exclusively* on the PG lane on PR→dev — which is exactly the desired outcome given SQLite masks PostgreSQL aggregate/`SUM` behavior.

**4. Real PostgreSQL run — CONFIRMED GREEN.** Local `autoerp_postgres` reachable on `127.0.0.1:5433` (user `autoerp`/`autoerp_secret`). Created `autoerp_test`, ran the five paths under `phpunit-pgsql.xml` against real PG → `OK (19 tests, 82 assertions)`, config resolved to `phpunit-pgsql.xml`. The aggregate/rollup/threshold/receiving-reconciliation logic passes on the production engine, not just SQLite.

**5. Regression scan of `65ef90bb6` — CLEAN.** Touches only: `ci.yml` (above), the five test files (pure hack removal), and `apps/web/src/features/inventory/components/ThresholdEditCell.tsx`. The FE change renames the two aria-label keys `stockByLocation.minQuantity/maxQuantity` → `stock.minQuantity/maxQuantity`; both target keys exist (`apps/web/src/locales/en/inventory.json:264-265`, nested under the `stock` block at `:241`) and resolve to "Minimum/Maximum quantity". Still uses string-emitting `<QuantityInput decimalPlaces={4}>` with `formatQuantity` placeholders — no float, no `parseFloat`/`Number`, precision discipline intact.

## Findings

### Critical
None.

### Important
None. The sole round-2 IMPORTANT is fully resolved: hacks removed, default runner green, and CI now runs all seven classes on real PostgreSQL on PR→dev.

### Minor
None material. (The FE key rename is correct and keys resolve.)

## What to fix before merge
Nothing. The PostgreSQL gating contract is genuinely complete and both runners are green.

VERDICT: APPROVE
