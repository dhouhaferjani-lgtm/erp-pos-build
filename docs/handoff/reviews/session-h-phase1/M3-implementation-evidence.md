# M3 implementation evidence

Scope: Session H Phase 1 M3, code HEAD `4b8fbcedd4969b2b6931b5ace0a818df0d626e4b` before this evidence-only commit.

## Authorization ruling

The supplier list intentionally uses only canonical `partners.view` (`apps/web/src/routes/index.tsx:843-849`). The rejected suggestion to compose `moduleKey="purchases"` would add the unrelated UI alias `purchases.view`: `MODULE_PERMISSIONS.purchases` maps directly to that alias (`apps/web/src/hooks/usePermissions.ts:29-33`) and the alias is role grouping, explicitly “NOT backend authorization” (`apps/web/src/hooks/uiAliasPermissions.ts:1-7`). It is not a vertical ModuleGuard. The binding dispatch calls the supplier route previously ungated and explicitly requires canonical `partners.view`; `PartnerRoutes.gates.test.tsx:64-73` proves `partners.view` permits the list while `purchases.view` alone does not. Replacement-only is therefore preserved.

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
- BUG-006 mechanism: `partnerListRouteType.test.tsx` now submits the unkeyed reconciled form after the visible Type becomes blank and proves no POST occurs; the keyed production route continues to render `supplier`. Focused GREEN: 11/11. This pins that a visually blank selector cannot conceal a posted customer.

## Browser and consumer evidence

Committed browser command at code HEAD `4b8fbcedd4969b2b6931b5ace0a818df0d626e4b`:

`E2E_BASE_URL=http://localhost:5174 API_BASE=http://127.0.0.1:8011/api/v1 pnpm --filter @autoerp/web exec playwright test e2e/session-h/m3-dead-crm-partner-vehicle-gates.spec.ts --project=chromium --workers=1`

Result: **4 passed / 1 runtime skip**. Item (v) skipped only because Otospex discovery/authentication was unavailable locally: email-first `admin@demo.local/password` returned the missing identity-index 422; the known `demo-unlimited` tenant is present centrally but its tenant database is not provisioned, so explicit-tenant auth is 503. Post-auth company/partner failures remain hard failures, not skips.

The restricted gate creates a disposable API user, assigns a temporary exact `contacts.update` role, removes the cashier bootstrap role, and uses the real PIN verification API to prove `contacts.update` is present while `partners.update` and `partners.view` are absent. The browser retains its owner transport token; the restricted contexts project that API-verified disposable identity into `/auth/me` solely to exercise the production frontend `AuthProvider` / `RequirePermission` guard. This is not a literal restricted-user browser login and does not claim end-to-end authentication or backend-authorization coverage. Cleanup asserts every role/user response. No seeded user's roles are altered. A seeded cashier was explicitly rejected after live `/auth/me` proved it has `partners.view`.

Screenshots (refreshed by the committed run):

- `.playwright-mcp/session-h/m3/m3-retired-companies.png`
- `.playwright-mcp/session-h/m3/m3-owner-partner-edit.png`
- `.playwright-mcp/session-h/m3/m3-contacts-only-partner-edit-blocked.png`
- `.playwright-mcp/session-h/m3/m3-owner-suppliers-list.png`
- `.playwright-mcp/session-h/m3/m3-customer-create-types.png`
- `.playwright-mcp/session-h/m3/m3-supplier-create-types.png`

The selected-state PartnerPicker label/group change is broader than VehicleForm but benign: the adversarial register's 60 consumer files / 473 tests were green across documents, vouchers, scheduling, CRM, and expenses; focused PartnerPicker tests remain 13/13.

## Final verification

- Focused frontend regression command covering route gates/table, PartnerPicker, PartnerForm, the pure Nature heuristic, BUG-006, VehicleForm, and the session-H helper: `pnpm --filter @autoerp/web exec vitest run src/routes/PartnerRoutes.gates.test.tsx src/routes/routes.test.tsx src/components/molecules/pickers/PartnerPicker.test.tsx src/features/partners/PartnerForm.test.tsx src/features/partners/partnerNature.test.ts src/features/partners/__tests__/partnerListRouteType.test.tsx src/features/vehicles/__tests__/VehicleForm.tenantScope.test.tsx src/test/sessionHLoginHelper.test.ts --maxWorkers=1` — **8 files / 97 tests passed**. A parallel attempt passed 96/97 but resource-starved the source-tree route scan past its fixed five-second limit; `routes.test.tsx` immediately passed 22/22 alone in 364 ms, and no timeout was changed. Existing `act(...)` diagnostics and unmatched-route messages remain warning-only in older tests.
- `pnpm --filter @autoerp/web typecheck`: exit 0.
- `pnpm --filter @autoerp/web lint`: exit 0, including the chained key, design-system, quantity, and i18n audits plus custom ESLint/tool tests.
- Changed-scope React Doctor (`npx react-doctor@latest --verbose --scope changed --base bdc228a18`): **89/100, 16 files, no issues**.
- Targeted PHPStan and Pint for the two partner requests and `CreatePartnerTest`: exit 0.
- `php artisan test tests/Feature/Import/ImportReExecutionGuardTest.php`: **7 passed / 28 assertions**, including the exact finished-job replay witness at `:161-191`.
- Feature-lane manifest, TanStack-key audit, design audit, local i18n audit, and `git diff --check`: exit 0. The i18n audit has zero new gaps and reports only the eight intentional key removals as baseline burn-down.

## Owes parent / out of scope

- `VehicleForm.tsx` still offers display-cased fuel/transmission literals while backend enums use lowercase canonical values. This mismatch pre-existed M3; no production enum/payload behavior was broadened here.
- The local i18n audit reports eight prior baseline entries translated (burn-down) and zero new gaps. No baseline re-pin is required for this deletion-only cleanup; parent may elect to lock the burn-down later.
