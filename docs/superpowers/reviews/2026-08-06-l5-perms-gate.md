# Gate record — `fix/l5-finance-perms-and-taxconfig-seed` (W-6 D5 "Option B split" + W-X)

Reviewer: tenancy-authz-reviewer (adversarial merge gate). Worktree
`/Users/houssamr/Projects/syneriva/apps/erp.fix-l5-perms`, base `695f6814d`,
commits `863b2621d` / `f503bf919` / `565e587c6`. Not committed. Gate only — no code changed.

**VERDICT: spec ✅ / quality CHANGES-REQUESTED → APPROVE-WITH-FIXES**

The ruling is implemented correctly and the headline deploy claim is empirically true.
Four IMPORTANT defects sit in the affordance layer (sidebar, widget, VAT mutate-without-read)
plus two in-lane misses the research memo explicitly asked for.

---

## 1. HIGHEST-PRIORITY PROBE — reseed semantics on existing tenants: **PASSES**

`RolesAndPermissionsSeeder.php:490` uses
`$role->syncPermissions($roleName === 'admin' ? Permission::all() : $permissions)` — **sync, not
additive**. Grants removed from `rolePermissionGrants()` ARE revoked on re-run. The deploy note
in `f503bf919` is accurate.

Proven empirically (two throwaway probe tests, both green, both deleted; worktree left clean):

1. **With team context** (`setPermissionsTeamId($tenant->id)` before seeding): seed → simulate
   the old state (`manager->givePermissionTo('reports.financial')`, `viewer->givePermissionTo('reports.operational')`,
   both `reports.view`) → re-run seeder → all three grants gone; a real `manager` user hitting
   `GET /api/v1/reports/trial-balance` gets **403**.
2. **Without team context** (`setPermissionsTeamId(null)` — the realistic
   `tenants:run db:seed --class=RolesAndPermissionsSeeder` console path): identical result.
   `manager` → 403 on `/reports/trial-balance`, **200** on `/reports/aged-receivables`.

Why it works despite `Role::firstOrCreate` bypassing Spatie's team-stamping static `create()`:
seeded role rows carry `tenant_id = NULL` (dumped live: all 7 roles NULL), and
`vendor/spatie/laravel-permission/src/Models/Role.php:172-181` `findByParam()` matches
`whereNull(teamsKey) OR teamsKey = <current>`, so a NULL-team role resolves under any team.
A tenant cannot shadow a builtin role either — `RoleController.php:191` calls Spatie's static
`Role::create()`, which throws `RoleAlreadyExists` on a name collision with the NULL-team row.

---

## 2. Findings

### IMPORTANT

**I-1 — `manager` can MUTATE VAT periods it cannot READ (probe 2 — real, and this branch created it).**
`apps/api/app/Modules/Taxation/routes.php:83,85` (index/show → `can:reports.financial`) vs
`:84,86,87,88` (generate/close/reopen/file → `can:reports.manage`), against
`apps/api/database/seeders/RolesAndPermissionsSeeder.php:536` which keeps `'reports.manage'` on
`manager` while removing `'reports.financial'`.
*Failure scenario:* a branch manager `POST /api/v1/vat/periods/{id}/file` → **200** (files the VAT
declaration to the tax authority); `GET /api/v1/vat/periods` → **403**; `GET /vat/reports/{id}/summary`
→ **403** (`Taxation/routes.php:94`); the FE page `/finance/vat-periods` silently redirects to
`/dashboard` (`routes/index.tsx:2049` `moduleKey="reports"` → now `reports.financial`;
`RequirePermission.tsx:73-75`). A fiscal-lifecycle mutation with zero read-back, on the launch tenant.
*Ruling:* **not defensible as out-of-scope.** The research memo's Option B says "`reports.manage`
… unchanged" (memo:113) under the stated premise that `manager` KEEPS `reports.financial`
(memo:169-172, sub-decision 2). The owner then took sub-decision 2 the other way, which
invalidates the premise. Fix in-lane: drop `'reports.manage'` from the manager grant list
(`RolesAndPermissionsSeeder.php:536`) — one line, zero new permission rows, consistent with
"manager gets OPERATIONAL ONLY" — or obtain an explicit owner ruling that a manager may file a
VAT declaration blind. Do not merge silently.

**I-2 — Sidebar not aligned: five dead finance-report nav items that bounce to the dashboard.**
`apps/web/src/components/organisms/Sidebar/Sidebar.tsx:292-299` — `trialBalance`, `profitLoss`,
`balanceSheet`, `agedReceivables`, `agedPayables` all still carry `permission: 'accounts'`
(`usePermissions.ts:44` `accounts: ['accounts.view']`; `permissionsMap.generated.ts:7`
`accounts.view: accountant, admin, manager, viewer`), while the routes they link to now require
`reports.financial` / `reports.operational` (`routes/index.tsx:1969,1979,1989,1999,2009`).
*Failure scenario:* a `viewer` sees all five items in the sidebar, clicks "Trial Balance", and is
**silently `<Navigate to="/dashboard">`**-ed (`RequirePermission.tsx:73-75`; nothing consumes the
`state.permissionDenied` flag — grepped repo-wide, zero consumers), no message. Same for
`manager` on the three `reports.financial` pages. The commit aligned `FinanceHubPage.tsx` cards
but not the sidebar, so the two affordance surfaces now disagree with each other.

**I-3 — Inverse defect + a factually wrong code comment: `manager` loses nav access to pages it IS allowed to open.**
`apps/web/src/hooks/usePermissions.ts:34-38` sets `reports: ['reports.financial']` with the comment
"VAT period reads (**the only consumer of this module key** — vat-periods/vat-report)". That is
false: `Sidebar.tsx:290` (`treasuryOverview` → `/finance/overview`) and `Sidebar.tsx:291`
(`cashMovements` → `/finance/cash-movements`) also use `permission: 'reports'`.
*Failure scenario:* `manager` holds `reports.operational`, the routes at `routes/index.tsx:1929,1940`
and the APIs both allow them — but the two sidebar entries are hidden, so the only path in is the
finance hub card. Either the comment or the mapping is wrong; the two sidebar entries need their own
`reports.operational` mapping key.

**I-4 — `/finance/overview` shows a `manager` six fabricated ZERO financial tiles after a 403.**
`apps/web/src/features/finance/components/FinanceWidget.tsx:36` branches on `isLoading` only; on
error `data` is `undefined` and lines `:63,71,80,88,96,104` render
`formatMoney(data?.total_assets ?? '0')` etc. The hook (`hooks/useFinanceSummary.ts`) calls
`GET /reports/finance-summary` (`features/finance/api.ts:215`), now `can:reports.financial`
(`Accounting/Presentation/routes.php:195-196`).
*Failure scenario:* a `manager` opens `/finance/overview` (allowed — route gate is now
`reports.operational`), the summary call 403s, and the page presents "Total Assets 0.000 /
Total Liabilities 0.000 / Net Income MTD 0.000 / AR 0.000 / AP 0.000" as if real, plus a
"View reports" link to `/finance/balance-sheet` that redirects to `/dashboard`.
**This exposure is NEW**: at base the page gate was `reports.view`, which only `admin` held
(`permissionsMap.generated.ts:230` at base), so no manager could reach it.
Probe 4's specific claim holds only for the CHART — `OwnerChart.tsx:28-29` checks `isError`
before `isEmpty` and renders `reports:ownerDashboard.error`, and the 403 does reach `isError`
(the axios interceptor at `apps/web/src/lib/api.ts:185-187` only `console.error`s and re-rejects;
the 403 envelope carries `error.message`, `apps/api/bootstrap/app.php:172+`). The sibling widget
on the same page does not degrade.

**I-5 — `/finance/ledger` left mismatched, and the memo named it as in-scope for this commit.**
`apps/web/src/routes/index.tsx:1958` gates on `journal.view` while the API
`GET /ledger` requires `ledger.view` (`Accounting/Presentation/routes.php:157-159`);
`GeneralLedgerPage.tsx:16` → `useLedger` → `features/finance/api.ts:127` `/ledger`.
`journal.view` = accountant/admin/manager/viewer; `ledger.view` = accountant/admin.
**Confirmed genuinely pre-existing** — byte-identical at `695f6814d` (`git show 695f6814d:apps/web/src/routes/index.tsx`
lines 1956-1964). But the research memo:173-175 says "**all six `/finance/*` routes, `/finance/ledger`,
the `FinanceHubPage` Treasury card and the `usePermissions.ts:35` nav alias must be aligned … in
the same commit — that FE/API agreement is the actual D5 fix." Three of the four were done.
*Failure scenario:* `manager`/`viewer` click "General Ledger" (hub card `FinanceHubPage.tsx:104-109`
`permissionModule: 'finance'` = `accounts.view|journal.view`; sidebar `Sidebar.tsx:294`), the page
renders, `/ledger` 403s.

**I-6 — Three staging E2E "TRIPWIRE D5" specs now assert the pre-fix defect as green.**
- `apps/web/e2e/money-campaign/finance-permissions.spec.ts:186-198` — asserts the accountant is
  **403** on `/reports/trial-balance|balance-sheet|profit-loss|aged-receivables|aged-payables|finance-summary`
  and `/ledger`. Post-fix the accountant is 200 on all seven → **the spec fails**.
- `finance-permissions.spec.ts:344-347` — asserts `a[href^="/finance/overview"]` has count **0**
  for the accountant. Post-fix the accountant holds `reports.operational` → the card renders → **fails**.
- `w7-multilocation.spec.ts:706-710` — asserts the accountant is **403** on `/reports/cash-movements` → **fails**.
Not a CI break (`playwright.smoke.config.ts:8-9` restricts CI to `e2e/smoke/*.smoke.ts`;
`.github/workflows/smoke-test.yml:53`), but the next money-campaign run will report three false
regressions against the fix. Update or ticket before the campaign re-runs.

### minor

- **m-1** — Deploy notes omit the memo's own warning (memo:167-170): `syncPermissions` **clobbers
  any tenant-side role customisation** made through `RoleController::update`. Add "verify no
  tenant-side role customisation exists" to the reseed runbook step.
- **m-2** — PHPStan level-8 `missingType.iterableValue` on
  `tests/Feature/Taxation/TaxConfigManageSeededRoleGrantHttpTest.php:88` (`payload(): array`).
  Not CI-blocking (`phpstan.neon:6-7` analyses `app/` only) but violates rule 6 on new code.
  `app/` itself is clean for the changed paths.
- **m-3** — `RolesAndPermissionsFinanceReportSplitTest.php:82` is named
  `test_reports_view_is_still_seeded_but_gates_nothing_…` but only asserts role grants; nothing
  pins "no route checks `reports.view`". A grep/architecture assertion would make the deprecation
  claim enforceable.
- **m-4** — `permissionsMap.generated.ts` was **stale at base** (missing
  `fiscal.refunds.manage_dead_letters`, which `RolesAndPermissionsSeeder.php:431/537` already
  granted at `695f6814d`), i.e. base would have failed the drift guard (`.github/workflows/ci.yml:1019`,
  `scripts/preflight.sh:147`). This branch incidentally repairs it — do not attribute that line to the ruling.
- **m-5 — deprecation sweep for `reports.view` (next release).** Remaining consumers:
  `apps/api/database/seeders/PermissionSeeder.php:136,195,223` (central bootstrap; it creates the
  permission itself, so harmless — becomes a dead grant on `Administrator`/`Sales Manager`/`Accountant`);
  `apps/web/src/hooks/permissionsMap.generated.ts:230`;
  `apps/web/src/hooks/__tests__/usePermissions.uiAliases.test.tsx:71`;
  `apps/api/tests/Feature/Seeders/RolesAndPermissionsFinanceReportSplitTest.php:93-96`;
  **`apps/web/e2e/money-campaign/w8-isolation.spec.ts:642`** — requires `'reports.view'` in the
  admin permission list, so it breaks the moment the permission row is deleted.
  `apps/pos/src/components/pos/TodaySalesPanel.tsx:222` is `t('reports.view')`, an **i18n key**, not
  a permission — do not touch.

---

## 3. Verified-correct (no finding)

- **Route mapping completeness.** Repo-wide grep: **no route anywhere checks `reports.view`**
  (only comments/seeder/tests remain). All 16 base occurrences under `apps/api/app` at `695f6814d`
  are accounted for: 8 Accounting routes, 2 Document routes, 2 Taxation VAT-period reads, 4 doc-comments.
  Nothing outside the ruling was reclassified: `vat/reports/*` (`Taxation/routes.php:93-96`) was
  already `reports.financial` at base, the Owner-Reporting MVP block
  (`Accounting/Presentation/routes.php:200-228`) stays on `can:dashboard.owner`, POS X/Z reports
  (`POS/routes.php:98-115`) and Inventory counting reports are untouched. Treasury statements are
  `bank-statements.*`, not `reports.*`.
- **Ambiguity #1 backed by the memo.** VAT reads → `reports.financial` is exactly memo:107
  ("`reports.financial` → trial balance, P&L, balance sheet, finance summary, **VAT reads**").
- **W-X byte-match.** `taxation.tax_configurations.manage` in
  `Taxation/routes.php:22,26,28,30` == `RolesAndPermissionsSeeder.php:384`. Granted to accountant
  (`:757`) + admin (via `Permission::all()`); viewer/manager/cashier denied — asserted at both the
  seeder level and over HTTP (`TaxConfigManageSeededRoleGrantHttpTest.php:98-124`, real 201/403).
- **permissionsMap regen is faithful.** Re-ran `php artisan permissions:export-frontend-map` to a
  temp path → **byte-identical** to the committed file, hash included.
- **Rule-12 middleware.** All three touched groups use
  `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`
  (`Accounting/Presentation/routes.php:25`, `Document/Presentation/routes.php:33`, `Taxation/routes.php:15`).
- **No tenancy/module-gating risk.** No `module:` guard, `verticals.php`, connection pinning,
  queue-context or money/quantity surface is touched by this diff.
- **Test quality.** New tests use `RefreshDatabase` + real models + the real seeder + real HTTP,
  and every authz test exercises the DENY path
  (`FinanceReportPermissionSplitHttpTest.php:104-113,131-135,147-155`,
  `TaxConfigManageSeededRoleGrantHttpTest.php:113-124`,
  `RolesAndPermissionsFinanceReportSplitTest.php:49-80`). No `assertTrue(true)`, no mocked SUT.
  Gap: no test covers the RE-SEED/upgrade path (I ran it manually above) — worth adding.
- **Pint** clean on all changed API paths.

## 4. Blast radius actually executed (PHPUnit by path only; full suite never run)

| Suite | Result |
|---|---|
| `tests/Feature/Seeders/RolesAndPermissions{FinanceReportSplit,TaxConfigManageGrant}Test.php` | 9 passed |
| `tests/Feature/Accounting/Reports/FinanceReportPermissionSplitHttpTest.php` + `tests/Feature/Taxation/TaxConfigManageSeededRoleGrantHttpTest.php` | 8 passed |
| `tests/Feature/Accounting/Reports/` + `CashMovementsReportTest` + `Treasury/LocationReconciliationTest` | 80 passed |
| `Identity/{RBACTest,RoleAuthorizationTest,PermissionCatalogVerticalFilterTest}`, `Console/ExportFrontendPermissionsMapCommandTest`, `Seeders/RolesAndPermissions{LoyaltyEnroll,RefundReverseGrant}Test` | 28 passed |
| web: `routes.test.tsx`, `CashMovementsRoute.test.tsx`, `FinanceHubPage.test.tsx`, `usePermissions.uiAliases.test.tsx` | 27 passed |
| 2 throwaway reseed probes (deleted) | passed |

**125 API + 27 web assertions-bearing tests, 0 failures.**

`DemoPharmacySeederTest::test_seeds_gl_consistent_partner_balances` fails
(`SUPP-PAYABLE-01 payable_balance < 0`) — **confirmed pre-existing**: it reproduces identically
after `git checkout 695f6814d -- database/seeders/RolesAndPermissionsSeeder.php` (restored;
worktree clean). Unrelated to permissions.

## 5. Before merge

Fix **I-1** (drop `reports.manage` from `manager` or escalate to the owner), **I-2**/**I-3**
(sidebar + the false comment), **I-4** (FinanceWidget error state); ticket **I-5**, **I-6**, m-1, m-5.

---

# Fix-round re-verify (2026-08-07)

Commits re-verified: `5eac6b1d2` (gate record), `2dc65e1a0` (I-1), `77779172b` (I-2/I-3/I-4/I-5),
`368298dea` (I-6). Scope limited to the round-1 findings, per the coordinator. Worktree clean
after re-verification (two throwaway probes deleted).

**VERDICT: CLEAR TO MERGE after one one-line residual (R-1). All six IMPORTANT findings and all
three minors are genuinely closed.**

## I-1 — CLOSED (verified at three levels)

- `RolesAndPermissionsSeeder.php:536-546` — `'reports.manage'` removed from the manager grant list;
  accountant keeps it (`:757`). The manager entry is a key of `rolePermissionGrants()`, which is
  exactly the array `createRoles()` feeds to `syncPermissions()` at `:490` — so the removal IS
  inside the sync-covered set.
- **Existing-tenant reseed proven empirically.** Throwaway probe (deleted): tenant seeded in
  console context (`setPermissionsTeamId(null)`), manager re-granted `reports.manage` +
  `reports.financial` to simulate the old state, seeder re-run → both revoked, accountant's
  `reports.manage` intact, and a real manager user gets **403 on
  `POST /api/v1/vat/periods/generate`** while still **200 on `GET /api/v1/reports/aged-receivables`**.
- `tests/Feature/Taxation/VatPeriodManagerCannotMutateTest.php` — 5 tests green (4 manager-denied
  on generate/close/reopen/file, 1 accountant-still-allowed with `assertNotSame(403)` on the two
  business-state-422 paths, which is the right assertion for a permission gate).
- `RolesAndPermissionsFinanceReportSplitTest.php:49,67` now pins
  `accountant.reports.manage === true` / `manager.reports.manage === false`.
- **Map regen picked it up**: `permissionsMap.generated.ts:228` is now
  `'reports.manage': ['accountant', 'admin']`, and re-running
  `php artisan permissions:export-frontend-map` produces a **byte-identical** file (hash included),
  so the CI drift guard is satisfied.

## I-2 / I-3 — CLOSED (per-role matrix measured, not asserted)

`Sidebar.tsx:293-306` re-cut 1:1 to the routes; `usePermissions.ts:36-46` gives
`treasuryOverview`/`cashMovements` their own `reports.operational` key, adds `reports.financial`
and `ledger.view` identity keys, and the false "only consumer" comment is corrected.
`canAccessModule` returns `true` for an unmapped key (`usePermissions.ts:128`), so the identity
keys were required — they are present.

Measured with a throwaway probe that drives the **real** `usePermissions` against the real
`authStore` per seeded role (deleted afterwards) — this is stronger than `Sidebar.test.tsx`,
which mocks `canAccessModule`:

| role | finance nav entries visible |
|---|---|
| admin | all 11 |
| accountant | all 11 |
| **manager** | treasuryOverview, cashMovements, agedReceivables, agedPayables (**operational only**) + chartOfAccounts, journalEntries |
| **viewer** | chartOfAccounts, journalEntries **only** — zero report entries, no ledger |
| cashier | none |

Exactly the required matrix: manager sees the operational entries and **no** `reports.financial`
entry, no General Ledger, no VAT reporting (coherent now that I-1 removed `reports.manage`).
`chartOfAccounts`/`journalEntries` are correctly retained — their routes gate on
`accounts.view` (`routes/index.tsx:1949`) and `journal.view` (`:2019`), both held by manager and
viewer. `Sidebar.test.tsx` (41 tests) green, including the re-pointed
`reports.operational` denial case.

## I-4 — CLOSED

`FinanceWidget.tsx:47-63` returns a `QueryError` block **before** the tile grid, so the
`data?.x ?? '0'` fallbacks at `:81+` are unreachable on the error branch. i18n key verified present
in **both** locales — `apps/web/src/locales/en/finance.json` `widget.loadError` = "Unable to load
the finance summary", `apps/web/src/locales/fr/finance.json` = "Impossible de charger le résumé
financier"; the retry label `actions.tryAgain` is `QueryError.tsx:85`, an existing shared key.
`FinanceWidget.error.test.tsx` (2 tests) green and asserts the right things — the error text
renders, `container.textContent` contains **no** `0.000` and no tile label, and the retry calls
`refetch`. The pre-existing `FinanceWidget.test.tsx` (8) and `FinanceWidget.currency.test.tsx` still pass.

## I-5 — CLOSED at the route and sidebar; **residual R-1 on the hub card**

`routes/index.tsx:1959` is now `permission="ledger.view"`, and `Sidebar.tsx:296` `generalLedger`
moved from `'accounts'` to `'ledger.view'` — so the viewer (holds `journal.view`, lacks
`ledger.view`) **no longer sees the sidebar General Ledger item** (confirmed in the matrix above).

**R-1 (IMPORTANT, residual, one line) — the finance HUB still offers the same dead card.**
`apps/web/src/features/finance/pages/FinanceHubPage.tsx:106-112` keeps
`permissionModule: 'finance'` on the General Ledger card, and `finance` maps to
`['accounts.view','journal.view']` (`usePermissions.ts:48`), both held by manager and viewer.
*Failure scenario:* a viewer opens `/finance`, sees the "General Ledger" card, clicks, and is
**silently `<Navigate to="/dashboard">`**-ed (`RequirePermission.tsx:73-75`). The card was
`permissionModule: 'finance'` at base `695f6814d` too, so the affordance is pre-existing — but
I-5 changed its failure mode from "page renders, shows a QueryError from the API 403" to a silent
teleport, which is worse, and it is the exact F1 affordance class this fix round closed on the
sidebar. One-line fix: `permission: 'ledger.view'` (drop `permissionModule`), mirroring what the
sibling report cards already do in the same file.
*Nit, not a defect:* the `journalEntries` hub card and sidebar entry use the broader
`accounts.view|journal.view` while the route gates on `journal.view` alone — harmless today
because the two role sets are identical (`permissionsMap.generated.ts:7,124`).

## I-6 — CLOSED

All three specs flipped to the fixed matrix with the tripwire comments **retained and annotated
"FIXED 2026-08-06/07"**, not deleted:
- `e2e/money-campaign/finance-permissions.spec.ts:143` (title), `:171-182` (comment rewritten),
  `:195-198` — the seven-path loop now asserts **200** for the accountant.
- `finance-permissions.spec.ts:335-351` — the `/finance/overview` hub card assertion flipped from
  `toHaveCount(0)` to `toBeVisible`.
- `w7-multilocation.spec.ts:699-713` — accountant on `/reports/cash-movements` flipped 403 → **200**.
No Playwright run performed (per instruction). Swept the rest of the campaign for collateral: the
viewer block (`finance-permissions.spec.ts:115-140`) only touches journal-entries;
`permissions.spec.ts` has zero `/reports`, `/vat/periods` or `/ledger` references; the two
remaining VAT specs run as `owner`/`cashier` (`w7-empty-states.spec.ts:200`,
`wx-vat-periods.spec.ts:234-256`) so no assertion flips — **but their comments are now stale**
("manager/accountant have manage"; "reads require reports.view"). Fold into the m-5 deprecation
ticket; not blocking.

## Minors

- **m-1 CLOSED (with a caveat).** The `syncPermissions`-clobbers-tenant-customisation warning is
  spelled out in `2dc65e1a0`'s message, including "reseed once, after all three fixes land, not
  per-commit". Caveat: it lives only in a commit message, not in a deploy runbook doc — whoever
  executes the reseed reads the runbook, not `git log`. Worth one line in the lane's deploy checklist.
- **m-2 CLOSED.** PHPStan clean on the new/changed test files and on `app/Modules/Taxation`
  (`[OK] No errors`).
- **m-3 CLOSED.** Renamed to
  `test_reports_view_permission_row_still_exists_and_is_only_explicitly_granted_to_admin`
  (`RolesAndPermissionsFinanceReportSplitTest.php:103`) — the name now matches what it asserts.
- **m-4 / m-5** unchanged: m-4 was informational; m-5 remains ticketed (add the two stale VAT-spec
  comments above to it).

## Gates re-run

| Gate | Result |
|---|---|
| `VatPeriodManagerCannotMutateTest` + 3 seeder/taxation suites | 16 passed |
| Round-1 API regression set (Accounting reports, CashMovements, LocationReconciliation, RBAC, RoleAuthorization, PermissionCatalogVerticalFilter, ExportFrontendPermissionsMap, 2 seeder-grant suites) | **108 passed** |
| web: Sidebar, FinanceWidget ×3, routes, CashMovementsRoute, FinanceHubPage, usePermissions aliases, modules.drift | **84 passed** |
| 2 throwaway probes (reseed revocation of `reports.manage`; per-role sidebar matrix) | passed, deleted |
| `permissions:export-frontend-map` re-run vs committed file | byte-identical |
| Pint (seeder + new tests) | pass |
| `tsc --noEmit` (apps/web) | clean |
| ESLint on the 5 touched web files | 0 errors (4 pre-existing warnings at `Sidebar.tsx:621/635/680`, untouched lines) |

**Before merge:** close R-1 (one line in `FinanceHubPage.tsx:111`). Everything else is clear.
