# Company fiscal-identity guards — completion report

Date: 2026-08-10

Branch: `fix/company-identity-guards`

Base: `origin/dev` at `7d85232cc54abd6a6b2135f476205ab434e71a66`

Working tree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/company-identity-guards`

## Outcome

Implemented the complete guard lane named by
`CODEX-DISPATCH-settings-identity-guards-2026-08-10.md` and updated
`docs/superpowers/tickets/2026-08-10-country-code-mutable-authz-authority.md`.

- Company `country_code` and `currency` are immutable after provisioning through the tenant
  settings endpoint. Both the direct country field and the nested `address.country` alias are
  guarded. Idempotent same-value resubmissions remain valid because `CompanyPage` submits the
  loaded full form.
- Added `settings.fiscal.update` as defense-in-depth for custom and future roles. The split
  constrains no seeded role today: only seeded `admin` holds `settings.update`, and `admin`
  receives every permission. A future or custom cosmetic editor can hold `settings.update`
  without gaining fiscal-identity mutation authority.
- Mutable company fiscal identity is `legal_name`, `tax_id`, `registration_number`, and, on the
  adjacent `PUT /companies/{id}` surface, `vat_number`. Actual changes require the fiscal
  permission; unchanged values do not.
- Both company update surfaces now write a dedicated `company.fiscal_identity_updated` audit
  event through `AuditService`, with per-field old/new values. Update and audit are in one database
  transaction.
- The settings UI always locks country and currency, locks mutable fiscal fields without the new
  permission, explains both restrictions, and requires the existing `ConfirmDialog` pattern before
  a fiscal identity save.
- Added English and French backend errors and frontend guidance/confirmation copy.
- Regenerated `apps/web/src/hooks/permissionsMap.generated.ts`; it maps
  `settings.fiscal.update` to `admin` only.

No `tax_configurations` scoping, MFA, impersonation internals, stamp-capability logic, or unrelated
schema widths were changed.

## Files and implementation slices

### 1. Post-provisioning immutability

- `apps/api/app/Modules/Company/Application/Services/CompanyFiscalIdentityService.php`
- `apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php`
- `apps/api/lang/en/company.php`
- `apps/api/lang/fr/company.php`
- `apps/api/tests/Feature/Tenant/CompanySettingsTest.php`

The service centralizes strict old/new comparison and immutable-field validation. The controller
no longer maps either direct country/currency values or `address.country` into update attributes.

### 2. Fiscal authorization and audit

- `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- `apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php`
- `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php`
- `apps/api/tests/Feature/Tenant/CompanySettingsTest.php`
- `apps/api/tests/Feature/Company/CompanyUpdateAuthorizationTest.php`
- `apps/web/src/hooks/permissionsMap.generated.ts`

The mixed cosmetic/fiscal endpoints retain their route-level `settings.update` middleware and add
conditional controller enforcement for actual fiscal changes. This preserves cosmetic access while
preventing the adjacent `PUT /companies/{id}` endpoint from bypassing the split. This is currently
defense-in-depth for explicitly assigned/custom permission sets and future seeded roles; it does not
remove an authority held by any seeded non-admin role because no such role has `settings.update`.

### 3. Frontend affordances and confirmation

- `apps/web/src/features/settings/CompanyPage.tsx`
- `apps/web/src/features/settings/CompanyPage.test.tsx`
- `apps/web/src/locales/en/settings.json`
- `apps/web/src/locales/fr/settings.json`

## TDD evidence

All backend red and green runs used tests by explicit path. No full PHPUnit suite was run.

### Slice 1 red → green → refactor → revert-replay

Focused command (SQLite):

```text
APP_ENV=testing php artisan test tests/Feature/Tenant/CompanySettingsTest.php --filter='immutable|idempotent'
```

- Red: 3 failed, 1 warning, 13 assertions. Each mutation returned 200 instead of the expected
  validation failure.
- Green after minimum guard: 4 warnings, 22 assertions, exit 0.
- After formatting/refactor: 4 warnings, 22 assertions, exit 0.
- Revert-replay: removing only the production patch recreated the same 3 failures; reapplying it
  restored 4 warnings / 22 assertions.

The identical command with PostgreSQL configuration produced the same red and green counts:

```text
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=autoerp_identity_guards_test DB_USERNAME=houssamr DB_PASSWORD= \
php artisan test -c phpunit-pgsql.xml \
tests/Feature/Tenant/CompanySettingsTest.php --filter='immutable|idempotent'
```

Surrounding file after the slice: 44 warnings, 201 assertions, exit 0 on SQLite and PostgreSQL.

### Slice 2 red → green → refactor → revert-replay

Focused command:

```text
php artisan test \
tests/Feature/Tenant/CompanySettingsTest.php \
tests/Feature/Company/CompanyUpdateAuthorizationTest.php \
--filter='fiscal|cosmetic'
```

- Red on SQLite and PostgreSQL: 6 failed, 4 warnings, 22 assertions. Failures proved the missing
  permission, accepted unauthorized identity mutations, immutable-field authorization ordering,
  and absent dedicated audit records.
- Green on SQLite and PostgreSQL: 10 warnings, 38 assertions, exit 0.
- PostgreSQL caught a JSONB key-order assumption in the first audit assertion; the test was changed
  to compare semantic array content rather than storage ordering.
- Revert-replay on both drivers recreated 6 failures / 4 warnings / 22 assertions; reapplication
  restored 10 warnings / 38 assertions.

Surrounding paths after the slice:

- SQLite: 60 warnings, 241 assertions, exit 0, 42.31s.
- PostgreSQL at `127.0.0.1:5432`: 60 warnings, 241 assertions, exit 0, 53.08s.

### Slice 3 red → green → refactor → revert-replay

Focused command:

```text
pnpm exec vitest run src/features/settings/CompanyPage.test.tsx \
  -t 'CompanyPage fiscal identity guards'
```

- Red: 3 failed, 1 passed, 11 skipped. Country/currency and unauthorized fiscal fields were
  enabled, and a fiscal save called the API without confirmation.
- Green: 4 passed, 11 skipped, exit 0.
- Revert-replay recreated 3 failures / 1 pass; reapplication restored 4 passes.
- Surrounding file: 15 passed, exit 0.

React Doctor was required because this slice changed React. With a writable scratch npm cache:

```text
NPM_CONFIG_CACHE=/private/tmp/react-doctor-npm-cache \
npx react-doctor@latest --verbose --scope changed --base origin/dev
```

Result: score 91/100, 3 changed files scanned, no issues found. The skill did not require any code
change.

## Verification commands and results

- `./vendor/bin/pint --test` against all touched PHP production/test/translation files: pass.
- `./vendor/bin/phpstan analyse` against the touched service, controllers, and seeder with the repo
  configuration: `[OK] No errors`.
- `pnpm exec eslint src/features/settings/CompanyPage.tsx
  src/features/settings/CompanyPage.test.tsx`: exit 0; no errors. It reports three pre-existing
  warnings in `CompanyPage.tsx` (two unnecessary type assertions and one unnecessary template
  expression).
- `pnpm typecheck`: pass.
- `pnpm exec vitest run src/features/settings/CompanyPage.test.tsx`: 15 passed. Existing React
  `act(...)` warnings remain; the baseline run had the same warning family.
- Permission map regenerated with `php artisan permissions:export-frontend-map`; the generated
  entry is `settings.fiscal.update: ['admin']`.
- Final Git checks use `git diff --check`, explicit status inspection, and base/branch verification.

Backend feature runs display the suite's existing `file_get_contents(...)` warning on every test;
the same warning was present in the clean baseline. Tests and assertions still exit successfully.

## Other-writers survey

Searched Company model creation/update calls and direct `companies` table writes under
`apps/api/app` and `apps/api/database`.

### Live HTTP/application paths

- `AuthController` and `TenantProvisioningService`: create the first company during registration.
  These are provisioning-time writes and remain legitimate.
- `CompanyController::store`: creates an additional company with its initial country/currency.
  This is provisioning-time and remains legitimate.
- `CompanySettingsController::update`: was the only live HTTP writer for existing company
  country/currency, including the nested `address.country` alias. Both writes are now removed and
  guarded.
- `CompanyController::update`: does not accept country/currency but does accept legal/tax identity.
  It is now covered by the new permission and audit path to prevent an adjacent-surface bypass.
- No live import service or super-admin HTTP endpoint was found that mutates an existing company's
  country or currency.

### Seeders and fixtures

- New-company writers: `DatabaseSeeder`, `TunisianParapharmacySeeder`, `ParapharmacySeeder`,
  `CoffeeShopSeeder`, and `TwoTenantIsolationDemoSeeder` (direct insert). These provision fixture
  companies and are legitimate/out of scope.
- `DemoTenantSeeder` and `MenuTenantMultiCategoryFixture` use `updateOrCreate`, so rerunning the
  fixtures can overwrite identity on an existing demo fixture. They are deterministic demo reset
  tools, not a live tenant mutation flow, and remain unchanged.
- `DemoPharmacySeeder` and `TaxRecoverabilityTestDataSeeder` use `firstOrCreate`; identity values
  are creation defaults only and do not update an existing row.
- `CompanyFactory` supplies country/currency only for test creation.

### Migrations and internal services

- `2025_11_30_133000_migrate_tenant_data_to_companies.php` creates initial company rows during the
  historical tenant-to-company migration.
- Other Company updates found in migrations/services change unrelated defaults (tax rate,
  reservation settings, fiscal chain seed, POS policy, and similar fields). None mutates an
  existing company's country or currency.

No additional in-scope writer required a code change.

## Correction procedure

There is intentionally no tenant correction UI. For a genuine pre-go-live mistake:

1. A super-admin opens the established support-access case/grant and verifies the tenant, company,
   requested correction, and external evidence under the support ticket.
2. The ordinary tenant settings endpoint is not used. A write-elevated impersonation session still
   reaches the same guarded controller and therefore rejects country/currency changes; this is the
   intended v1 behavior.
3. The correction must use the approved operational super-admin maintenance procedure in the
   tenant context, with the support ticket and before/after values retained as evidence. No new
   bypass endpoint or impersonation exception was added in this lane.

The repository currently has no dedicated super-admin country/currency correction endpoint. If the
operational procedure is later productized, it needs its own audited, reviewed lane and must not
weaken the tenant controller guard.

## Deployment note

The permission catalog changed. Deployments must run the roles/permissions seeder for the relevant
tenants and then run `php artisan permission:cache-reset`. The permission cache is tenant-blind, so
the reset must not be skipped. Deploy the matching frontend permission map in the same release.

Before staging promotion, run this probe against **every tenant database** and require zero rows:

```sql
SELECT id, tenant_id, name, country_code, currency
FROM companies
WHERE country_code IS NULL OR currency IS NULL;
```

Before any live tenant onboarding, approve and publish a support-operations runbook for a genuinely
mis-provisioned country/currency. That procedure is not built today; support must not promise that
the application can perform the correction. Owner acknowledgement is tracked in
`docs/superpowers/tickets/2026-08-10-no-fiscal-identity-correction-path.md`.

## Decisions and deviations

- `CompanyPage` resubmits the loaded country and currency on ordinary saves, so guards compare
  values and allow idempotent submissions. This is the dispatch's prescribed decision for a
  legitimate full-form flow.
- The original checkout's `.git` metadata was read-only to Codex, including `FETCH_HEAD`; a linked
  `git worktree add` could not be created there. I verified `origin/dev` directly, then made a fresh
  standalone Git working tree inside the writable repository scope and branched from the exact
  required commit. This is the only setup deviation; the implementation branch is local and was
  neither pushed nor merged.
- The dispatch brief itself is present in the original checkout but is not tracked at the selected
  `origin/dev` commit, so it is not present in the fresh checkout. It was read from the user-supplied
  absolute path and executed; it was not copied into the implementation branch.
- A symlink to the original `apps/api/vendor` initially caused Composer's absolute autoload paths to
  execute controllers from the original checkout. The symlink was removed and `composer install
  --no-interaction --prefer-dist` was run locally in the fresh working tree before continuing.
- The first React Doctor invocation hit a root-owned user npm cache. Re-running with
  `NPM_CONFIG_CACHE=/private/tmp/react-doctor-npm-cache` completed successfully without changing
  repository dependencies.

There were no functional deviations from the brief and no TODOs or stub logic were left behind.

## Open follow-ups and concerns

- Gate `POST /api/v1/companies`: the settings-mutation route is closed, but un-permissioned company
  creation can still mint a chosen country authority. The launch-readiness item remains open.
- Company-scope authored `tax_configurations` in a separate reviewed lane. Global country-scoped
  rows are not company-isolated and can still affect a sibling company.
- Define and approve the operational super-admin correction procedure before live onboarding. No
  in-product correction exists today; this is an owner-ack item, not an optional volume-based
  enhancement.
- Existing backend `file_get_contents(...)` test warnings and frontend React `act(...)` warnings
  should be cleaned separately; they predate this work and do not mask a failing assertion here.

## Fix round 1 — gate-review follow-up

Date: 2026-08-10

This round addresses the fiscal-light P2/P3 findings and the documentary blockers from the
tenancy-authz `CHANGES-REQUESTED` verdict. It does not implement the newly documented authorization
or operational follow-up lanes.

### Documentary and launch-readiness corrections

- Reclassified the country-authority ticket as partially closed. Existing-company mutation through
  settings is closed; un-permissioned `POST /api/v1/companies` can still mint a chosen country
  authority, and country-scoped `tax_configurations` still affect sibling companies. Launch
  readiness therefore remains open.
- Added separate tickets for the `POST /companies` gate, `tax_status` permission/audit asymmetry,
  branch-location tax identity under `inventory.adjust`, and the owner acknowledgement required
  for the absent country/currency correction path.
- Replaced English and French “contact support” promises with the true state: fiscal identity is
  fixed at creation and the necessary support-operations procedure is not yet available.
- Corrected P3-1 wording: `settings.fiscal.update` constrains no seeded role today because only
  seeded `admin` has `settings.update` and `admin` receives all permissions. The split is
  defense-in-depth for custom assignments and future roles.
- Added two deploy gates: a staging query that must find zero companies with NULL country/currency,
  and a runbook/owner-ack requirement before a live tenant onboards.

### Small code corrections

- Replaced the fiscal-identity service's open string-key contract with PHPStan literal unions for
  mutable and immutable fields. Immutable validation now has an exhaustive two-arm `match`; the
  former `LogicException` default is no longer reachable or needed.
- Reordered tenant settings handling so immutable country/currency changes return 422 before the
  caller's `settings.fiscal.update` permission is considered. Mutable fiscal changes still return
  403 for a caller without that permission; idempotent immutable submissions remain accepted.
- Added a real transaction-boundary test that forces `AuditEvent` creation to throw and proves the
  preceding company update is rolled back with no fiscal audit row persisted.
- Updated the stale F-7 capability-cache comment: company country is immutable, while the
  tenant/company-scoped query key handles company switches and newly created companies.
- Added a second visible immutable hint directly under Regional Settings beside the disabled
  currency select. Country and currency now each reference their adjacent hint through distinct
  `aria-describedby` IDs.

### Red/green and final verification

No full PHPUnit suite was run. Every backend run named explicit test paths.

- Backend red, SQLite: the new precedence test failed because the response still returned 403;
  the rollback characterization already passed. Result: 1 failed, 1 warning, 5 assertions.
- Frontend red: the Regional Settings assertion found one immutable hint instead of two. Result:
  1 failed, 14 skipped.
- Focused green on both SQLite and PostgreSQL 5432: 7 tests, 28 assertions, 0 failures per driver.
- Final SQLite paths (`CompanySettingsTest.php` and `CompanyUpdateAuthorizationTest.php`): 61 tests,
  245 assertions, 0 failures.
- Final PostgreSQL paths, identical files and port 5432: 61 tests, 245 assertions, 0 failures.
- PHPUnit labels all 61 tests as warnings because of the pre-existing `file_get_contents(...)`
  warning already recorded in the baseline report; no assertion or test failed.
- Pint, scoped to the five touched PHP service/controller/test/translation files: pass.
- PHPStan, scoped to the touched production service/controller and translation files: no errors.
  An exploratory inclusion of the entire legacy feature-test file exposed its existing nullable
  model diagnostics; the one diagnostic introduced by the new rollback assertion was corrected.
- Full touched frontend test file: 15 passed. Existing React `act(...)` warnings remain unchanged.
- Frontend typecheck: pass. ESLint: 0 errors; only the three pre-existing `CompanyPage.tsx` warnings.
- React Doctor: 91/100, four changed files scanned, no issues found.

### Commit handoff

The implementation and verification completed, but this Codex session could not create the
requested commit. The workspace files are writable while `.git` is mounted read-only by the
session permission profile; `git add` failed when Git attempted to create `.git/index.lock`.
No stash or push was attempted. Branch HEAD therefore remains
`ad8e8d15d737df7d05ce021db171ab41236767d9`, with the verified fix round present as unstaged working
tree changes. The intended commit subject is
`fix-round-1: close company identity guard review findings`.
