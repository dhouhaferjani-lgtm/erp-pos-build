# Multiloc Spec/Plan Adversarial Review

Reviewed:
- Spec: `docs/superpowers/specs/2026-07-03-multi-company-location-settings-ux-design.md`
- Plan: `docs/superpowers/plans/2026-07-03-multi-company-location-settings-ux.md`

Overall verdict: NEEDS-REWORK

## Findings

### BLOCKER: Chunk 1 leaves logo upload/delete writing Tenant while settings GET would read Company

The plan repoints only `show`/`update` to Company, but the same settings UI also uses `/settings/company/logo`. Today upload/delete still resolve `Tenant` and write `tenant.logo_path`: `apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php:152`, `apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php:174`, `apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php:211`, `apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php:235`. The UI uploads/deletes via those routes and renders `settings.logo_url` from `GET /settings/company`: `apps/web/src/features/settings/CompanyPage.tsx:127`, `apps/web/src/features/settings/CompanyPage.tsx:143`, `apps/web/src/features/settings/CompanyPage.tsx:434`, `apps/web/src/features/settings/CompanyPage.tsx:437`.

If `CompanySettingsData::fromCompany()` returns `Company.logo_path` while logo endpoints still mutate `Tenant.logo_path`, logo upload/delete will appear broken. Chunk 1 must either repoint logo endpoints too or explicitly keep logo Tenant-backed and adjust the GET contract.

### BLOCKER: Planned `address.country -> companies.address_country` mapping targets a nonexistent column

The plan's mapping uses `$company->address_country` and writes `address_country`, but the companies table has address street/street_2/city/state/postal only, plus top-level `country_code`: `apps/api/database/migrations/tenant/2025_11_30_104000_create_companies_table.php:47`, `apps/api/database/migrations/tenant/2025_11_30_104000_create_companies_table.php:52`, `apps/api/database/migrations/tenant/2025_11_30_104000_create_companies_table.php:34`. The Company model fillable list likewise includes `address_street`, `address_city`, `address_postal_code`, but no `address_country`: `apps/api/app/Modules/Company/Domain/Company.php:207`, `apps/api/app/Modules/Company/Domain/Company.php:211`, `apps/api/app/Modules/Company/Domain/Company.php:214`.

With "no schema changes", the unchanged response contract's `address.country` must map to `companies.country_code`, or the implementation will no-op/fail and tests asserting `address.country` need to reflect that.

### BLOCKER: Existing settings validation allows nulls for non-null Company columns

`UpdateCompanySettingsRequest` permits `null` for `country_code`, `currency_code`, `timezone`, `date_format`, and `locale`: `apps/api/app/Modules/Tenant/Presentation/Requests/UpdateCompanySettingsRequest.php:41`, `apps/api/app/Modules/Tenant/Presentation/Requests/UpdateCompanySettingsRequest.php:42`, `apps/api/app/Modules/Tenant/Presentation/Requests/UpdateCompanySettingsRequest.php:43`, `apps/api/app/Modules/Tenant/Presentation/Requests/UpdateCompanySettingsRequest.php:44`, `apps/api/app/Modules/Tenant/Presentation/Requests/UpdateCompanySettingsRequest.php:45`. Company requires non-null `country_code`, `currency`, `locale`, and `timezone`: `apps/api/database/migrations/tenant/2025_11_30_104000_create_companies_table.php:34`, `apps/api/database/migrations/tenant/2025_11_30_104000_create_companies_table.php:59`, `apps/api/database/migrations/tenant/2025_11_30_104000_create_companies_table.php:60`, `apps/api/database/migrations/tenant/2025_11_30_104000_create_companies_table.php:61`.

The plan says no rule changes are needed. That is unsafe after the data source flips to Company.

### MAJOR: Currency edits through `/settings/company` will not refresh the app-wide company store

The spec wants currency changes reflected in `/user/companies`; that endpoint is Company-sourced and returns `currency`: `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:717`, `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:726`. But `CompanyPage` only invalidates the `company-settings` query after save: `apps/web/src/features/settings/CompanyPage.tsx:109`, `apps/web/src/features/settings/CompanyPage.tsx:113`. The company store is fed by `/user/companies` under `tenantScopedKey(['user', 'companies'])` with a 10-minute stale time: `apps/web/src/features/company/CompanyProvider.tsx:75`, `apps/web/src/features/company/CompanyProvider.tsx:76`, `apps/web/src/features/company/CompanyProvider.tsx:78`, `apps/web/src/features/company/CompanyProvider.tsx:82`. `useCurrency()` reads the cached store currency and falls back to EUR: `apps/web/src/hooks/useCurrency.ts:44`, `apps/web/src/hooks/useCurrency.ts:47`.

Chunk 1 needs to invalidate/refetch `['user','companies']` or update the store after a currency PATCH, otherwise the settings page can save Company currency while the rest of the app keeps displaying the old currency.

### MAJOR: Live non-provisioning consumers still read Tenant business fields

Platform billing uses `tenant.country_code` for tax rate and billing country and reads `tenant.address` for invoices: `apps/api/app/Modules/Billing/Application/Services/InvoiceService.php:357`, `apps/api/app/Modules/Billing/Application/Services/InvoiceService.php:359`, `apps/api/app/Modules/Billing/Application/Services/InvoiceService.php:389`, `apps/api/app/Modules/Billing/Application/Services/InvoiceService.php:391`, `apps/api/app/Modules/Billing/Application/Services/InvoiceService.php:398`. This is not just provisioning-time display like `CreateTenantCommand`: `apps/api/app/Modules/Tenant/Application/Commands/CreateTenantCommand.php:106`, `apps/api/app/Modules/Tenant/Application/Commands/CreateTenantCommand.php:107`.

Stopping all Tenant writes means billing address/country will no longer track settings edits. The spec identifies this risk, but the plan's "report, don't fix" step is not enough unless product explicitly accepts billing staying tenant-account-scoped.

### MAJOR: Global location-switch invalidation can overwrite dirty settings forms

`LocationSwitcher` is shown in the top bar on almost every dashboard route: `apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx:23`, `apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx:46`, `apps/web/src/components/organisms/TopBar/TopBar.tsx:108`, `apps/web/src/components/organisms/TopBar/TopBar.tsx:112`. Chunk 5 changes the switch handler from stock-only predicates to `queryClient.invalidateQueries()`; current code only invalidates `stock-levels` and `stock-movements`: `apps/web/src/components/organisms/LocationSwitcher/LocationSwitcher.tsx:70`, `apps/web/src/components/organisms/LocationSwitcher/LocationSwitcher.tsx:74`, `apps/web/src/components/organisms/LocationSwitcher/LocationSwitcher.tsx:77`.

Some non-location forms rehydrate local state from query results without a dirty guard. `TaxSettingsPage` sets `formData` inside the query function on every refetch and tracks `isDirty` separately: `apps/web/src/features/settings/TaxSettingsPage.tsx:81`, `apps/web/src/features/settings/TaxSettingsPage.tsx:86`, `apps/web/src/features/settings/TaxSettingsPage.tsx:94`, `apps/web/src/features/settings/TaxSettingsPage.tsx:101`. `InventorySettings` similarly resets local settings when query data changes: `apps/web/src/features/settings/components/InventorySettings.tsx:93`, `apps/web/src/features/settings/components/InventorySettings.tsx:96`, `apps/web/src/features/settings/components/InventorySettings.tsx:104`, `apps/web/src/features/settings/components/InventorySettings.tsx:107`. A global invalidation from the location switcher can erase unsaved edits on these screens.

### MAJOR: Arabic settings work is missing/wired to English

The plan repeatedly requires edits to `apps/web/src/locales/{en,fr,ar}/settings.json`, but the Arabic settings file is absent in the worktree (`apps/web/src/locales/ar/` contains many files, not `settings.json`). The runtime also wires Arabic `settings` to the English settings bundle: `apps/web/src/lib/i18n.ts:104`, `apps/web/src/lib/i18n.ts:106`, `apps/web/src/lib/i18n.ts:127`, `apps/web/src/lib/i18n.ts:238`, `apps/web/src/lib/i18n.ts:287`.

The plan must add `ar/settings.json`, import it, and wire `resources.ar.settings` to it; otherwise the required Arabic i18n edits are either impossible or dead.

### MINOR: AddLocationModal evidence overstates the missing fields

The spec says the quick-add modal captures no postal code. It already has `addressPostalCode` state, renders an input, and sends it through the create API: `apps/web/src/components/organisms/AddLocationModal/AddLocationModal.tsx:39`, `apps/web/src/components/organisms/AddLocationModal/AddLocationModal.tsx:95`, `apps/web/src/components/organisms/AddLocationModal/AddLocationModal.tsx:260`, `apps/web/src/components/organisms/AddLocationModal/AddLocationModal.tsx:270`, `apps/web/src/features/location/api.ts:40`, `apps/web/src/features/location/api.ts:131`.

The accurate gap is: no country input is rendered, despite `addressCountry` state/payload plumbing: `apps/web/src/components/organisms/AddLocationModal/AddLocationModal.tsx:40`, `apps/web/src/components/organisms/AddLocationModal/AddLocationModal.tsx:96`; and no tax/VAT fields are rendered, even though the API input/payload already has `taxId`/`vatNumber`: `apps/web/src/features/location/api.ts:42`, `apps/web/src/features/location/api.ts:43`, `apps/web/src/features/location/api.ts:133`, `apps/web/src/features/location/api.ts:134`.

### MINOR: Several named test targets do not exist yet

The plan references `apps/web/src/components/organisms/AddLocationModal/__tests__/AddLocationModal.test.tsx` and a LocationSwitcher test file, but those component test directories/files are absent; the only AddLocationModal and LocationSwitcher files are the components and indexes: `apps/web/src/components/organisms/AddLocationModal/AddLocationModal.tsx:19`, `apps/web/src/components/organisms/LocationSwitcher/LocationSwitcher.tsx:46`. Existing settings tests do exist, e.g. `apps/web/src/features/settings/SettingsPage.test.tsx:1` and `apps/web/src/features/settings/__tests__/SettingsPage.sections.test.tsx:1`.

This is not a design blocker, but the implementation plan should say "create" for those tests and provide harness setup rather than assuming an extendable test file.

## Verified Claims / Non-Findings

- `/settings/company` currently reads/writes Tenant: `apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php:61`, `apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php:87`, `apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php:127`; the Tenant model marks those business fields deprecated: `apps/api/app/Modules/Tenant/Domain/Tenant.php:26`, `apps/api/app/Modules/Tenant/Domain/Tenant.php:39`, `apps/api/app/Modules/Tenant/Domain/Tenant.php:48`.
- `SettingsPage` really has no locations card, while the route exists: `apps/web/src/features/settings/SettingsPage.tsx:29`, `apps/web/src/features/settings/SettingsPage.tsx:96`, `apps/web/src/routes/index.tsx:1958`, `apps/web/src/routes/index.tsx:1967`.
- `CreateLocationRequest` really early-returns when country is empty: `apps/api/app/Modules/Company/Presentation/Requests/CreateLocationRequest.php:67`, `apps/api/app/Modules/Company/Presentation/Requests/CreateLocationRequest.php:71`, `apps/api/app/Modules/Company/Presentation/Requests/CreateLocationRequest.php:74`, `apps/api/app/Modules/Company/Presentation/Requests/CreateLocationRequest.php:75`.
- `FR/TN/MA` is consistent with backend tax-identity config today: `apps/api/config/tax_identity.php:6`, `apps/api/config/tax_identity.php:7`, `apps/api/config/tax_identity.php:8`, `apps/api/config/tax_identity.php:9`, `apps/api/app/Modules/Company/Application/Services/CountryTaxIdentityConfig.php:30`.
- The countries API exposes the claimed snake_case tax profile fields: `apps/api/app/Models/Country.php:27`, `apps/api/app/Models/Country.php:39`, `apps/api/app/Http/Controllers/Api/CountryController.php:20`, `apps/api/app/Http/Controllers/Api/CountryController.php:25`, `apps/web/src/features/settings/types/country.ts:20`, `apps/web/src/features/settings/types/country.ts:28`.
