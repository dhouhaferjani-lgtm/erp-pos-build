# Codex Adversarial Review — Multi-Location Plans §1–§4 (pre-dispatch)

> 2026-07-16 · Codex session 019f6a1e-03b3-78a1-ba67-e1722c192b71 vs plans @ ef9e9fa65. Verdict: REJECT ×4. All findings below were folded into the plans in the follow-up commit (see git log).

## Plan 1 — Scope foundation

**Verdict: REJECT**

### Spec fidelity

1. **BLOCKER — Backfill grants every tenant user access to every company**

   - Plan evidence: the migration iterates every company, then selects every active user lacking that company’s membership and inserts an unrestricted row (`allowed_location_ids = NULL`) ([plan 1:180](docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md:180)).
   - Code evidence: memberships are the company-association boundary and are unique per user/company ([membership migration:27](apps/api/database/migrations/tenant/2025_11_30_106000_create_user_company_memberships_table.php:27), [membership migration:57](apps/api/database/migrations/tenant/2025_11_30_106000_create_user_company_memberships_table.php:57)); users are otherwise tenant-scoped ([UserController.php:74](apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:74)).
   - Required edit: do not cross-join all users with all companies. Define an auditable source for the company to which each historically memberless user belongs. If no safe source exists, make the migration fail closed for multi-company tenants and require a precomputed mapping before enforcement.

2. **BLOCKER — Required staff assignment UI is absent**

   - Plan evidence: Task 4 lists only seeder, requests, controller, and backend tests ([plan 1:442](docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md:442)); no frontend task implements the spec-required create/edit assignment UI.
   - Code evidence: the create payload has no `allowed_location_ids` ([UsersPage.tsx:54](apps/web/src/features/settings/UsersPage.tsx:54)); the edit modal manages only identity, role, and discount fields ([UserEditModal.tsx:35](apps/web/src/features/settings/components/UserEditModal.tsx:35), [UserEditModal.tsx:56](apps/web/src/features/settings/components/UserEditModal.tsx:56)).
   - Required edit: add explicit frontend files/tasks for create and edit assignment, using `GET /company/locations/all`, permission-gating with `users.manage_location_access`, “all” versus subset semantics, self-edit disablement, payload tests, and end-to-end coverage.

### Execution safety

3. **BLOCKER — Store authorization can occur after an irreversible user creation**

   - Plan evidence: Task 4 says to call `applyLocationAccessGrant` in `store` “after the Task 2 membership create” and early-return on denial ([plan 1:471](docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md:471), [plan 1:511](docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md:511)).
   - Code evidence: `store` completes its transaction and commits the user before sending the invitation and response ([UserController.php:175](apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:175), [UserController.php:218](apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:218)).
   - Required edit: authorize and validate the grant before creating anything, then create user, membership, grant, audit record, and identity-index change in one transaction. Add a test asserting a denied grant leaves no user, membership, role, audit event, or identity-index row.

4. **MAJOR — Permission deployment is not safe under push-to-dev auto-deploy**

   - Plan evidence: the new `can:users.manage_location_access` route ships in Task 6, while reseeding and cache reset are deferred to a post-deploy checklist ([plan 1:603](docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md:603), [plan 1:910](docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md:910)).
   - Code evidence: the permission does not currently exist in the seeder’s `users.*` block ([RolesAndPermissionsSeeder.php:285](apps/api/database/seeders/RolesAndPermissionsSeeder.php:285)).
   - Required edit: add an automatically executed, idempotent permission-registration step before any route/UI relying on the permission, including cache invalidation. Do not rely on a manual action after the push.

5. **MAJOR — A required regression command names a nonexistent test**

   - Plan evidence: Task 5 says to run `tests/Feature/Inventory/StockTransferControllerTest.php` and then says “adjust to the real path” ([plan 1:549](docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md:549)).
   - Code evidence: that file is not found in the codebase; the relevant existing file is [StockTransferLocationScopeTest.php](apps/api/tests/Feature/Inventory/StockTransferLocationScopeTest.php).
   - Required edit: replace the placeholder with exact existing test paths and specify the precise regression cases.

### Missing verification

6. **MAJOR — Malformed cross-tab data clears scope instead of preserving it**

   - Plan evidence: `parseScope` returns `'all'` on malformed JSON, and the storage listener hydrates that result ([plan 1:680](docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md:680), [plan 1:733](docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md:733)). Its test explicitly expects a malformed event to yield All ([plan 1:658](docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md:658)).
   - Code evidence: the hardened pattern being cited says a malformed payload “must NOT clear the selection” and only updates when parsing yields a valid known ID ([locationStore.ts:180](apps/web/src/stores/locationStore.ts:180)).
   - Required edit: distinguish invalid payload from a valid persisted `'all'`; ignore malformed storage events and test that the previous scope is preserved.

7. **MAJOR — Restricted management behavior is not verified atomically**

   - Plan evidence: tests cover permission denial and subset checks but do not assert that denied create/update requests leave all other user fields unchanged ([plan 1:454](docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md:454)).
   - Code evidence: `update` mutates role and fields inside its existing transaction before the proposed grant call’s placement is defined ([UserController.php:263](apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:263), [UserController.php:279](apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:279)).
   - Required edit: pin the guard before mutation or make denial throw inside the transaction; add rollback assertions for profile, role, membership, audit log, and identity index.

---

## Plan 2 — Inventory visibility

**Verdict: REJECT**

### Spec fidelity

1. **BLOCKER — Matrix rollup can double-count null-variant and variant rows**

   - Plan evidence: parent rollups sum all `stock_levels` rows and assume a product stores stock either at null-variant or variant grain, never both ([plan 2:143](docs/superpowers/plans/2026-07-16-multiloc-2-inventory-visibility.md:143)).
   - Code evidence: the existing query service explicitly treats null and concrete variant grains as mutually selected predicates rather than summing them together ([LocationStockQueryService.php:113](apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:113), [LocationStockQueryService.php:120](apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:120)).
   - Required edit: define rollup behavior that remains correct when both row forms exist, and test that exact mixed-data case. Do not encode the unverified “well-formed product” assumption.

2. **BLOCKER — Suggested source tries to set a nonexistent line-level source**

   - Plan evidence: Task 4 says the “Use this source” action sets the line’s source ([plan 2:353](docs/superpowers/plans/2026-07-16-multiloc-2-inventory-visibility.md:353)).
   - Code evidence: transfers have one header `sourceLocationId`, submitted as `source_location_id`; lines carry no source field ([CreateStockTransferPage.tsx:487](apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:487), [CreateStockTransferPage.tsx:649](apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:649)).
   - Required edit: make suggestions operate at the transfer header grain, define how conflicting suggestions across multiple lines are handled, and test that changing source recomputes availability and batch allocations for every line.

3. **BLOCKER — §4 expects a rebalancing endpoint that §2 never creates**

   - Plan evidence: Task 7 builds a frontend-only view consuming the current matrix page data ([plan 2:431](docs/superpowers/plans/2026-07-16-multiloc-2-inventory-visibility.md:431)).
   - Code evidence: current inventory routes expose stock levels and movements but no rebalancing endpoint ([Inventory routes.php:55](apps/api/app/Modules/Inventory/Presentation/routes.php:55), [Inventory routes.php:64](apps/api/app/Modules/Inventory/Presentation/routes.php:64)).
   - Required edit: either add and pin a scoped rebalancing endpoint in §2 or edit §4 to consume the stock-matrix endpoint and run the same pure classification logic. Both plans must name the same contract.

### Code reality

4. **BLOCKER — Threshold implementation calls a nonexistent API**

   - Plan evidence: Task 2 requires `QuantityScale::bcformatStrict($value, 4)` ([plan 2:244](docs/superpowers/plans/2026-07-16-multiloc-2-inventory-visibility.md:244)).
   - Code evidence: `QuantityScale` exposes `SCALE` and `round`, but no `bcformatStrict` method ([QuantityScale.php:14](apps/api/app/Shared/Domain/QuantityScale.php:14), [QuantityScale.php:39](apps/api/app/Shared/Domain/QuantityScale.php:39)).
   - Required edit: use the repository’s real quantity normalization API, such as `QuantityScale::round(..., QuantityScale::SCALE, QuantityScale::FLOOR)`, or explicitly add and test a shared strict-format method before consuming it.

### Cross-plan consistency

5. **BLOCKER — Movement filtering bypasses the resolver when no parameter is supplied**

   - Plan evidence: Task 8 calls `LocationScopeResolver` only when `$requested !== []`; its acceptance test says “no param → all (company-scoped)” ([plan 2:459](docs/superpowers/plans/2026-07-16-multiloc-2-inventory-visibility.md:459), [plan 2:475](docs/superpowers/plans/2026-07-16-multiloc-2-inventory-visibility.md:475)).
   - Code evidence: the current base query is company-wide, and without an added predicate returns every company location ([StockMovementController.php:36](apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:36), [StockMovementController.php:45](apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:45)).
   - Required edit: always call `resolve($user, $requested)` and always apply the returned effective IDs. Add a restricted-user/no-param test proving only allowed locations return.

### Missing verification

6. **MAJOR — Receiving destination does not test repeated partial receipts to different stores**

   - Plan evidence: the chosen approach rewrites the PO line’s `document_lines.location_id` whenever a receipt posts ([plan 2:387](docs/superpowers/plans/2026-07-16-multiloc-2-inventory-visibility.md:387), [plan 2:412](docs/superpowers/plans/2026-07-16-multiloc-2-inventory-visibility.md:412)).
   - Code evidence: the service supports partial receipt counters on the same PO line ([GoodsReceiptService.php:300](apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:300), [GoodsReceiptService.php:438](apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:438)); incoming projection reads the mutable PO-line location ([LocationStockQueryService.php:254](apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:254)).
   - Required edit: specify and test A-then-B partial receipts, including posted stock history and where the remaining PO quantity is projected after each receipt. Document the chosen single-destination remainder semantics.

7. **MAJOR — Threshold authorization is not aligned with the shared resolver/rule contract**

   - Plan evidence: Task 2 directly calls `LocationContext::validateLocationAccess` ([plan 2:244](docs/superpowers/plans/2026-07-16-multiloc-2-inventory-visibility.md:244)).
   - Code evidence: current direct access validation is membership-aware but operates as an imperative exception path ([StockMovementController.php:93](apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:93)); §1’s planned write contract is the refactored `ValidLocationAccess`.
   - Required edit: use the post-§1 injected `ValidLocationAccess` in the FormRequest or explicitly justify and test the alternative, including absent membership, null membership, foreign company, and restricted membership cases.

---

## Plan 3 — Financial location dimension

**Verdict: REJECT**

### Code reality

1. **BLOCKER — Pinned resolver namespace is wrong**

   - Plan evidence: §3 imports `App\Modules\Company\Application\Services\LocationScopeResolver` ([plan 3:33](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:33)).
   - Code evidence: §1 creates it under `App\Modules\Company\Services`; existing Company context services also live in that namespace ([CompanyContext.php:5](apps/api/app/Modules/Company/Services/CompanyContext.php:5)).
   - Required edit: replace every namespace reference with `App\Modules\Company\Services\LocationScopeResolver`.

2. **BLOCKER — Multiple supplied test harnesses do not exist**

   - Plan evidence: Task 1 imports `Tests\Feature\Treasury\Concerns\SeedsTreasuryCompany` ([plan 3:82](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:82)); Task 3 imports `Tests\Feature\Fiscal\Concerns\ProjectsSaleReceiptEvents` ([plan 3:390](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:390)); the FE example imports `@/test/utils` ([plan 3:185](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:185)).
   - Code evidence: all three paths are not found in the codebase.
   - Required edit: anchor every test to an existing treasury/fiscal test harness or add the helper as an explicit created file with complete implementation and its own verification.

3. **MAJOR — Repository validation is tenant-only, not company-scoped**

   - Plan evidence: repository location uses `ScopedExists::tenant('locations', $tenantId)` ([plan 3:155](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:155)).
   - Code evidence: `ScopedExists::tenant` checks only `tenant_id`, while `tenantAndCompany` checks both axes ([ScopedExists.php:28](apps/api/app/Shared/Presentation/Validation/ScopedExists.php:28), [ScopedExists.php:39](apps/api/app/Shared/Presentation/Validation/ScopedExists.php:39)); repositories themselves are company-scoped ([PaymentRepositoryController.php:124](apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:124)).
   - Required edit: use `ScopedExists::tenantAndCompany('locations', $tenantId, $companyId)` and add a same-tenant/different-company denial test.

### Cross-plan consistency

4. **BLOCKER — Frontend scope contract is incompatible with §1**

   - Plan evidence: §3 expects `{ locationIds, isAllLocations }` and `[]` meaning All ([plan 3:39](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:39)).
   - Code evidence: §1 pins `{ scope: 'all' | string[], effectiveLocationIds, isAll, setScope }` ([plan 1:621](docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md:621), [plan 1:848](docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md:848)).
   - Required edit: consume §1’s exact hook shape and pass `scope` unchanged into `locationScopedKey`; send `effectiveLocationIds` to backend reads.

5. **BLOCKER — Provisional bypass permissions are undefined and contradict §1**

   - Plan evidence: tasks use `treasury.view_all_locations`, `finance.view_all_locations`, and `expenses.view_all_locations`, then defer deciding their names until deployment ([plan 3:917](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:917), [plan 3:1351](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:1351)).
   - Code evidence: none exists in the current permission list; the relevant block contains only existing user and POS/replenishment permissions ([RolesAndPermissionsSeeder.php:285](apps/api/database/seeders/RolesAndPermissionsSeeder.php:285), [RolesAndPermissionsSeeder.php:317](apps/api/database/seeders/RolesAndPermissionsSeeder.php:317)).
   - Required edit: decide the exact bypass policy before dispatch. Either seed named permissions in §1 before any consumer or pass `null` consistently. Add bypass and non-bypass tests per endpoint.

6. **BLOCKER — No-param financial reads leak restricted users’ other locations**

   - Plan evidence: cash position resolves and filters only “when `location_ids` present” ([plan 3:950](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:950)); instrument tasks use the same conditional pattern.
   - Code evidence: current cash position loads all active company repositories ([CashPositionController.php:61](apps/api/app/Modules/Treasury/Presentation/Controllers/CashPositionController.php:61)); maturing instruments likewise start company-wide ([MaturingInstrumentsController.php:37](apps/api/app/Modules/Treasury/Presentation/Controllers/MaturingInstrumentsController.php:37)).
   - Required edit: always resolve and apply the effective scope, including empty requested IDs. Explicitly define whether Unattributed remains visible to restricted users and test that rule.

### Execution safety

7. **BLOCKER — Repository assignment cannot safely occur “before deploy”**

   - Plan evidence: the deploy checklist requires owners to use Task 1’s new UI before the deploy containing that UI, then auto-runs schema and backfill migrations ([plan 3:1346](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:1346), [plan 3:1348](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:1348)).
   - Code evidence: current repository responses omit location and the current UI has no location field ([PaymentRepositoryController.php:319](apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:319), [AddRepositoryModal.tsx:34](apps/web/src/components/organisms/AddRepositoryModal/AddRepositoryModal.tsx:34)).
   - Required edit: split rollout into two deploy-safe waves: first ship assignment UI/exposure and verify completion; only a later push may ship the automatic backfill and location-filtered surfaces. Alternatively, make the backfill migration self-defer until a durable “assignment complete” condition is true.

8. **MAJOR — Migration ignores failed backfill commands**

   - Plan evidence: the wrapper calls `Artisan::call` for each company without checking its exit code ([plan 3:879](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:879)).
   - Code evidence: the proposed command has explicit failure exits for missing/invalid company ([plan 3:799](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:799)).
   - Required edit: check every exit code and throw on failure so the migration cannot be recorded as successful after a partial backfill. Test partial failure and rerun behavior.

9. **MAJOR — Test commands violate the by-path rule**

   - Plan evidence: repeated backend commands use only `--filter=...` ([plan 3:143](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:143), [plan 3:771](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:771)); frontend commands use broad substring/directory invocations ([plan 3:203](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:203), [plan 3:1337](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:1337)).
   - Code evidence: concrete existing test files use full paths, e.g. [RepositoryListPage.test.tsx](apps/web/src/features/treasury/RepositoryListPage.test.tsx) and [InstrumentListPage.test.tsx](apps/web/src/features/treasury/InstrumentListPage.test.tsx).
   - Required edit: replace every filter/subtree command with exact file paths.

### Missing verification

10. **MAJOR — Expense list bypasses the resolver**

   - Plan evidence: analytics resolves scope, but `ExpenseIndexQuery` is instructed to trust raw request `location_ids` directly ([plan 3:1136](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:1136)).
   - Code evidence: `ExpenseIndexQuery::build` currently accepts a raw `Request` and constructs its predicates directly ([ExpenseIndexQuery.php:21](apps/api/app/Modules/Expense/Application/Queries/ExpenseIndexQuery.php:21)).
   - Required edit: resolve scope in the list controller/request before constructing the query, pass typed effective IDs into the query object, and test out-of-scope and no-param restricted access.

11. **MAJOR — Unattributed reconciliation tests do not cover every promised surface**

   - Plan evidence: the plan promises Unattributed reconciliation for cash, instruments, upcoming payments, AR/AP, and expenses, but Task 13’s generic loop checks only cash position and aged receivables ([plan 3:1280](docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md:1280)).
   - Code evidence: the actual surfaces are separate implementations, including [MaturingInstrumentsController.php:24](apps/api/app/Modules/Treasury/Presentation/Controllers/MaturingInstrumentsController.php:24) and [ExpenseAnalyticsRequest.php:43](apps/api/app/Modules/Expense/Presentation/Requests/ExpenseAnalyticsRequest.php:43).
   - Required edit: add reconciliation assertions for échéancier/instrument lists, upcoming payments, aged payables, expense analytics, and export/list outputs.

---

## Plan 4 — Analytics and dashboards

**Verdict: REJECT**

### Cross-plan consistency

1. **BLOCKER — §4 invents a third, incompatible scope model**

   - Plan evidence: §4 expects `scope: { locationIds: string[] }`, `setScope(string[])`, and `isAll` causing no query parameter ([plan 4:46](docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md:46)).
   - Code evidence: §1 pins `scope: 'all' | string[]`, `effectiveLocationIds`, and `setScope('all' | string[])` ([plan 1:621](docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md:621)).
   - Required edit: replace §4’s contract verbatim with §1’s exact hook and key signatures.

2. **BLOCKER — Backend resolver semantics are reversed**

   - Plan evidence: §4 says an unrestricted user resolving an empty request receives `[]`, meaning no narrowing ([plan 4:63](docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md:63), [plan 4:67](docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md:67)).
   - Code evidence: §1’s resolver explicitly returns the full effective allowed ID list when the request is empty ([plan 1:387](docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md:387)); current membership semantics distinguish null=all from empty=deny-all ([LocationContext.php:184](apps/api/app/Modules/Company/Services/LocationContext.php:184)).
   - Required edit: remove all “empty means unfiltered” assumptions. Controllers must apply the returned IDs, including on no-param requests.

3. **BLOCKER — Consolidated rebalance widget depends on a nonexistent §2 endpoint**

   - Plan evidence: Task 6 declares a §2 “rebalancing endpoint” dependency ([plan 4:319](docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md:319)).
   - Code evidence: §2 Task 7 creates only frontend files and no endpoint ([plan 2:431](docs/superpowers/plans/2026-07-16-multiloc-2-inventory-visibility.md:431)); current Inventory routes contain none ([Inventory routes.php:55](apps/api/app/Modules/Inventory/Presentation/routes.php:55)).
   - Required edit: align Task 6 to a real pinned endpoint or amend §2 to produce one.

### Code reality

4. **BLOCKER — Z-report query uses the wrong table/column name**

   - Plan evidence: Task 5 filters a terminal relation using bare `company_id` and then `location_id` ([plan 4:271](docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md:271)).
   - Code evidence: the actual current code scopes through the terminal relation ([ReportController.php:254](apps/api/app/Modules/POS/Presentation/Controllers/ReportController.php:254)), and POS routes consistently refer to the terminal table as `pos_terminals` ([POS routes.php:49](apps/api/app/Modules/POS/routes.php:49)); `ScopedExists` for terminal IDs also targets `pos_terminals` ([ReportController.php:59](apps/api/app/Modules/POS/Presentation/Controllers/ReportController.php:59)).
   - Required edit: use Eloquent-qualified `pos_terminals.company_id` and `pos_terminals.location_id`, or scope via a prevalidated terminal-ID subquery. Add SQL execution coverage on PostgreSQL.

5. **MAJOR — Sales chart violates the plan’s no-float money rule**

   - Plan evidence: Task 2 treats `SalesByLocationChart` as already correct except for colors ([plan 4:177](docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md:177), [plan 4:188](docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md:188)).
   - Code evidence: it converts `gross_sales` with `Number(...)` ([SalesByLocationChart.tsx:30](apps/web/src/features/owner-dashboard/components/SalesByLocationChart.tsx:30)).
   - Required edit: explicitly address chart-library numeric conversion at the visualization boundary or document an approved bounded conversion strategy. Add large-value/decimal precision coverage rather than declaring the component correct.

### Missing verification

6. **BLOCKER — POS filter tests cover only a minority of the eight methods**

   - Plan evidence: Task 1 changes all eight methods but directly tests summary, product, and one F&B path ([plan 4:104](docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md:104)).
   - Code evidence: the service has separate query graphs for category, period, cashier, discounts, customers, and F&B ([PosAnalyticsService.php:78](apps/api/app/Modules/POS/Application/Services/PosAnalyticsService.php:78), [PosAnalyticsService.php:191](apps/api/app/Modules/POS/Application/Services/PosAnalyticsService.php:191), [PosAnalyticsService.php:252](apps/api/app/Modules/POS/Application/Services/PosAnalyticsService.php:252)).
   - Required edit: add at least one L1/L2 isolation assertion for every method and each internal query branch, including summary payment breakdown and void count, discount subqueries, customer top list, and all F&B subqueries.

7. **MAJOR — “DTO byte-identical” can pass while behavior is partially unfiltered**

   - Plan evidence: the principal F6 gate is a generated-types zero-diff ([plan 4:146](docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md:146)).
   - Code evidence: individual methods contain multiple independent queries; for example summary has receipts, voided count, and payments queries ([PosAnalyticsService.php:21](apps/api/app/Modules/POS/Application/Services/PosAnalyticsService.php:21), [PosAnalyticsService.php:37](apps/api/app/Modules/POS/Application/Services/PosAnalyticsService.php:37), [PosAnalyticsService.php:43](apps/api/app/Modules/POS/Application/Services/PosAnalyticsService.php:43)).
   - Required edit: retain the no-diff gate, but add query-branch behavioral assertions so a forgotten predicate cannot pass.

8. **MAJOR — Branch leaderboard still leaks scope through live-sales state**

   - Plan evidence: Task 3 scopes only the two `useSalesByLocation` calls ([plan 4:201](docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md:201)).
   - Code evidence: leaderboard activity also comes from unfiltered `useLiveSales(canFetch)` and feeds `open_shifts_by_location` into the rows ([BranchLeaderboard.tsx:31](apps/web/src/features/owner-dashboard/components/BranchLeaderboard.tsx:31), [BranchLeaderboard.tsx:35](apps/web/src/features/owner-dashboard/components/BranchLeaderboard.tsx:35)).
   - Required edit: scope `useLiveSales` too, or filter its location map to the effective IDs. Add a restricted-scope test proving excluded locations cannot affect activity indicators.

9. **MAJOR — Task 6 lacks exact endpoint shapes and test paths**

   - Plan evidence: widget contracts are prose-only, and commands use `<widget test path>` placeholders ([plan 4:316](docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md:316), [plan 4:324](docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md:324)).
   - Code evidence: existing cash and instrument endpoints have materially different response shapes—cash returns `data.groups`/`grand_total` ([CashPositionController.php:136](apps/api/app/Modules/Treasury/Presentation/Controllers/CashPositionController.php:136)), while maturing instruments return rows plus bucket metadata ([MaturingInstrumentsController.php:102](apps/api/app/Modules/Treasury/Presentation/Controllers/MaturingInstrumentsController.php:102)).
   - Required edit: pin each hook, endpoint URL, query parameters, response type, aggregation grain, route target, and exact test path before dispatch.

---

## Cross-cutting

1. **BLOCKER — Three incompatible scope contracts exist**

   - §1: `'all' | string[]`, empty resolver request returns effective IDs.
   - §3: `locationIds: string[]`, empty means All.
   - §4: object-shaped scope, empty resolver result means unfiltered.
   - Required edit: copy §1’s pinned contracts verbatim into §2–§4 and delete all alternate semantics.

2. **BLOCKER — Restricted-user access is optional on several downstream endpoints**

   §2 movements, §3 cash/instruments/reports, and §4 analytics rely on parameters being present before applying scope. Direct requests without `location_ids[]` therefore remain company-wide. Every location-sensitive endpoint must always invoke the resolver and apply its effective IDs.

3. **BLOCKER — Financial rollout ordering is impossible under auto-deploy**

   The repository-assignment UI does not exist before the push that auto-runs the backfill. Split §3 into assignment and attribution deploy waves, with a durable completion gate between them.

4. **BLOCKER — §4 consumes a rebalancing endpoint that §2 does not produce**

   Pin one shared API contract or make §4 explicitly consume the matrix endpoint.

5. **MAJOR — Verification commands are not uniformly path-runnable**

   Plan 1 contains “adjust to the real path”; plan 3 repeatedly uses filters and directory-wide frontend tests; plan 4 contains placeholder widget paths and a full preflight instruction. Replace all with exact file paths and scoped static-analysis commands before autonomous dispatch.

Codex session ID: 019f6a1e-03b3-78a1-ba67-e1722c192b71
Resume in Codex: codex resume 019f6a1e-03b3-78a1-ba67-e1722c192b71
