# Design-System Sweep Progress

> Branch: `feat/design-system-unification` in `/Users/houssamr/Projects/syneriva/apps/erp.design-sweep`.
> Source handoff: `docs/handoff/CODEX-design-system-unification-2026-07-10.md`.

## Wave 0 — Tooling & Guardrails

Status: complete.

- Worktree: created from `origin/dev` as required by the handoff.
- Companion manifests copied into the branch because they were untracked in the main checkout.
- 0.1 TanStack audit shorthand-property blind spot:
  - RED: `pnpm vitest run tools/__tests__/audit-tanstack-keys.test.mjs` failed on `flags shorthand queryKey declarations with no tenant scope`.
  - GREEN: same command passed after resolving shorthand `queryKey` declarations.
- 0.2 Tenant-scoped picker keys:
  - RED: `pnpm audit:keys` reported `PartnerPicker`, `VehiclePicker`, `ServicePicker`, plus an additional real `UserPicker` violation exposed by the scanner fix.
  - GREEN: `pnpm audit:keys` reports 0 violations after wrapping all four picker keys with `tenantScopedKey`.
- 0.3 `AddPartnerModal` invalidation:
  - RED: `pnpm vitest run src/components/organisms/AddPartnerModal/AddPartnerModal.test.tsx` failed on live partner search/detail cache invalidation.
  - GREEN: same command passed after replacing the stale exact key with a tenant/company-aware predicate for `partners`, `partners-search`, `pickers/partner`, and the created `partner` detail.
- 0.4 Design-system audit:
  - RED: `pnpm vitest run tools/__tests__/audit-design-system.test.mjs` failed because `tools/audit-design-system.mjs` did not exist.
  - GREEN: same command passed after adding the C1-C6 scanner.
  - Baseline seeded with 509 current C1-C6 entries using `node tools/audit-design-system.mjs --write-baseline`.
  - `node tools/audit-design-system.mjs` passes with 509 acknowledged, 0 new, 0 stale.
  - Wired into `apps/web/package.json`, `scripts/preflight.sh`, and `.github/workflows/ci.yml`.
- 0.5 ESLint hardcoded-color rule:
  - Global WARN and strict new-feature ERROR regexes widened to the full C7 palette/utility set.
- 0.6 Vehicles regression:
  - Replaced `hover:bg-gray-50` and `bg-gray-100` literals in `VehicleDetailPage` with existing design tokens.
- Verification:
  - `pnpm vitest run tools/__tests__/audit-tanstack-keys.test.mjs tools/__tests__/audit-design-system.test.mjs src/components/organisms/AddPartnerModal/AddPartnerModal.test.tsx` passed: 37 tests.
  - `pnpm typecheck` passed.
  - `pnpm lint` passed; existing warning count remains high, but 0 errors. The chained audits passed:
    - TanStack query key audit: 0 violations.
    - Design-system audit: 509 acknowledged, 0 new, 0 stale.
  - Vehicles C7 scoped check returned zero matches:
    `rg -n --pcre2 '(bg|text|border|ring|divide|from|to|via|placeholder|fill|stroke|outline|accent|caret|shadow|decoration)-(gray|red|green|blue|yellow|amber|orange|purple|pink|indigo|emerald|rose|slate|zinc|neutral|stone)-[0-9]{2,3}\b' src/features/vehicles -g '!**/*.test.tsx' -g '!**/__tests__/**' -g '!**/*.stories.tsx' -c`

New shared-shape components: none.

## Wave 3 — Partner Picker Consolidation

Status: complete.

- 3.1 `PartnerPicker` canonical behavior:
  - Added suggestions-on-focus, ID-only rehydration through `tenantScopedKey(['partner', id])`, `includeInactive`, and inline creation through `AddPartnerModal`.
  - Kept caller-owned creation flows via `onAddNew` for pages that need custom prefill.
  - Added focused coverage for focus suggestions, bare-ID rehydration, inactive inclusion, and inline creation.
- 3.2 Legacy picker migrations:
  - Replaced `PartnerSearchSelect` in `DocumentForm`, `CreateCreditNotePage`, `QuoteRequestCreatePage`, and `CustomerHistoryAuditPage`.
  - Replaced CRM `PartnerSelect` usage in contact create/edit and detail flows.
  - Replaced document-ingestion `SupplierPicker` with `PartnerPicker` plus a page-level `Create supplier` action for the prefilled modal flow.
  - Kept explicit labels/placeholders at migrated call sites; nested form-field call sites pass `label=""` to avoid duplicate visible labels.
- 3.3 Dead component removal:
  - Deleted `components/ui/PartnerSearchSelect.tsx`.
  - Deleted `features/crm/components/PartnerSelect.tsx`.
  - Deleted `features/document-ingestions/components/SupplierPicker.tsx` and its obsolete test.
  - Baseline shrunk from 507 to 505 after removing stale `SupplierPicker` design-system fingerprints.
- 3.4 Tenant-scope regression found during verification:
  - `LocationSwitcher` previously invalidated every query on location switch.
  - Updated it to invalidate all active tenant/company-suffixed queries without invalidating another tenant/company cache, and updated component/shared selector tests.
- Verification:
  - Focused Wave 3 suite passed: `pnpm vitest run src/components/molecules/pickers/PartnerPicker.test.tsx src/components/__tests__/SharedSelectors.tenantScope.test.tsx src/components/organisms/LocationSwitcher/__tests__/LocationSwitcher.test.tsx src/features/documents/DocumentForm.test.tsx src/features/documents/__tests__/DocumentForm.tenantScope.test.tsx src/features/documents/__tests__/ReturnCreditNotePages.tenantScope.test.tsx src/features/purchases/quote-requests/QuoteRequestCreatePage.test.tsx src/features/customer-history-audit/pages/__tests__/CustomerHistoryAuditPage.test.tsx src/features/crm/pages/__tests__/ContactFormPage.test.tsx src/features/crm/__tests__/tenantScope.test.tsx src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx` passed: 76 tests.
  - `pnpm typecheck` passed.
  - `pnpm lint` passed; existing warning count remains high, but 0 errors. The chained audits passed:
    - TanStack query key audit: 0 violations.
    - Design-system audit: 505 acknowledged, 0 new, 0 stale.
  - `npx react-doctor@latest --verbose --scope changed --base HEAD` passed with one residual warning: `ContactFormPage` is still a large component. Splitting it is unrelated to this picker consolidation and was left out of scope.

New shared-shape components: `PartnerPicker` is now the canonical partner/supplier/customer picker.

## Wave 1 — High-Impact UX Corrections

Status: complete.

- 1.1 `LineItemEntryBar` suggestions-on-focus:
  - RED: `pnpm vitest run src/components/molecules/line-items/LineItemEntryBar.test.tsx` failed on an empty-query focus suggestion test.
  - GREEN: the entry bar now opens and fetches first-page product suggestions on focus while keeping scanner Enter resolution unchanged.
- 1.2 Workshop service affordance:
  - RED: `pnpm vitest run src/features/documents/components/__tests__/DocumentLineEditor.test.tsx` failed because the Workshop-gated `Service` control was absent.
  - GREEN: `DocumentLineEditor` now exposes a `ServicePicker` when Workshop is enabled and adds `is_service` document lines with service ids and service pricing.
- 1.3 Supplier-invoice ProductPicker filter:
  - RED: `pnpm vitest run src/features/purchases/supplier-invoices/SupplierInvoiceCreatePage.test.tsx --testNamePattern "all-products picker"` failed with `productType: undefined`.
  - GREEN: manual supplier-invoice lines now pass `productType="all"` so the picker does not inherit the `part` default.
- 1.4 Table density:
  - RED: focused density tests failed while `DocumentLineEditor` and `QuoteDetailPage` still exposed separate description tables/columns.
  - GREEN: editable document lines, read-only `DocumentLines`, quote detail lines, and supplier-invoice receipt rows now use article-cell description/notes density with `line-clamp-2`; long text remains available through `LineItemsTable` detail rows.
  - Baseline shrunk from 509 to 507 after removing two stale bespoke table fingerprints for `DocumentLines` and `QuoteDetailPage`.
- Verification:
  - `pnpm vitest run src/components/molecules/line-items/LineItemEntryBar.test.tsx src/features/documents/components/__tests__/DocumentLineEditor.test.tsx src/features/purchases/supplier-invoices/SupplierInvoiceCreatePage.test.tsx src/features/documents/quotes/__tests__/QuoteDetailPage.tenantScope.test.tsx` passed: 49 tests.
  - `pnpm typecheck` passed.
  - `pnpm lint` passed; existing warning count remains high, but 0 errors. The chained audits passed:
    - TanStack query key audit: 0 violations.
    - Design-system audit: 507 acknowledged, 0 new, 0 stale.
  - `npx react-doctor@latest --verbose --scope changed --base origin/dev` passed with no issues after replacing changed-page barrel imports in `QuoteDetailPage`.

New shared-shape components: `DocumentLines` now renders via `LineItemsTable`.

## Wave 2 — Company Designation Override Setting

Status: complete.

- 2.1 Company-backed feature source:
  - RED: `php artisan test tests/Feature/Api/CompanyConfigControllerTest.php --filter line_designation_override_comes_from_primary_company_setting` initially returned `false` while the company setting was `true`.
  - GREEN: added tenant migration `companies.line_designation_override_enabled boolean default false`, model fillable/cast metadata, and `CompanyConfigController` now reads the current company column for the existing `line_designation_override_enabled` payload key.
- 2.2 Settings update endpoint:
  - Added `line_designation_override_enabled` validation and update mapping in company settings.
  - Added a feature test proving true and false updates persist to the company row and return in the settings payload.
- 2.3 Settings UI:
  - RED: `pnpm vitest run src/features/settings/CompanyPage.test.tsx` failed because no line-designation switch existed.
  - GREEN: Company settings now include a Documents section with a `Toggle` for custom line designations; save submits the existing backend key.
  - Added EN/FR/AR copy. French helper text explicitly says an edited designation must remain a "dénomination précise" of the goods or services sold and is soft guidance, not automatic validation.
- 2.4 Env-flag removal:
  - Removed the old `FEATURE_DOCUMENT_LINE_DESIGNATION_OVERRIDE` deployment flag from `config/features.php`.
  - Document PDF line notes now receive `lineDesignationOverrideEnabled` from the document company instead of config. Factur-X was not touched.
  - Added PDF render coverage for notes hidden when the company setting is disabled.
- 2.5 Generated types:
  - `CompanySettingsData` is not annotated with `#[TypeScript]` and is not present in `packages/shared/types/generated.d.ts`; `php artisan typescript:transform` was not run to avoid unrelated generated-file churn.
- Verification:
  - `php artisan test tests/Feature/Api/CompanyConfigControllerTest.php --filter line_designation_override_comes_from_primary_company_setting` passed: 4 assertions.
  - `php artisan test tests/Feature/Tenant/CompanySettingsTest.php --filter line_designation_override_setting` passed: 6 assertions.
  - `php artisan test tests/Feature/Modules/Document/DocumentPdfRenderTest.php` passed: 5 tests, 15 assertions.
  - `./vendor/bin/phpstan analyse app/Http/Controllers/Api/CompanyConfigController.php app/Modules/Company/Domain/Company.php app/Modules/Tenant/Application/DTOs/CompanySettingsData.php app/Modules/Tenant/Presentation/Requests/UpdateCompanySettingsRequest.php app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php --memory-limit=1G` passed. Including the full touched `CompanySettingsTest.php` still reports pre-existing nullable/test fixture issues outside this wave.
  - `pnpm vitest run src/features/settings/CompanyPage.test.tsx` passed: 7 tests.
  - `pnpm typecheck` passed.
  - `pnpm lint` passed; existing warning count remains high, but 0 errors. The chained audits passed:
    - TanStack query key audit: 0 violations.
    - Design-system audit: 507 acknowledged, 0 new, 0 stale.
  - `npx react-doctor@latest --verbose --scope changed --base origin/dev` passed with no issues after replacing a changed-page barrel import in `CompanyPage`.

New shared-shape components: none.
