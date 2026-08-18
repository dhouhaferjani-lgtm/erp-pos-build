# M1 / Wave 1 — adversarial merge-gate register (round 2)

Range reviewed: `7d85232cc54abd6a6b2135f476205ab434e71a66..82ddc74b0` (14 commits). Repairs under review: `cca51e7e2` (Phase 1.1.13) + `82ddc74b0` (Phase 1.1.14).

Lenses: **frontend-conventions** ✔ applied · **tenancy-authz** ✔ applied · **treasury** ✔ applied (currency/no-aggregate half; no GL, payment or partial-write path exists in this read-only wave, so that half does not apply) · **general** ✔ applied.

## Round-1 findings — verification of the repairs

| # | Round-1 finding | Status | Evidence I ran/read |
|---|---|---|---|
| 1 | P1 legacy `receipt_type` axis regression | **CLOSED — CONFIRMED** | `ReceiptController.php:117-119` now passes the legacy param straight through (`where('receipt_type', …)`); `:120-147` adds the legacy-return union for REFUND and excludes it from the SALE set; `receipt_type` restored to the DTO (`ReceiptListItemData.php:19`). `CACHE_STORE=array ./vendor/bin/phpunit tests/Feature/POS/ReceiptReturnFlowTest.php` → `OK (20 tests, 115 assertions)`. BT-2's mandated legacy-axis assertion now exists (`ReceiptIndexTypeFilterTest.php:28-46`), and I verified the fixture is faithful to production: `PosCoreReceiptProjection::resolveReceiptType()` (`:571-578`) does project fiscal REFUND/VOID as `ReceiptType::Return`. |
| 2 | P1 PHPStan level 8 — 8 errors | **CLOSED — CONFIRMED** | `./vendor/bin/phpstan analyse app/Modules/POS --memory-limit=3G` → `[OK] No errors` (269 files). The nullable-Carbon cases are now guarded by `dateBoundary()` (`ReceiptController.php:214-222`, `ReceiptFilterOptionsController.php:126-134`). |
| 3 | P1 FT-14/FT-15 not asserted behaviourally | **CLOSED — CONFIRMED** | `Sidebar.test.tsx:662-736` now *renders* the sidebar under mocked `canAccessModule` and asserts exact visible `/pos/*` href sets for accountant and cashier, plus GATE-5's "zero-child group must not render". I verified the cashier grant fixture is faithful to the seeder (`permissionsMap.generated.ts:170-189`: cashier holds `manage_shifts`/`operate_terminal`/`view_receipts`, not `manage_tables`/`manage_terminals`/`view_reports`). `pnpm vitest run` on the four suites → `69 passed`. |
| 4 | P2 preflight never completed | **SUBSTANTIALLY CLOSED** (downgraded to P3-2 below) | Pint drift re-verified as genuinely outside the lane: `./vendor/bin/pint --test` over every changed API path names only `tests/Feature/POS/ZReportListTest.php`, and `git diff --name-only` confirms that file is untouched. I ran every stage preflight would have run, independently: PHPStan ✔, PHPUnit by path ✔, `typescript:transform` drift → tree clean ✔, `permissions:export-frontend-map` drift → tree clean ✔, `pnpm typecheck` ✔, scoped ESLint → **0 errors** ✔, `audit-tanstack-keys.mjs` → `0 new` ✔, `audit-design-system.mjs` → `737 acknowledged, 0 new, 0 stale` ✔. |
| 5 | P2 BT-16 asserted the wrong database | **CLOSED — CONFIRMED** | `PosReceiptsIndexMigrationStructureTest.php` now queries `pg_indexes`, asserts both `USING btree` column lists and `WHERE (training_flag = false)`, re-runs `up()` (idempotency), exercises `down()`, and restores. Skips cleanly off PG. Added to the pgsql merge-gate `--filter` (`.github/workflows/ci.yml:629`). |
| 6 | P2 `filter-options` materialised every receipt in PHP | **CLOSED — CONFIRMED** | `ReceiptFilterOptionsController.php:77-92` reduces in SQL via `ROW_NUMBER() OVER (PARTITION BY cashier_id ORDER BY posted_at DESC, id DESC)` + `fromSub(...)->where('snapshot_rank', 1)`, ordered `cashier_name`, `cashier_id` per S-7. `ReceiptFilterOptionsTest::test_cashier_options_are_deduplicated_by_the_database` locks it against the query log. |
| 7–15 | P3s | **7, 8, 10, 11, 12, 15 CLOSED; 9, 13, 14 partially** | `common:notAvailable` present EN+FR ✔; `hasTenantScope === false` now renders a dedicated no-scope state with **no** requests issued (`ReceiptListPage.tsx:48-56`, asserted at `ReceiptListPage.test.tsx:171`); BT-11 is now DB-backed against the real seeder and refuses `pos.process_returns` ✔; the timezone/hydration race is fixed by gating on `activeCompany` ✔; the `LIKE` escaping now has a test ✔; handback commit list reconciled ✔. |

New backend suite: `46 tests, 229 assertions, 1 skipped` (the PG-only migration test), all green.

## P2 — fix before merge

**1. The brief's mandated `tests/Feature/Compliance/` route-gate coverage for A-1 and OP-23 does not exist, and the handback reports the item as DONE without disclosing the omission. CONFIRMED.**

Brief Addendum A(a) item 3, sub-bullet 2 (binding, overrides the spec): *"Add route-gate coverage for both fraud routes alongside the A-1 route/permission tests under `tests/Feature/Compliance/` and the FE `RequirePermission` tests."* The preflight path set names `tests/Feature/Compliance` for precisely this reason (brief §7 / spec §7.4).

- `git diff --name-only 7d85232cc..HEAD | grep tests` returns **no** file under `apps/api/tests/Feature/Compliance/`. `grep -rn "fraud-settings.view|fraud-alerts.view" apps/api/tests` hits only pre-existing `FraudSettingsControllerCashControlsTest.php` / `FraudSettingsControllerContractTest.php`, neither of which exercises the accountant.
- The FE half is also unmet in the form asked for: `apps/web/src/routes/__tests__/ReceiptPermissionParity.test.ts` is a **source-string grep** over `routes/index.tsx` / `ComplianceExportPage.tsx` — it never mounts `RequirePermission`. That is the same class of evidence round 1 rejected as P1 for FT-14/FT-15.
- The handback declares *"A-1/GATE-3/GATE-5/OP-23 — DONE"* and lists the deviation nowhere in its "Deviations" section. Brief §8 item 8: *"A silent deviation found at gate time invalidates the handback."*

Failure scenario: `routes/index.tsx:2447` and `:2457` changed who reaches two live pages — `viewer` (holds `settings.view`, not `fraud-*.view`) **loses** access and `accountant` **gains** it. Nothing in either layer asserts that behaviour; a future edit reverting either gate to `moduleKey="settings"` is caught only by a string grep on one file, and re-locking the accountant out of `/settings/compliance/export` (the exact launch-required A-1 bug) has no backend or render-level test at all.

Mitigating, and why this is P2 not P1: the gate strings themselves are correct — I verified them against `Compliance/Presentation/routes.php:24,37,63,67,71` (`fraud-settings.view`, `fraud-alerts.view`, the three `compliance.*` keys) and against `permissionsMap.generated.ts:86,88` (accountant holds both fraud keys). The parity test does pin the exact gate strings on both fraud routes, and `RequirePermission`'s `permissions[]` any-of path (`RequirePermission.tsx:60-62`) is pre-existing behaviour.

## P3 — note (may ship with a ticket)

2. **The exact preflight invocation still never completes as one command.** It stops at repository-wide Pint drift on `tests/Feature/POS/ZReportListTest.php` — genuinely outside this lane and correctly not touched. Every downstream stage is green when run individually (evidence above), so this is now an environment/baseline residual, not a lane defect. Worth a ticket against the baseline file rather than this branch.

3. **Playwright flow 1 and the M1 screenshots still have not run** (`apps/web/e2e/pos/receipts-permissions.spec.ts` compiles but the local API was not up). Honestly disclosed in the handback and recorded in the YAML `findings:`. It remains the only live proof of the A-1 nav entry → `/settings/compliance/export` path and the `/pos/terminals` denial as a real accountant. Brief §7: an omitted run is an incomplete handback, not a green one — it is correctly reported as incomplete.

4. **NG-5 forward comment missing on the new route.** Spec §4.2.1 ("Future module gating") requires a one-line comment on each POS route stating it will inherit `ModuleGuard module="POS"`; the brief's scope-guard row repeats it ("Leave the one-line forward comment the spec asks for"). `grep -n 'ModuleGuard module="POS"\|NG-5\|becomes a real module' apps/web/src/routes/index.tsx apps/api/app/Modules/POS/routes.php` → no matches. The CL-6 comment that *is* present covers the write-surface retirement (NG-4), not this.

5. **The `filter-options` window-function SQL is never exercised on PostgreSQL.** Local runs are SQLite (`phpunit.xml`), and `ReceiptFilterOptionsTest` was **not** added to the pgsql merge-gate `--filter` — only `PosReceiptsIndexMigrationStructureTest` was. `fromSub` + `ROW_NUMBER()` is portable and I see no PG-specific hazard, but the one new query that is dialect-sensitive is the one CI does not run on the production dialect.

6. **The legacy `receipt_type` axis bypasses the training code-array bound.** `ReceiptController.php:117-119` short-circuits before the `invoice_type_codes` logic, so `?receipt_type=sale&include_training=true` drops the `training_flag = false` predicate with no code array bounding the set — the toggle widens on its own, which spec §3.a rule 1 forbids on the new axis. Spec's exhaustive table does not cover `receipt_type` and no screen sends it (FT-16 asserts the payload), so this is back-compat surface only. Untested.

7. **Spec §3.a rule 4's genuinely-empty array is still uncovered.** `test_empty_type_array_is_rejected` sends `invoice_type_codes[]=`, i.e. `['']` — it now honestly asserts `invoice_type_codes.0` (the `in:` rule), but the `min:1` path on a truly empty array remains unexercised. The rule is present in `IndexReceiptsRequest.php:32`.

8. **BT-9's accountant arm uses a permission-equipped user, not the seeded `accountant` role.** `ReceiptAuthorizationTest.php:26-42` grants `pos.view_reports` to the base admin-membership user. Spec BT-9 names the role. Mitigated in combination with BT-11 (`AccountantReceiptPermissionsTest`), which now runs the real seeder and pins the role's grants exactly.

9. **BT-8's "cross-company terminals absent" arm is untested.** `ReceiptFilterOptionsTest` proves cross-*location* exclusion (`Z-HIDDEN`) but never creates a second company. The `where('company_id', …)` guard is present at `ReceiptFilterOptionsController.php:42,44`.

## Bypasses I tried that FAILED (no defect found)

- **A cross-receipt aggregate** (Addendum A(c) rule 2 / OI-3): `git diff -U0 … | grep -inE '^\+.*(\bSUM\s*\(|\.reduce\(|totalsStrip|sum\()'` → the only hits are prose lines inside the copied-in spec/brief/findings markdown. No code aggregate anywhere.
- **Rule 19 / `any` / `app()` sweep over added lines**: `grep -E 'parseFloat|Number\(|: any|<any>|app\(|toFixed'` over `+` lines in `apps/web/src`, `apps/api/app`, `apps/api/database` → **zero hits**. `total` is `decimal:3` on the model (string), formatted with `CurrencyScale::bcformatStrict((string) $receipt->total, $this->currencyScaleResolver->getScale($receipt->currency))` (`ReceiptController.php:172,196`) — receipt currency, explicit, injected resolver (`:55`).
- **A float or company-currency leak on the FE**: `formatCurrency(receipt.total, { currency: receipt.currency })` (`ReceiptListPage.tsx:166`) via `lib/format.ts` — the only implementation that does not default to `'EUR'`. Verified `formatCurrency` accepts a string amount and never parses it as a float.
- **Permission-map / generated-type drift** (hard preflight failure): re-ran `permissions:export-frontend-map` and `typescript:transform`; `git status --porcelain` clean after both.
- **Panel-gating fail-open on `fallback={<></>}`**: `RequirePermission.tsx:66-69` returns the fallback only when it is truthy; `<></>` is a React element (truthy), so a partial holder gets an empty panel, not a whole-page redirect. Correct.
- **GATE-3 fail-open**: all six identity keys plus the composite `compliance` key are present in `MODULE_PERMISSIONS` (`usePermissions.ts:57-63`), so no sidebar `permission:` value on this branch is absent from the map.
- **Migration safety** (auto `tenants:migrate` on push): `CREATE INDEX IF NOT EXISTS` / `DROP INDEX IF EXISTS`, no `CONCURRENTLY`, no `$withinTransaction` need — self-guarding and unattended-safe per S-6.
- **Route middleware (rule 12)**: `filter-options` is registered before both `/pos/receipts` and `/pos/receipts/{id}` inside the group carrying `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` (`POS/routes.php:41,145`).
- **Location-scope escape**: intersection only narrows; `[]` membership fails closed (asserted at `ReceiptIndexLocationScopeTest.php:31-42`); client `location_ids[]` naming an unallowed location does not widen (asserted at `:12-29`).
- **i18n parity**: scripted flat-key diff of `en/fr` `pos.json` → **identical key sets**; `en/fr` `common.json` has only pre-existing `services.*` FR extras. All 30 keys the new page renders exist in both. No `receiptSearch` key or reference remains anywhere.
- **CL-1 orphans**: `grep -rn "ShiftReceiptsList" apps/web/src apps/pos/src` → zero. `getShiftReceipts` retained with the CL-2 `@see` pointer.
- **A design-system or query-key regression**: both `apps/web/tools` audits report `0 new`.

Nothing else in the wave-1 scope (S-1/S-2/S-3/S-6/S-7/S-9/S-11/S-13, screen (a), A-1 + nav + OP-23, GATE-3, GATE-5, A-2, CL-1/2/5/6/7, i18n rename, BT-1…BT-5/BT-8…BT-12/BT-16/BT-18, FT-1…FT-3/FT-10/FT-11/FT-14…FT-16) showed a defect on this round.

**To close:** add the `tests/Feature/Compliance/` route-gate coverage the brief names (accountant reaches the three `compliance/nf525/*` endpoints and both fraud endpoints; a `settings.view`-only role does not), plus one FE test that actually mounts `RequirePermission` for `/settings/compliance/export` and the two fraud routes — or, if you elect to defer, say so explicitly in the handback's Deviations section with the justification. Silence is what makes this a gate item.

VERDICT: CHANGES-REQUIRED
