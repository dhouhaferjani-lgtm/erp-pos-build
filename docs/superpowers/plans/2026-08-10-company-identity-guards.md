# Company Fiscal-Identity Guards Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent post-provisioning country/currency mutation, restrict mutable company fiscal identity to owner/admin-tier users, audit accepted identity changes, and require explicit frontend confirmation.

**Architecture:** A focused `CompanyFiscalIdentityService` compares submitted identity attributes with persisted `Company` values and raises field-keyed validation errors for immutable country/currency changes. Both company-update HTTP surfaces use conditional `settings.fiscal.update` authorization and `AuditService::record`; the Company settings page disables immutable fields, gates mutable fiscal fields, and reuses `ConfirmDialog` before submission.

**Tech Stack:** Laravel 12, PHP 8.2 strict types, Spatie Permission, PHPUnit on SQLite and PostgreSQL 15.15, React 19, TypeScript strict, TanStack Query, Vitest/Testing Library, i18next.

## Global Constraints

- Branch `fix/company-identity-guards` remains local, unmerged, and unpushed.
- Run PHPUnit tests by explicit file path only; never run the full suite.
- Run PostgreSQL tests against `127.0.0.1:5432`, never Docker port `5433`.
- Never use `git stash`; preserve rule 19 money discipline, strict types, constructor injection, and en+fr user-facing strings.
- For each behavior, prove RED, implement minimum GREEN, refactor, rerun its exact path and surrounding path, then revert-replay the fix commit.
- Do not modify `tax_configurations` scoping, MFA, impersonation internals, stamp capability code, or schema code-width concerns.

---

### Task 1: Immutable Country and Currency

**Files:**
- Create: `apps/api/app/Modules/Company/Application/Services/CompanyFiscalIdentityService.php`
- Modify: `apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php`
- Modify: `apps/api/tests/Feature/Tenant/CompanySettingsTest.php`
- Create: `apps/api/lang/en/company.php`
- Create: `apps/api/lang/fr/company.php`

**Interfaces:**
- Produces: `changedFields(Company $company, array $candidateAttributes): array<string, array{old: string|null, new: string|null}>`.
- Produces: `assertImmutableFieldsUnchanged(Company $company, array $candidateAttributes, array $validationKeys): void`.
- Consumes direct `country_code`, aliased `address.country`, and `currency_code` request values.

- [ ] **Step 1: Write failing HTTP tests**

Add tests that submit `FR -> TN` through `country_code`, `address.country`, and `EUR -> TND` through `currency_code`; each must return 422 with the matching field key and leave both columns unchanged. Add a control that submits the current values with cosmetic changes and receives 200.

- [ ] **Step 2: Run RED on SQLite and PostgreSQL**

```bash
cd apps/api
php artisan test tests/Feature/Tenant/CompanySettingsTest.php --filter='immutable|idempotent'
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_identity_guards_test DB_CENTRAL_DATABASE=autoerp_identity_guards_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test -c phpunit-pgsql.xml tests/Feature/Tenant/CompanySettingsTest.php --filter='immutable|idempotent'
```

Expected: mutation cases fail because the endpoint currently writes both columns.

- [ ] **Step 3: Implement minimum guard**

Create the strict service. Compare actual submitted identity values before building attributes, remove country/currency from the update map, and never map `address.country` onto `companies.country_code`. Throw `ValidationException::withMessages()` using translated `company.identity.country_immutable` and `company.identity.currency_immutable` messages.

- [ ] **Step 4: Run GREEN and surrounding file**

Run both Step 2 commands, then the complete `tests/Feature/Tenant/CompanySettingsTest.php` path on SQLite and PostgreSQL.

- [ ] **Step 5: Commit and revert-replay**

```bash
git commit -m "Phase 0.1.1: Guard immutable company identity"
git diff HEAD^ HEAD | git apply -R
# Run the mutation filters and observe RED.
git diff HEAD^ HEAD | git apply
# Run them again and observe GREEN on SQLite and PostgreSQL.
```

---

### Task 2: Fiscal Permission Split and Auditing

**Files:**
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- Modify: `apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php`
- Modify: `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php`
- Modify: `apps/api/tests/Feature/Tenant/CompanySettingsTest.php`
- Modify: `apps/api/tests/Feature/Company/CompanyUpdateAuthorizationTest.php`
- Regenerate: `apps/web/src/hooks/permissionsMap.generated.ts`

**Interfaces:**
- Produces `settings.fiscal.update`, granted only to seeded `admin`.
- Consumes actual changes to `legal_name`, `tax_id`, `registration_number`, `vat_number`, `country_code`, and `currency`.
- Produces `company.fiscal_identity_updated` audit events containing `payload.changes.<field>.old/new`.

- [ ] **Step 1: Write failing permission and audit tests**

Add a user holding `settings.update` without the new permission: cosmetic updates return 200 while changed fiscal fields return 403. Add owner/admin controls, audit old/new assertions, and equivalent bypass coverage for `PUT /companies/{id}` including `vat_number`.

- [ ] **Step 2: Run RED on SQLite and PostgreSQL**

```bash
cd apps/api
php artisan test tests/Feature/Tenant/CompanySettingsTest.php tests/Feature/Company/CompanyUpdateAuthorizationTest.php --filter='fiscal|identity|cosmetic'
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_identity_guards_test DB_CENTRAL_DATABASE=autoerp_identity_guards_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test -c phpunit-pgsql.xml tests/Feature/Tenant/CompanySettingsTest.php tests/Feature/Company/CompanyUpdateAuthorizationTest.php --filter='fiscal|identity|cosmetic'
```

Expected: permission/grant assertions fail, low-privilege identity writes succeed, and dedicated audit events are absent.

- [ ] **Step 3: Implement conditional authorization and atomic audit**

Add the permission to the catalog. Inject the identity service and `AuditService` into both controllers. Compare persisted values so unchanged full-form identity keys do not require elevated authority. Return translated 403 responses for actual unauthorized identity changes. Wrap each accepted identity update and audit record in one transaction.

- [ ] **Step 4: Regenerate frontend permissions**

```bash
cd apps/api
php artisan permissions:export-frontend-map
```

Verify the generated map contains `'settings.fiscal.update': ['admin']`.

- [ ] **Step 5: Run GREEN, surrounding files, commit, and revert-replay**

Run both Step 2 commands and both complete backend files on SQLite/PostgreSQL, commit as `Phase 0.1.2: Split fiscal settings authority`, reverse with `git diff HEAD^ HEAD | git apply -R` to prove RED, then reapply and prove GREEN.

---

### Task 3: Frontend Gating and Confirmation

**Files:**
- Modify: `apps/web/src/features/settings/CompanyPage.tsx`
- Modify: `apps/web/src/features/settings/CompanyPage.test.tsx`
- Modify: `apps/web/src/locales/en/settings.json`
- Modify: `apps/web/src/locales/fr/settings.json`

**Interfaces:**
- Consumes `hasPermission('settings.fiscal.update')` and existing `ConfirmDialog`.
- Produces immutable disabled country/currency controls, gated legal/tax/registration controls, and confirmed submission for actual mutable identity changes.

- [ ] **Step 1: Write failing Vitest cases**

Prove a caller with only `settings.update` can save cosmetic fields but cannot edit legal/tax/registration fields; admin can edit those fields; country/currency stay disabled; identity changes open confirmation and do not PATCH until confirm; cosmetic changes submit directly.

- [ ] **Step 2: Run RED**

```bash
cd apps/web
pnpm exec vitest run src/features/settings/CompanyPage.test.tsx
```

- [ ] **Step 3: Implement minimum UI behavior**

Compute `canEditFiscalIdentity`, disable fields with translated hints, compare `legal_name`, `tax_id`, and `registration_number` with loaded settings, and reuse `ConfirmDialog` with en+fr title/message/action strings.

- [ ] **Step 4: Run GREEN, regressions, commit, and revert-replay**

Run CompanyPage plus its country-profile and scope test paths, commit as `Phase 0.1.3: Confirm fiscal identity edits`, reverse the commit to prove new cases RED, reapply, and prove GREEN.

---

### Task 4: Scoped Verification and Handoff

**Files:**
- Modify: `docs/superpowers/tickets/2026-08-10-country-code-mutable-authz-authority.md`
- Create: `docs/sessions/codex-settings-identity-guards-report.md`

- [ ] **Step 1: Run final backend paths on SQLite and PostgreSQL**

Run complete `CompanySettingsTest.php` and `CompanyUpdateAuthorizationTest.php` paths serially on both databases.

- [ ] **Step 2: Run touched backend static gates**

```bash
cd apps/api
./vendor/bin/pint --test app/Modules/Company/Application/Services/CompanyFiscalIdentityService.php app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php app/Modules/Company/Presentation/Controllers/CompanyController.php database/seeders/RolesAndPermissionsSeeder.php tests/Feature/Tenant/CompanySettingsTest.php tests/Feature/Company/CompanyUpdateAuthorizationTest.php lang/en/company.php lang/fr/company.php
./vendor/bin/phpstan analyse --debug --no-progress --memory-limit=2G app/Modules/Company/Application/Services/CompanyFiscalIdentityService.php app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php app/Modules/Company/Presentation/Controllers/CompanyController.php database/seeders/RolesAndPermissionsSeeder.php
```

- [ ] **Step 3: Run frontend gates and React diagnostics**

```bash
cd apps/web
pnpm exec vitest run src/features/settings/CompanyPage.test.tsx src/features/settings/__tests__/CompanyPage.countryProfile.test.tsx src/features/settings/__tests__/CompanyPage.scope.test.tsx
pnpm typecheck
pnpm exec eslint src/features/settings/CompanyPage.tsx src/features/settings/CompanyPage.test.tsx
```

Run the repository `react-doctor` skill workflow and resolve regressions.

- [ ] **Step 4: Update ticket and write report**

Reference this local guard lane, leave tax-configuration scoping open, document support correction via super-admin support access, impersonation guard behavior, the writer survey, deploy reseed plus `permission:cache-reset`, exact red/green/revert evidence, results, deviations, concerns, and follow-ups.

- [ ] **Step 5: Verify scope and commit handoff**

Check `git diff --check`, branch, commits, changed paths, and absence of stub markers. Commit as `Phase 0.1.4: Document identity guard delivery` without merging or pushing.
