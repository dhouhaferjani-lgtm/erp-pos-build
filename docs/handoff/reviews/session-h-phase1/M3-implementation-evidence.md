# M3 implementation evidence

Scope: Session H Phase 1 M3 through adversarial round-2 code HEAD `73b9d8ad1` before this evidence-only commit.

## Authorization ruling

The round-2 controller ruling supersedes the earlier replacement-only decision after concrete list/detail and sidebar-consistency evidence. The supplier list now composes the existing purchases UI/module alias and canonical partner authorization as `<RequirePermission moduleKey="purchases" permission="partners.view">` (`apps/web/src/routes/index.tsx`). `MODULE_PERMISSIONS.purchases` still maps to `purchases.view` (`apps/web/src/hooks/usePermissions.ts`), while `partners.view` remains the canonical backend permission; both are intentionally required so a user cannot bookmark a supplier list that the unchanged purchases-gated detail/sidebar flow cannot coherently expose. `PartnerRoutes.gates.test.tsx` proves each leg independently: `partners.view` alone is denied, `purchases.view` alone is denied, and both permissions open the supplier list and detail.

## Second of everything

- **Second company:** `apps/api/tests/Feature/Partner/CreatePartnerTest.php:206-257` creates company B in the same tenant, creates A and B customers with the same `SHARED-001` code and different Nature values, proves both database rows, and proves company A's list cannot see B. `apps/web/src/features/partners/PartnerForm.test.tsx:157-177` proves Nature-required behavior for A and B. The legacy-null B2B decision is extracted as the generated-DTO-typed pure function `shouldShowPartnerB2BFields`; `apps/web/src/features/partners/partnerNature.test.ts:5-17` evaluates the same immutable company-B row twice and gets the same result, proving re-render/idempotency.
- **Second location — not in scope: no location-keyed table touched by this lane.** The complete partners schema/model check found no `location_id` or location relationship: the base schema enumerates partner fields at `apps/api/database/migrations/tenant/2025_11_30_052119_create_partners_table.php:16-35`, later partner migrations add business/tax/address/account fields only, and the model fillable list at `apps/api/app/Modules/Partner/Domain/Partner.php:97-135` has no location. The vehicle picker change is FE-only.
- **Re-run / idempotency:** Parties import replay protection is the existing `apps/api/tests/Feature/Import/ImportReExecutionGuardTest.php:161-191` (finished job cannot run again and no row is reapplied), with the pending-residue replay case at `:194-216`. Nature/B2B is a pure function of its row and is evaluated twice at `partnerNature.test.ts:5-17`.

## Concepts and baseline

`Concepts:` Party (glossary ✅; row updated by `df921cccf`), Contact (glossary ✅), Nature (Phase-1 label over `customer_category`; Phase 2 owns `party_kind`). No hand-rolled partner domain type was added. M1/a3 established `apps/web/src/features/partners/types.ts` as the generated `PartnerData` feature boundary; the authoritative generated fields are at `packages/shared/types/generated.d.ts:1834-1850`, and M3's pure function is a `Pick<PartnerData, ...>`.

Industry baseline: this shape-neutral cleanup relies on `docs/superpowers/specs/2026-08-23-party-contact-target-model-research.md` §2.1–§2.5, as required by the M3 addendum; the full convention-10 table remains Phase 2-owned.

## TDD record

Original behavior cycles (commands run from repository root unless noted):

- Routes RED: `pnpm --filter @autoerp/web exec vitest run src/routes/PartnerRoutes.gates.test.tsx src/routes/routes.test.tsx` — 6 failed / 25 passed: `partners.update` could not open edits, `contacts.update` wrongly opened them, `partners.view` could not open suppliers while purchases did, and Companies route/sidebar remained. GREEN: 31/31.
- Type choices RED: `pnpm --filter @autoerp/web exec vitest run src/features/partners/PartnerForm.test.tsx -t "Type choices"` — 2 failed / 1 passed because create options were unscoped. GREEN full file: 27/27 at implementation time.
- Vehicle picker RED: `pnpm --filter @autoerp/web exec vitest run src/features/vehicles/__tests__/VehicleForm.tenantScope.test.tsx` — 2 failed / 3 passed because VehicleForm had no customer-filtered picker/search. GREEN: 5/5.
- A11y RED: `pnpm --filter @autoerp/web exec vitest run src/components/molecules/pickers/PartnerPicker.test.tsx` — 1/13 failed; selected Owner lacked the expected accessible owner-specific clear name. Initial M3.6 GREEN: 13/13.
- Localized-clear RED (round 1): same PartnerPicker command — 1/13 failed, expected `Clear Owner selection`, actual composed `Clear selection Owner`. GREEN: 13/13 with one interpolated EN/FR/AR key.
- Pure heuristic RED: `pnpm --filter @autoerp/web exec vitest run src/features/partners/partnerNature.test.ts` — suite failed because `./partnerNature` did not exist. GREEN: 1/1 after extracting the pure generated-DTO-typed row function.
- Second-company RED: `cd apps/api && php artisan test tests/Feature/Partner/CreatePartnerTest.php --filter=second_company_can_reuse_partner_code` — company B received 422 instead of 201 because request validation remained tenant-wide. GREEN after matching the database's `(company_id, code)` contract: 1/1 (10 assertions); full file 14/14 (62 assertions).
- Update-code coverage RED: with the former tenant-wide uniqueness rule reintroduced as a mutation, `php artisan test tests/Feature/Partner/UpdatePartnerTest.php --filter=code` passed own-code retention and same-company duplicate rejection but failed cross-company reuse with 422 instead of 200. GREEN with the company-scoped rule: 3/3 (14 assertions); full file 21/21 (46 assertions). The cross-company case also proves an acting company-A context receives 404 when attempting to update the company-B partner, while the existing suite retains its cross-tenant 404 case.
- Round-2 supplier composition RED: focused `PartnerRoutes.gates.test.tsx` failed 1/11 because a `partners.view`-only actor still rendered the supplier list instead of the dashboard. GREEN after composing both props: 11/11; the `purchases.view`-only leg is also denied and the both-permission actor opens list and detail.
- Real-session browser source RED: `sessionHM3BrowserGateSource.test.ts` failed because the E2E spec intercepted `/auth/me` and used `useRestrictedIdentity`. GREEN removes both and pins a normal `loggedPage(browser, restrictedCredentials)` login for the disposable user.
- BUG-006 mechanism: `partnerListRouteType.test.tsx` now submits the unkeyed reconciled form after the visible Type becomes blank and proves no POST occurs; the keyed production route continues to render `supplier`. Focused GREEN: 11/11. This pins that a visually blank selector cannot conceal a posted customer.

## Browser and consumer evidence

Committed browser command at code HEAD `73b9d8ad1`:

`E2E_BASE_URL=http://localhost:5174 API_BASE=http://127.0.0.1:8011/api/v1 pnpm --filter @autoerp/web exec playwright test e2e/session-h/m3-dead-crm-partner-vehicle-gates.spec.ts --project=chromium --workers=1`

Result: **4 passed / 1 runtime skip**, exit 0, in 53.4 seconds. Item (v) skipped only because Otospex discovery/authentication was unavailable locally: email-first `admin@demo.local/password` returned the missing identity-index 422; the known `demo-unlimited` tenant is present centrally but its tenant database is not provisioned, so explicit-tenant auth is 503. Post-auth company/partner failures remain hard failures, not skips.

The restricted gate creates a disposable email user through the API, assigns a temporary exact `contacts.update` role, removes the cashier bootstrap role through the API, and activates it through the existing API. Because the product has no public test-token or password-setting endpoint, a local/testing-only tenant-aware Artisan bootstrap sets that exact disposable user's deterministic per-run password; it refuses non-local/test environments, initializes the centrally resolved tenant through the tenancy application layer, changes only the password hash, and always ends tenancy. The browser then uses the normal login page with the disposable email/password. Live server and in-browser `/auth/me` responses prove the bearer/session belongs to the exact disposable user, with `contacts.update` present and `partners.update` / `partners.view` absent; there is no auth interception or owner-token identity projection. Cleanup asserts every role/user response, deletes the user and role, and proves the saved disposable token is revoked with 401. No seeded user's identity or roles are altered.

The local worktree's central database was initially empty, so the committed browser gate first failed at tenant discovery with a 500 for missing `central_identities`. Per controller authorization, the normal deterministic fixture path was used: `APP_ENV=local php artisan migrate --force` followed by `APP_ENV=local php artisan db:seed --class='Database\\Seeders\\DemoPharmacySeeder' --force`. The seeder provisioned and recorded `demo-pharmacy-tn` through application services (tenant `01a04e72-3947-7015-ac92-4ecd0a0c5b95`) and created its owner identity. No orphan tenant database was altered, and the intended deterministic demo tenant was retained.

Screenshots (refreshed by the committed run):

- `.playwright-mcp/session-h/m3/m3-retired-companies.png`
- `.playwright-mcp/session-h/m3/m3-owner-partner-edit.png`
- `.playwright-mcp/session-h/m3/m3-contacts-only-partner-edit-blocked.png`
- `.playwright-mcp/session-h/m3/m3-owner-suppliers-list.png`
- `.playwright-mcp/session-h/m3/m3-customer-create-types.png`
- `.playwright-mcp/session-h/m3/m3-supplier-create-types.png`

The selected-state PartnerPicker label/group change is broader than VehicleForm but benign: the adversarial register's 60 consumer files / 473 tests were green across documents, vouchers, scheduling, CRM, and expenses; focused PartnerPicker tests remain 13/13.

## Final verification

- Focused frontend regression command covering route gates/table, PartnerPicker, PartnerForm, the pure Nature heuristic, BUG-006, VehicleForm, the session-H login helper, and the no-auth-interception source regression: `pnpm --filter @autoerp/web exec vitest run src/routes/PartnerRoutes.gates.test.tsx src/routes/routes.test.tsx src/components/molecules/pickers/PartnerPicker.test.tsx src/features/partners/PartnerForm.test.tsx src/features/partners/partnerNature.test.ts src/features/partners/__tests__/partnerListRouteType.test.tsx src/features/vehicles/__tests__/VehicleForm.tenantScope.test.tsx src/test/sessionHLoginHelper.test.ts src/test/sessionHM3BrowserGateSource.test.ts --maxWorkers=1` — **9 files / 100 tests passed**. Existing `act(...)` diagnostics and unmatched-route messages remain warning-only in older tests.
- `pnpm --filter @autoerp/web typecheck`: exit 0.
- `pnpm --filter @autoerp/web lint`: exit 0: ESLint 0 errors (6,463 baseline warnings), TanStack/design/quantity/i18n audits green, zero new or stale ratchet findings, and tool tests 8 files / 160 tests passed.
- Changed-scope React Doctor (`npx react-doctor@latest --verbose --scope changed --base bdc228a18`): **89/100, 17 files, no issues**.
- Targeted PHPStan and Pint for both partner requests plus `CreatePartnerTest` and `UpdatePartnerTest`: exit 0.
- `php artisan test tests/Feature/Partner/CreatePartnerTest.php tests/Feature/Partner/UpdatePartnerTest.php tests/Feature/Import/ImportReExecutionGuardTest.php`: **42 passed / 136 assertions**, including the exact finished-job replay witness at `ImportReExecutionGuardTest.php:161-191`.
- Feature-lane manifest, TanStack-key audit, design audit, local i18n audit, and `git diff --check`: exit 0. The i18n audit has zero new gaps and reports only the eight intentional key removals as baseline burn-down.

## Owes parent / out of scope

- `VehicleForm.tsx` still offers display-cased fuel/transmission literals while backend enums use lowercase canonical values. This mismatch pre-existed M3; no production enum/payload behavior was broadened here.
- The local i18n audit reports eight prior baseline entries translated (burn-down) and zero new gaps. No baseline re-pin is required for this deletion-only cleanup; parent may elect to lock the burn-down later.
- **Partner-code contract realignment:** matching validation to the existing `(company_id, code)` database constraint changes the published same-tenant/cross-company create response from 422 to 201. This is intentional and tested, but POS pending-customer creation, Parties import, and other API clients may have encoded the former over-rejection; the parent must include this relaxation in promotion/client communication.
- **Otospex browser debt:** browser item (v) remains locally skippable only because the seeded `demo-unlimited` tenant database is not provisioned. The customer-only picker semantics have unit/Vitest evidence, but no local Otospex browser evidence when that named runtime skip fires.
- **Phase 2 form-state debt:** when a mismatched `partnerType="customer"` and supplier pathname coexist, the independently derived customer/supplier contexts both become true and collapse the unkeyed Type options to blank/`both`. Production keyed routes prevent the live path and BUG-006 pins safe non-submission; Phase 2 should replace the double-context derivation with one authoritative context independently of React keys.

## Adversarial round 2

Commits `701f7e53b` and `73b9d8ad1` close the two confirmed P2 findings. Route RED was 1 failed / 10 passed: a `partners.view`-only actor still reached the supplier list. GREEN is 11/11 with independent denial for `partners.view`-only and `purchases.view`-only actors plus list/detail success for the actor holding both. Browser-source RED was 1/1 because the spec still contained the circular `/auth/me` interception; GREEN removes interception and pins normal `loggedPage(browser, restrictedCredentials)` authentication.

The post-commit browser run at code HEAD `73b9d8ad1` is 4 passed / 1 allowed Otospex runtime skip in 53.4 seconds. It exercises a real disposable email/password session and live `/auth/me`; teardown leaves zero active `session-h-m3-%@example.test` users and zero `session-h-m3-contacts-only-%` roles, and the deleted user's saved token returns 401. The six M3 screenshots were refreshed by this run.

Round-2 preservation/realignment: the partner-code 422-to-201 relaxation is explicitly owed to parent for API/POS/import consumers; the locally unprovisioned Otospex database means browser item (v) retains unit evidence only; and the double-context form derivation remains a Phase 2 debt. No progress YAML, adversarial register, schema, generated DTO, public auth endpoint, seeded identity, or baseline file changed.
