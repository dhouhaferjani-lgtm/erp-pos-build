# Adversarial Design Review: Tax Provisioning + POS Customer Modal

Date: 2026-06-12

Specs reviewed:
- `docs/superpowers/specs/2026-06-12-tenant-tax-provisioning-and-resolution-design.md`
- `docs/superpowers/specs/2026-06-12-pos-customer-search-modal-design.md`

Verdict: NEEDS-REWORK

Confidence: 88%

Evidence source: files were read from `/Users/houssamr/Projects/syneriva/apps/erp.tax-pos-specs`. This review artifact was written in the writable workspace because the requested `.tax-pos-specs` path rejected writes with `Operation not permitted`.

## BLOCKER Findings

### B1 - Provisioning caller list is incomplete and would leave real company creation paths without tax defaults

Spec section: Spec 1, sections 3.1 and 4.

File:line evidence:
- `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:64` to `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:80` seeds reference data, chart of accounts, default tax rate, then tax configurations during registration initialization.
- `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:252` to `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:270` silently returns when `countries` lacks the company country and only maps `TN` to `TunisiaTaxConfigurationSeeder`.
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:352` to `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:358` routes DB-per-tenant registration through `TenantProvisioningService`.
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:478` to `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:482` calls `TenantInitializationService` on the shared-DB registration path.
- `apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:133` to `apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:152` creates the registration company, and `apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:180` to `apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:182` calls initialization.
- `apps/api/app/Modules/Tenant/Application/Commands/ResetTenantCommand.php:97` to `apps/api/app/Modules/Tenant/Application/Commands/ResetTenantCommand.php:110` reuses `initializeForNewRegistration()` for companies found during tenant reset.
- `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:66` to `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:104` creates additional companies.
- `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:145` to `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:152` only seeds chart of accounts after company creation; no reference data, tax configuration, default tax FK/rate, payment method, or repository initialization is called.
- `apps/api/database/seeders/ParapharmacySeeder.php:367` to `apps/api/database/seeders/ParapharmacySeeder.php:387` creates an FR company directly.
- `apps/api/database/seeders/ParapharmacySeeder.php:412` to `apps/api/database/seeders/ParapharmacySeeder.php:426` seeds chart of accounts, payment methods, and repositories, but no tax configurations.
- `apps/api/database/seeders/ParapharmacyMultiBranchSeeder.php:197` to `apps/api/database/seeders/ParapharmacyMultiBranchSeeder.php:215` creates another FR parapharmacy company directly.
- `apps/api/database/seeders/CoffeeShopSeeder.php:236` to `apps/api/database/seeders/CoffeeShopSeeder.php:254` creates a TN coffee-shop company directly.
- `apps/api/database/seeders/CoffeeShopSeeder.php:276` to `apps/api/database/seeders/CoffeeShopSeeder.php:287` seeds financial foundation without tax configurations.
- `apps/api/database/seeders/DatabaseSeeder.php:75` to `apps/api/database/seeders/DatabaseSeeder.php:92` creates and seeds an FR company, while `apps/api/database/seeders/DatabaseSeeder.php:103` to `apps/api/database/seeders/DatabaseSeeder.php:118` creates a TN company and only then calls `TunisiaTaxConfigurationSeeder`.
- `apps/api/database/seeders/DemoTenantSeeder.php:184` to `apps/api/database/seeders/DemoTenantSeeder.php:196`, `apps/api/database/seeders/DemoTenantSeeder.php:1480` to `apps/api/database/seeders/DemoTenantSeeder.php:1491`, `apps/api/database/seeders/DemoTenantSeeder.php:1575` to `apps/api/database/seeders/DemoTenantSeeder.php:1587`, `apps/api/database/seeders/DemoTenantSeeder.php:1650` to `apps/api/database/seeders/DemoTenantSeeder.php:1661`, `apps/api/database/seeders/DemoTenantSeeder.php:1723` to `apps/api/database/seeders/DemoTenantSeeder.php:1734`, `apps/api/database/seeders/DemoTenantSeeder.php:1796` to `apps/api/database/seeders/DemoTenantSeeder.php:1807`, `apps/api/database/seeders/DemoTenantSeeder.php:1869` to `apps/api/database/seeders/DemoTenantSeeder.php:1880`, `apps/api/database/seeders/DemoTenantSeeder.php:1942` to `apps/api/database/seeders/DemoTenantSeeder.php:1953`, and `apps/api/database/seeders/DemoTenantSeeder.php:2015` to `apps/api/database/seeders/DemoTenantSeeder.php:2026` all create/update companies directly.
- `apps/api/database/seeders/TunisianParapharmacySeeder.php:99` to `apps/api/database/seeders/TunisianParapharmacySeeder.php:101` does call the Tunisia tax seeder, but `apps/api/database/seeders/TunisianParapharmacySeeder.php:157` to `apps/api/database/seeders/TunisianParapharmacySeeder.php:178` still creates the company directly.
- `apps/api/database/seeders/ProductionSeeder.php:10` to `apps/api/database/seeders/ProductionSeeder.php:15` explicitly says it creates no tenant, user, or transactional data, but `apps/api/database/seeders/ProductionSeeder.php:41` to `apps/api/database/seeders/ProductionSeeder.php:44` only seeds Tunisia tax configurations.

Problem:
The spec correctly identifies that registration uses `TenantInitializationService`, but the proposed caller list is too narrow. Following it literally would fix signup plus some parapharmacy seeders, but would still leave the normal "create another company" API path and multiple demo/vertical seeders with no shared country tax provisioning or company default tax FK. The "every provisioning path" claim needs an explicit inventory of all company writers, not just `ParapharmacySeeder` and its siblings.

Recommended fix:
Add a required shared `CompanyTaxProvisioningService::provisionForCompany(Company $company)` (or equivalent) and call it from all production company creation paths and all demo/vertical seeders that create companies: registration shared path, DB-per-tenant registration, `CompanyController::store()`, `ResetTenantCommand`, `DatabaseSeeder`, `DemoTenantSeeder`, `ParapharmacySeeder`, `ParapharmacyMultiBranchSeeder`, `CoffeeShopSeeder`, `TunisianParapharmacySeeder`, and `TaxRecoverabilityTestDataSeeder` if that fixture is intended to exercise tax recoverability against real configs. Keep `ProductionSeeder` as lookup-only, but add FR tax config seeding if it is meant to preload country-global reference data.

### B2 - "Resolve once at import" is not a single chokepoint and misses normal API/composite creation

Spec section: Spec 1, section 3.4.

File:line evidence:
- `apps/api/app/Modules/Product/Application/Services/ProductService.php:43` to `apps/api/app/Modules/Product/Application/Services/ProductService.php:83` is an import/upsert path and stores `tax_rate` from input at line 55.
- `apps/api/app/Modules/Import/Services/ImportService.php:327` to `apps/api/app/Modules/Import/Services/ImportService.php:336` dispatches product and composite item imports separately.
- `apps/api/app/Modules/Import/Services/ImportService.php:360` to `apps/api/app/Modules/Import/Services/ImportService.php:365` sends product imports through `ProductService::upsert()`.
- `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:304` to `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:308` creates normal API products directly with validated input, bypassing `ProductService`.
- `apps/api/app/Modules/Catalog/Application/Services/CompositeItemImportService.php:19` to `apps/api/app/Modules/Catalog/Application/Services/CompositeItemImportService.php:69` has its own composite import/upsert path and only sets `tax_rate` if provided.
- `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemController.php:83` to `apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemController.php:91` creates composite items directly from request validation.
- `apps/api/database/seeders/ParapharmacySeeder.php:536` to `apps/api/database/seeders/ParapharmacySeeder.php:546`, `apps/api/database/seeders/CoffeeShopSeeder.php:334` to `apps/api/database/seeders/CoffeeShopSeeder.php:343`, and `apps/api/database/seeders/ParapharmacyMultiBranchSeeder.php:438` to `apps/api/database/seeders/ParapharmacyMultiBranchSeeder.php:448` create products directly in seeders.
- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:207` and `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:217` fall back to `0.00` when a composite item or product has null `tax_rate`.
- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:336` persists that resolved line `tax_rate` into receipt lines.

Problem:
The design says to implement the resolver in `ProductService create/import and the composite-item equivalent`, but there is no single product creation chokepoint. Admin-created products, imported products, composite-item API creates, composite-item imports, duplicated composite items, and direct seeders are separate writers. POS receipt creation then reads `tax_rate` from products/composites and turns null into zero. That means an implementation scoped to `ProductService` would still allow user-created API products and composite items to produce zero-tax sales.

Recommended fix:
Make the tax defaulting policy explicit for each writer. At minimum wire a shared resolver helper into `ProductController::store()`, `ProductService::upsert()`, `CompositeItemController::store()`, `CompositeItemImportService::upsert()`, and direct demo seeders. Decide separately whether `CompositeItemController::duplicate()` should preserve the source tax fields or recompute them. Add tests that create products/composites through both API and import paths with omitted `tax_rate`, then assert POS receipt creation uses the non-zero resolved rate.

## MAJOR Findings

### M1 - Runtime tax diagnosis is correct, but the spec underplays the consequence of leaving line creation untouched

Spec section: Spec 1, sections 1.2, 3.3, and 4.

File:line evidence:
- `apps/api/app/Modules/Taxation/Domain/Services/TaxCalculationService.php:71` to `apps/api/app/Modules/Taxation/Domain/Services/TaxCalculationService.php:76` selects active tax configurations by `company.country_code` and document type.
- `apps/api/app/Modules/Taxation/Domain/Services/TaxCalculationService.php:87` to `apps/api/app/Modules/Taxation/Domain/Services/TaxCalculationService.php:105` groups lines by `tax_rate` and matches configs with `bccomp`.
- `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:256` to `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:267` writes document line `tax_rate` as `$lineData['tax_rate'] ?? 0`.
- `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:599` to `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:612` does the same in the batch insert path.
- `apps/api/app/Modules/Taxation/Domain/Services/TaxResolutionService.php:25` to `apps/api/app/Modules/Taxation/Domain/Services/TaxResolutionService.php:59` implements line, product, category, then company fallback.
- `apps/api/app/Modules/Taxation/Providers/TaxationServiceProvider.php:41` to `apps/api/app/Modules/Taxation/Providers/TaxationServiceProvider.php:44` only registers `TaxResolutionService`; repository grep found no callers outside that registration and its own class.

Problem:
The spec is right that `TaxCalculationService` does not read `default_tax_configuration_id`, and that `DraftPersistenceService` writes `tax_rate ?? 0`. But because the plan explicitly leaves line creation/calculation untouched, the implementation must guarantee `tax_rate` is materialized before the line reaches POS/document creation. That is not just an import concern; it is a hard invariant for every sellable item writer.

Recommended fix:
State the invariant directly: "No sellable product/composite item may persist with null `tax_rate` unless it is intentionally tax-exempt and maps to a 0% config." Add validation/backfill tests for normal product API, product import, composite API, composite import, POS receipt creation, and document draft creation.

### M2 - Category tax columns exist, but the proposed validation comparison to Product is wrong

Spec section: Spec 1, section 3.3.

File:line evidence:
- `apps/api/database/migrations/tenant/2025_12_30_104000_add_tax_fields_to_categories.php:13` to `apps/api/database/migrations/tenant/2025_12_30_104000_add_tax_fields_to_categories.php:19` adds `default_tax_rate` and `default_tax_configuration_id` to categories.
- `apps/api/app/Modules/Product/Domain/Category.php:47` to `apps/api/app/Modules/Product/Domain/Category.php:58` omits both fields from `$fillable`.
- `apps/api/app/Modules/Product/Domain/Category.php:73` to `apps/api/app/Modules/Product/Domain/Category.php:79` omits both fields from casts.
- `apps/api/app/Modules/Product/Application/DTOs/CategoryData.php:18` to `apps/api/app/Modules/Product/Application/DTOs/CategoryData.php:33` omits both fields from the DTO.
- `apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php:116` to `apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php:123` and `apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php:168` to `apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php:174` omit category tax fields from create/update validation.
- `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php:52` to `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php:62` validates product `default_tax_configuration_id` only with `exists:tax_configurations,id`.
- `apps/api/app/Modules/Catalog/Presentation/Requests/StoreCompositeItemRequest.php:51` to `apps/api/app/Modules/Catalog/Presentation/Requests/StoreCompositeItemRequest.php:62` does include `TaxConfigurationCountryCoherent` for composite items.
- `apps/api/app/Modules/Catalog/Presentation/Rules/TaxConfigurationCountryCoherent.php:15` to `apps/api/app/Modules/Catalog/Presentation/Rules/TaxConfigurationCountryCoherent.php:23` documents why mismatched country tax config IDs are integrity garbage even though runtime ignores them.

Problem:
The column/model gap is real. Adding the two fields to `Category::$fillable` is mechanically safe because `Category` already uses an explicit allow-list and not `guarded = []`. However, the spec says category validation should use "the same validation rules Product uses" plus `TaxConfigurationCountryCoherent`. Product does not currently use the coherence rule; composite items do. If this is copied from Product only, categories would accept a tax configuration from the wrong country.

Recommended fix:
Add the fields to `Category` fillable/casts/docblock and `CategoryData`, but validate category `default_tax_configuration_id` with the composite-item precedent, not the current product precedent. Also open a follow-up or include Product request fixes so Product and Category both use `TaxConfigurationCountryCoherent`.

### M3 - Tax configurations are country-global, but the DB-per-tenant seeder behavior is uneven

Spec section: Spec 1, section 6.

File:line evidence:
- `apps/api/database/migrations/tenant/2025_12_30_100000_create_tax_configurations_table.php:13` to `apps/api/database/migrations/tenant/2025_12_30_100000_create_tax_configurations_table.php:17` creates `tax_configurations` with `country_code` FK and no `tenant_id`.
- `apps/api/database/migrations/tenant/2025_12_30_100000_create_tax_configurations_table.php:36` to `apps/api/database/migrations/tenant/2025_12_30_100000_create_tax_configurations_table.php:37` indexes by country/is_active and country/is_default, not tenant.
- `apps/api/database/seeders/ParapharmacySeeder.php:317` to `apps/api/database/seeders/ParapharmacySeeder.php:323` provisions and initializes the tenant database before tenant-scoped seed work.
- `apps/api/database/seeders/ParapharmacySeeder.php:176` to `apps/api/database/seeders/ParapharmacySeeder.php:195` states and performs reference data seeding inside tenant context for DB-per-tenant.
- `apps/api/database/seeders/ParapharmacyMultiBranchSeeder.php:102` to `apps/api/database/seeders/ParapharmacyMultiBranchSeeder.php:115` reuses the parapharmacy tenant provisioning pattern before reference data.
- `apps/api/database/seeders/DatabaseSeeder.php:47` to `apps/api/database/seeders/DatabaseSeeder.php:60` says DB-per-tenant seed data lands in the tenant DB and seeds countries/rates there.
- `apps/api/database/seeders/CoffeeShopSeeder.php:195` to `apps/api/database/seeders/CoffeeShopSeeder.php:228` creates a tenant and returns it without any `CreateDatabase`, `MigrateDatabase`, or `tenancy()->initialize()` step.
- `apps/api/database/seeders/CoffeeShopSeeder.php:95` to `apps/api/database/seeders/CoffeeShopSeeder.php:102` seeds reference data before creating the tenant, so under DB-per-tenant it is not following the parapharmacy ordering.

Problem:
The spec's "tax_configurations is country-global under db-per-tenant" statement is correct at the table level, but "demo seeders run inside the tenant connection" is not uniformly true. `ParapharmacySeeder`, `ParapharmacyMultiBranchSeeder`, and `DatabaseSeeder` explicitly handle DB-per-tenant. `CoffeeShopSeeder` does not in the code read here. Adding a shared tax step only works if it is invoked after the tenant connection is active and after `countries` exists.

Recommended fix:
Make the registry/service fail loudly in non-production seed/dev contexts when `countries` is missing for the company country, rather than silently no-oping. Normalize demo seeder tenant-connection setup before adding tax provisioning, especially `CoffeeShopSeeder`.

### M4 - France rates are sane, but the design ignores already-present eco-tax placeholders and does not decide non-VAT levies

Spec section: Spec 1, section 2.1.

File:line evidence:
- `docs/superpowers/specs/2026-06-12-tenant-tax-provisioning-and-resolution-design.md:76` to `docs/superpowers/specs/2026-06-12-tenant-tax-provisioning-and-resolution-design.md:87` proposes FR 20/10/5.5/2.1 and TN TVA/timbre defaults.
- `apps/api/database/migrations/tenant/2026_05_01_000004_add_eco_tax_fields_to_pos_receipt_lines.php:24` to `apps/api/database/migrations/tenant/2026_05_01_000004_add_eco_tax_fields_to_pos_receipt_lines.php:31` adds POS receipt line eco-tax fields.
- `apps/api/database/migrations/tenant/2026_05_01_000005_add_eco_tax_fields_to_document_lines.php:25` to `apps/api/database/migrations/tenant/2026_05_01_000005_add_eco_tax_fields_to_document_lines.php:31` adds document line eco-tax fields.
- `apps/api/app/Modules/POS/Domain/ReceiptLine.php:92` to `apps/api/app/Modules/POS/Domain/ReceiptLine.php:118` marks eco-tax as forward compatibility and says Phase 1 always null.
- `apps/api/app/Modules/Document/Domain/DocumentLine.php:96` to `apps/api/app/Modules/Document/Domain/DocumentLine.php:126` does the same for document lines.

Problem:
France health/hygiene rates in the spec align with the official Service Public page checked during review: metropolitan hygiene/cosmetics at 20%, non-reimbursed human medicines at 10%, reimbursed medical/pharmaceutical products at 2.1%, and some health/hygiene products at 5.5%. The codebase also already has eco-tax placeholders, but the spec says nothing about FODEC or other non-VAT levies for Tunisia. I did not find a source-code model, seeder, or rule named FODEC; only generic `eco_tax_*` placeholders exist. If FODEC is legally relevant to the target parapharmacy catalog, TVA+timbre-only seeding will still be incomplete.

Recommended fix:
Add an explicit "non-VAT levies decision" section: either out of scope with rationale and a tracking item, or add FODEC/eco-tax modeling requirements. Do not silently imply TVA+timbre exhausts all Tunisian fiscal charges.

## MINOR Findings

### m1 - Spec path for `DraftPersistenceService` is stale, though the line behavior is correct

Spec section: Spec 1, section 1.1.

File:line evidence:
- `docs/superpowers/specs/2026-06-12-tenant-tax-provisioning-and-resolution-design.md:68` to `docs/superpowers/specs/2026-06-12-tenant-tax-provisioning-and-resolution-design.md:70` cites `DraftPersistenceService:266`.
- `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:256` to `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:267` is the actual path and line.

Problem:
The behavioral claim is correct, but the spec's implied module path is stale if implementers search under `Application/Services`.

Recommended fix:
Update the spec reference to `app/Modules/Document/Domain/Services/DraftPersistenceService.php:266` and include the batch path at line 611.

### m2 - Product request tax-config validation lacks the coherence rule the spec wants categories to use

Spec section: Spec 1, section 3.3.

File:line evidence:
- `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php:53` to `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php:62` explains country-scoped configs but only uses `exists`.
- `apps/api/app/Modules/Product/Presentation/Requests/UpdateProductRequest.php:55` to `apps/api/app/Modules/Product/Presentation/Requests/UpdateProductRequest.php:63` has the same pattern.
- `apps/api/app/Modules/Catalog/Presentation/Requests/StoreCompositeItemRequest.php:52` to `apps/api/app/Modules/Catalog/Presentation/Requests/StoreCompositeItemRequest.php:62` includes `TaxConfigurationCountryCoherent`.

Problem:
The spec frames the category validation as matching Product plus coherence, but Product itself is weaker than composite items. This is not a crash risk, but it will preserve inconsistent data if users pick a wrong-country product tax config.

Recommended fix:
Use the composite request validation as the canonical pattern and bring Product along, or explicitly state that Product coherence is a separate follow-up.

### m3 - POS modal spec overstates the base `Modal` fixed-size guarantee

Spec section: Spec 2, sections 3 and 4.3.

File:line evidence:
- `docs/superpowers/specs/2026-06-12-pos-customer-search-modal-design.md:46` to `docs/superpowers/specs/2026-06-12-pos-customer-search-modal-design.md:47` claims reusable fixed `size`.
- `apps/pos/src/components/pos/Modal.tsx:12` to `apps/pos/src/components/pos/Modal.tsx:19` supports `size` and `closable`.
- `apps/pos/src/components/pos/Modal.tsx:56` to `apps/pos/src/components/pos/Modal.tsx:63` implements size as `max-w-*` and `max-h-*`, not fixed width/height.
- `apps/pos/src/components/pos/Modal.tsx:78` to `apps/pos/src/components/pos/Modal.tsx:79` scrolls only the body.

Problem:
`Modal` supports a size enum and close disabling, but `size="md"` is not a true fixed-size modal. Width is `w-full max-w-md`; height is only max-height. If the project convention is "modals never resize on interaction", the new customer modal will need an internal fixed/min height or a content shell that reserves space for selected/search/create states.

Recommended fix:
In `CustomerSearchModal`, add a stable content container height for search/create/selected states, or extend `Modal` with a fixed-height variant if that is a project-wide convention. Test the selected-customer/account-payment state and search/create state for no resize.

### m4 - Moving account-payment into a modal needs a close-on-success/stacking decision

Spec section: Spec 2, sections 4.1 and 7.

File:line evidence:
- `apps/pos/src/pages/HomePage.tsx:1344` to `apps/pos/src/pages/HomePage.tsx:1349` passes `onAccountPaymentComplete={() => setShowSuccessModal(true)}` to the inline panel.
- `apps/pos/src/components/customers/CustomerAttachPanel.tsx:166` to `apps/pos/src/components/customers/CustomerAttachPanel.tsx:185` processes account payment, clears the amount, and calls `onAccountPaymentComplete`.
- `apps/pos/src/stores/paymentStore.ts:1242` to `apps/pos/src/stores/paymentStore.ts:1310` sets `lastReceipt` and updates `selectedCustomer` after account payment.
- `apps/pos/src/components/pos/Modal.tsx:31` to `apps/pos/src/components/pos/Modal.tsx:39` disables Escape only when `closable` is false.

Problem:
There is no inline-layout dependency in the account-payment logic itself: it uses local input state and the payment store. But in a modal, the existing completion callback will open the success modal. If `CustomerSearchModal` stays open, the UI may stack success on top of customer search and leave stale modal state behind.

Recommended fix:
Define the modal lifecycle for account-payment success: close `CustomerSearchModal` before or immediately after showing success, and pass `closable={!isProcessing}` while `processAccountPayment` is in flight.

## NIT Findings

### n1 - HomePage line references in Spec 2 are accurate

Spec section: Spec 2, sections 1 and 4.2.

File:line evidence:
- `apps/pos/src/pages/HomePage.tsx:1343` to `apps/pos/src/pages/HomePage.tsx:1349` shows `CustomerAttachPanel` above the cart panel body.
- `apps/pos/src/pages/HomePage.tsx:1350` to `apps/pos/src/pages/HomePage.tsx:1378` shows `TransactionCart` below it.
- `apps/pos/src/pages/HomePage.tsx:34` imports `CustomerAttachPanel`.

Problem:
No problem with the cited current-state map. This is a verified claim.

Recommended fix:
Keep these line refs or update them after implementation.

### n2 - `selectedCustomer` is the checkout attach source, but it is also consumed by account charge/payment flows

Spec section: Spec 2, section 3.

File:line evidence:
- `apps/pos/src/stores/paymentStore.ts:231` to `apps/pos/src/stores/paymentStore.ts:236` defines `selectedCustomer`.
- `apps/pos/src/stores/paymentStore.ts:349` to `apps/pos/src/stores/paymentStore.ts:353` exposes `attachCustomer` and `detachCustomer`.
- `apps/pos/src/stores/paymentStore.ts:1524` to `apps/pos/src/stores/paymentStore.ts:1545` mutates `selectedCustomer`.
- `apps/pos/src/components/customers/CustomerAttachPanel.tsx:55` to `apps/pos/src/components/customers/CustomerAttachPanel.tsx:58` reads `selectedCustomer`, `attachCustomer`, `detachCustomer`, and `processAccountPayment`.
- Repository grep found the only non-test `CustomerAttachPanel` consumer at `apps/pos/src/pages/HomePage.tsx:1344`.
- `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:161` to `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:167` also reads `selectedCustomer` for account charge eligibility.

Problem:
The store-shape claim is correct and retiring the panel will not break other panel consumers, but `selectedCustomer` is not just a visual chip source. It drives account payment and account charge flows.

Recommended fix:
Keep `selectedCustomer` in `paymentStore` and do not move customer attachment into local HomePage/modal-only state.

### n3 - Customer search/create logic is local and movable, but it currently has hardcoded strings

Spec section: Spec 2, sections 3 and 4.3.

File:line evidence:
- `apps/pos/src/components/customers/CustomerSearchInput.tsx:37` to `apps/pos/src/components/customers/CustomerSearchInput.tsx:45` searches the local SQLite repository scoped by tenant and company.
- `apps/pos/src/components/customers/CustomerAttachPanel.tsx:80` to `apps/pos/src/components/customers/CustomerAttachPanel.tsx:149` creates a pending local customer and immediately attaches it.
- `apps/pos/src/components/customers/CustomerAttachPanel.tsx:191` to `apps/pos/src/components/customers/CustomerAttachPanel.tsx:291` contains hardcoded English UI text such as `Customer`, `Attached`, `Amount`, `Record`, and `Create local customer`.

Problem:
Moving the body into a modal is straightforward, but the spec says all strings must use `t()`. The existing component does not.

Recommended fix:
When extracting modal content, migrate visible strings to the POS customer namespace and update tests to query localized labels where appropriate.

## Overall Verdict

NEEDS-REWORK.

Spec 1 has the right core diagnosis: registration seeds only TN configs, FR has no config seeder, runtime tax calculation matches by country and line rate, and the category tax fields are dormant. The design is not implementation-safe yet because it misses real company creation paths and assumes a product creation chokepoint that does not exist. Those gaps would leave zero-tax products/companies after the implementation.

Spec 2 is much closer. The HomePage and store claims mostly check out, and `CustomerAttachPanel` has no other non-test consumer. The required edits are smaller: do not overstate `Modal` fixed sizing, define account-payment modal lifecycle, and keep `selectedCustomer` as the store source of truth.
