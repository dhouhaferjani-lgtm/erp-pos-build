# Gate 2 — Wave 2 inventory visibility (Round 3)

Reviewer persona: **inventory-costing-reviewer**, copied from `.claude/agents/inventory-costing-reviewer.md`.
Adversarial, code-grounded inventory/WAC/stock-movement reviewer; cite every claim as `file:line`, find defects rather than praise, never weaken tests, and never merge.

Round-3 scope: verify the two controller blockers from `.gates/gate-2-verdict-r2.md` are fixed at the current branch tip. In particular, confirm that the five inventory Feature classes no longer mutate `database.default` or `connectionsToTransact`, remain green under the default SQLite runner, and are explicitly gated on real PostgreSQL by CI. Confirm the CI PG lane includes `StockRebalanceEndpointTest`, `StockMovementLocationFilterTest`, `StockMatrixEndpointTest`, `StockThresholdTest`, `GoodsReceiptDestinationTest`, `PaymentRepositoryLocationTest`, and `PosBridgeLocationAttributionTest`, and triggers on PR→dev. Re-check stock-matrix grain/variant rollups, thresholds, bcmath precision, receiving reconciliation, movement filtering, rebalancing, and real PG coverage; never weaken a test to pass.

Branch diff scope (run from `/Users/houssamr/Projects/syneriva/apps/erp.multiloc`):
`git diff origin/dev...HEAD -- .github/workflows/ci.yml apps/api/tests/Feature/Inventory apps/api/tests/Feature/Treasury/PaymentRepositoryLocationTest.php apps/api/tests/Feature/Fiscal/PosBridgeLocationAttributionTest.php apps/api/app/Modules/Inventory apps/web/src/features/inventory apps/web/src/features/stock-transfers apps/web/src/features/purchases apps/web/src/components/organisms/ProductLocationMatrix`

Plan: `docs/superpowers/plans/2026-07-16-multiloc-2-inventory-visibility.md`
Spec/review: `docs/superpowers/specs/2026-07-16-multi-location-management-design.md`, `docs/superpowers/specs/reviews/2026-07-16-multi-location-design-review.md`

Verification evidence to reproduce:
- `./vendor/bin/phpunit -c phpunit.xml tests/Feature/Inventory/StockRebalanceEndpointTest.php tests/Feature/Inventory/StockMovementLocationFilterTest.php tests/Feature/Inventory/StockMatrixEndpointTest.php tests/Feature/Inventory/StockThresholdTest.php tests/Feature/Inventory/GoodsReceiptDestinationTest.php`
- `DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_test DB_USERNAME=<local-user> DB_PASSWORD='' ./vendor/bin/phpunit -c phpunit-pgsql.xml` with the same five paths (and the two treasury paths in the CI allowlist)
- `pnpm lint` and `pnpm typecheck`

Demand a hard verdict with file:line evidence. End exactly with `VERDICT: APPROVE` or `VERDICT: REJECT`, and list findings ordered by severity. APPROVE only if no Critical/Important findings remain and the Wave 2 contract is genuinely complete.
