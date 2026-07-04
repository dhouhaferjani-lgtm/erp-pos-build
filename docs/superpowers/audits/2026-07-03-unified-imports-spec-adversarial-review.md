# Unified Imports Design Spec Adversarial Review

Reviewed `docs/superpowers/specs/2026-07-02-unified-imports-design.md` on 2026-07-03 against the current AutoERP monorepo implementation under `apps/api`, `apps/web`, and `packages/shared`.

## Summary

Verdict: **not ready for implementation**. The spec has several confirmed contract mismatches where it assigns behavior to existing services that the code does not provide, most critically around AR/AP opening balances, product validation/upsert, partner code matching, warning storage, and option persistence. Some of these are not just missing implementation details; they would produce incorrect accounting or fail at runtime if implemented literally. The design can become implementable, but it needs a contract-repair pass that explicitly defines API values, service ownership, row status semantics, permission gates, warning/result artifacts, and opening-balance accounting behavior before work starts.

## Blockers

### 1. Confirmed issue: Parties opening balances claim GL entries, but AR/AP opening balances explicitly do not post GL

**Evidence:** The spec says parties balances are created through `OpeningBalanceBatchService -> validate -> post` and that "GL entries, audit trail, batch visibility, and lock lifecycle are unchanged" (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:54`). It also requires a feature test where "parties import creates partners + posted AR/AP batches + GL entries" (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:136`). The existing AR/AP opening implementation says the opposite: `OpeningBatchType::affectsGL()` returns false for `ArOpenItems` and `ApOpenItems` with the comment "AR/AP items don't create GL" (`apps/api/app/Modules/Accounting/Domain/Enums/OpeningBatchType.php:37`), and `ArApOpeningService` documents "No GL entry created" (`apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php:37`).

**Why this is a blocker:** A literal implementation would create historical AR/AP documents but no ledger impact, while the spec and tests expect ledger impact. That is an accounting integrity failure, not a UI defect.

**Suggested fix:** Decide and document the accounting contract. Either create matching GL opening entries for AR/AP receivables/payables in addition to AR/AP documents, or state that AR/AP document import never posts GL and requires a separate accounting opening-balance import. Then update the test plan and user-visible copy to match.

### 2. Confirmed issue: The existing AR/AP post lifecycle appears unusable for the spec's "validate -> post" flow

**Evidence:** The spec relies on an existing "validate -> post" lifecycle for AR/AP balances (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:54`). `ArApOpeningService::postBatch()` calls `markRowsPosted()` before `markBatchValidated()` (`apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php:322` and `:326`). `OpeningBalanceBatchService::markBatchValidated()` then requires `validCount > 0` before it can validate (`apps/api/app/Modules/Accounting/Application/Services/OpeningBalanceBatchService.php:331` and `:349`). After rows are marked posted, there may be no valid rows left to count. The inventory path does the reverse order, validating before posting rows (`apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:267`).

**Why this is a blocker:** The parties balance phase may fail every AR/AP posting at runtime, even before unified imports adds any new behavior. The spec treats this as stable infrastructure, but current code contradicts that assumption.

**Suggested fix:** Make the AR/AP opening lifecycle a named prerequisite. Fix and regression-test `ArApOpeningService::postBatch()` before unified parties balances depend on it, or design the import to create only draft/validated batches until the lifecycle is repaired.

### 3. Confirmed issue: `ImportType::Parties` is a new API value, but the existing import contract only knows `partners`

**Evidence:** The spec chooses `ImportType::Parties` (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:22`) and also says "Old import types remain functional" (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:117`). Current backend enum cases are `partners`, `products`, `stock_levels`, `opening_balances`, `product_images`, and `composite_items` (`apps/api/app/Modules/Import/Domain/Enums/ImportType.php:9`). Current frontend types likewise only include `partners` and not `parties` (`apps/web/src/features/import/types.ts:3`), and the dashboard tile is keyed as `partners` (`apps/web/src/features/import/pages/ImportDashboardPage.tsx:23`).

**Why this is a blocker:** Adding `parties` is not a local enum tweak. It affects request validation, templates, smart mapping, generated/shared DTOs, frontend routing, history display, switch statements, and backwards compatibility with `partners`.

**Suggested fix:** State the exact external API value. If `parties` is new, add a compatibility matrix for `partners` vs `parties` and require updates to backend enum matching, templates, shared types, frontend unions, route handling, and history labels. If this is only a relabel of `partners`, keep the API value `partners` and define how balances are enabled without adding a second import type.

### 4. Confirmed issue: Partner `code` upsert key is specified, but the current partner service ignores `code`

**Evidence:** The spec says "`code` = external/legacy id; used as upsert key when present" (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:30`). `PartnerService::upsertWithTypeMerge()` currently chooses an existing partner by `vat_number` if present, otherwise by `name` (`apps/api/app/Modules/Partner/Application/Services/PartnerService.php:58` and `:66`), and the payload it writes does not include `code` (`apps/api/app/Modules/Partner/Application/Services/PartnerService.php:82`). Existing partner uniqueness is company-scoped by code (`apps/api/database/migrations/tenant/2025_12_30_195300_fix_multi_company_unique_constraints.php:19`).

**Why this is a blocker:** The import would not be idempotent for legacy-coded partners, and AR/AP opening rows resolve partners by `partner_code` (`apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php:124`). A balance row could fail to resolve the partner that the same import just created.

**Suggested fix:** Extend the partner import/application contract so `code` is persisted and preferred as the company-scoped upsert key. Define conflict behavior for an existing `code` with a different name/type, and either rename `tax_id` to existing `vat_number` or explicitly map `tax_id -> vat_number`.

### 5. Confirmed issue: Product barcode-only rows cannot pass current validation or service upsert

**Evidence:** The spec allows `name` to be optional when `barcode` is present (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:72`) and says `sku` is "auto-generated if blank" (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:73`). Current product import validation requires `name`, `sku`, and `type` for products (`apps/api/app/Modules/Import/Domain/Enums/ImportType.php:65`). The frontend target columns also mark product `name`, `sku`, and `type` as required (`apps/web/src/features/import/pages/ImportWizardPage.tsx:50`). `ProductService::upsert()` reads `$data['name']` and `$data['sku']` directly and upserts only by SKU (`apps/api/app/Modules/Product/Application/Services/ProductService.php:49`, `:101`, and `:109`).

**Why this is a blocker:** The core product workflow in the spec, especially enrichment-first barcode imports, will be rejected before enrichment or crash/behave incorrectly in the product service.

**Suggested fix:** Specify the backend contract changes: product rows must validate as `barcode OR name+sku`, SKU generation must be deterministic, and product upsert must define precedence when both SKU and barcode exist. Update `ImportType`, `ProductServiceInterface`, `ProductService`, frontend target columns, shared types, and tests together.

### 6. Confirmed issue: Product price and margin columns are specified but current product import ignores them

**Evidence:** The spec adds `sale_price_incl_tax`, `sale_price_excl_tax`, and `margin` with precedence rules (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:75` through `:90`). Current `ProductService::upsert()` only handles `sale_price` and `purchase_price`, plus barcode/tax/unit/category fields (`apps/api/app/Modules/Product/Application/Services/ProductService.php:49` through `:113`). The existing import enum optional fields likewise contain `sale_price` and `purchase_price`, not the new price-authority columns (`apps/api/app/Modules/Import/Domain/Enums/ImportType.php:42`).

**Why this is a blocker:** The sell-ready product import can report success while silently dropping the price fields that make the file sell-ready.

**Suggested fix:** Define an import-specific product DTO or application service that resolves TTC/HT/margin into canonical product fields using the existing money precision contract. Add explicit warnings/errors for ignored or conflicting price inputs and assert persistence in feature tests.

### 7. Confirmed issue: `import_jobs.options` is referenced, but the API has no option ingestion path

**Evidence:** The spec stores `price_authority`, `enrichment_enabled`, and `location_code` in `import_jobs.options` (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:87` and `:122`). The database/model can store options (`apps/api/database/migrations/tenant/2025_11_30_150000_create_import_tables.php:26`; `apps/api/app/Modules/Import/Domain/ImportJob.php:89`), but `ImportController::store()` validates only `file` and `type` (`apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:82`) and `ImportService::createJob()` has no options parameter (`apps/api/app/Modules/Import/Services/ImportService.php:42`). The wizard steps do not include a price-authority/options step (`apps/web/src/features/import/pages/ImportWizardPage.tsx:30`).

**Why this is a blocker:** Implementers cannot persist the controls the spec depends on. Defaults would become implicit behavior, which is exactly where pricing/import bugs tend to hide.

**Suggested fix:** Add an explicit options contract to the upload/create-job API, backend validation, frontend wizard controls, audit/history display, and tests. Document defaults and whether options can be edited after upload.

### 8. Confirmed issue: Warning storage is not compatible with current row validity semantics

**Evidence:** The spec says warnings such as `price_conflict` and `margin_without_cost` should be non-blocking and surfaced in the wizard/workbook (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:88`, `:90`, and `:130`). It also says warnings/provenance should ride inside existing `data`/`errors` (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:123`). Current validation stores validation failures in `errors`, and row validity is false when errors exist (`apps/api/app/Modules/Import/Services/ImportService.php:117` through `:160`). `ImportRow` has `errors` and `import_error`, but no warning channel (`apps/api/app/Modules/Import/Domain/ImportRow.php:38`).

**Why this is a blocker:** If warnings are stored in `errors`, they risk making rows invalid or being counted/exported as failed rows. If they are buried in `data`, the API/frontend has no clear contract for warning counts, display, or workbook export.

**Suggested fix:** Define a first-class warning contract, either a `warnings` key inside `data` with DTO/API support or a dedicated column. Keep warnings separate from validation errors, execution errors, and failed-row counts.

## Major Concerns

### 1. Confirmed issue: Product opening stock path names the wrong abstraction and omits existing constraints

**Evidence:** The spec says `quantity` creates opening inventory movement via `InventoryOpeningService` (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:93`). `InventoryOpeningService` operates on an `OpeningBalanceBatch`, validates rows with `product_code` and `location_code`, and requires an active product (`apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:118` through `:181`). Existing product creation uses `OpeningBalancePostingService` directly from the product module controller rather than `InventoryOpeningService` (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:56`). `OpeningBalancePostingService` also rejects an active existing opening movement for the same product/location (`apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:87`).

**Why it matters:** Product import needs a precise product-create-then-stock-create workflow. The current spec does not say what happens for inactive enrichment placeholders, existing opening movements, non-stock/service products, batch-tracked products, or missing unit cost.

**Suggested fix:** Specify the exact service and input contract. If this is product-level opening stock, use `OpeningBalancePostingService` after product creation and define behavior for duplicates, inactive products, product type eligibility, batch tracking, and locked opening state.

### 2. Confirmed issue: Batch-to-import traceability is underspecified against the existing schema

**Evidence:** The spec says batches are named `IMPORT-{job-short-id}-AR/AP` and "linked back to the import job through batch reference/metadata" (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:62`) while also saying no link table is needed (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:125`). `opening_balance_batches` has `name`, `source_system`, and `import_file_reference`, but no generic metadata or `import_job_id` column (`apps/api/database/migrations/tenant/2025_12_11_100000_create_opening_balance_tables.php:14` through `:31`). `OpeningBalanceBatchService::createBatch()` has no metadata or job-id parameter (`apps/api/app/Modules/Accounting/Application/Services/OpeningBalanceBatchService.php:53`).

**Why it matters:** Support, retries, and history cannot reliably answer which import created which batch/documents if the link is only a naming convention.

**Suggested fix:** Use the existing `import_file_reference` explicitly, or add an `import_job_id`/metadata field. Define idempotency for retrying a partially completed import that already created one of the batches.

### 3. Confirmed issue: Company-currency-only v1 conflicts with AR/AP default currency behavior

**Evidence:** The spec says "Company currency only in v1" (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:65`). `ArApOpeningService` defaults missing currency to `'TND'` (`apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php:215`) rather than resolving the current company currency.

**Why it matters:** A non-TND company can get AR/AP documents in the wrong currency unless the importer always injects a currency and the service validates it.

**Suggested fix:** Resolve company currency before building AR/AP rows and reject any non-company currency in v1. Better, update `ArApOpeningService` to use the same company currency source the rest of accounting uses.

### 4. Confirmed issue: Enrichment placeholder state is not backed by existing product fields

**Evidence:** The spec says barcode/no-name rows create inactive placeholders with `awaiting_enrichment` "in product metadata" (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:102`) but later admits the "exact carrier" must be confirmed (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:124`). Current products have enrichment fields `platform_product_id`, `platform_submission_id`, and `enrichment_status` (`apps/api/database/migrations/tenant/2026_03_28_100000_add_enrichment_columns_to_products_table.php:14`), but no generic metadata column. `EnrichmentStatus` has no `awaiting_enrichment` value (`apps/api/app/Shared/Enums/EnrichmentStatus.php:7`). `EnrichmentReviewService::accept()` updates selected fields and clears enrichment state, but does not activate inactive products (`apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:143` through `:175`).

**Why it matters:** The placeholder workflow can strand products in an inactive state with no queryable flag, no activation rule, and no clear way to build workbook sheet 2.

**Suggested fix:** Pick a concrete state model before implementation. For example, use `enrichment_status=pending/enriching` plus `is_active=false`, record `platform_submission_id`, and make accepted enrichment activate only placeholders created by import.

### 5. Confirmed issue: Background enrichment idempotency is overstated

**Evidence:** The spec says the background loop uses the existing single-submit API with "idempotency keys" (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:105`). `ProductSubmissionService::submit()` currently sends a new random UUID idempotency key on every call (`apps/api/app/Modules/PlatformIntegration/Application/Services/ProductSubmissionService.php:73`), so a retry is not idempotent from the caller's perspective.

**Why it matters:** Queue retries or partial failures can duplicate upstream submissions and complicate webhook correlation.

**Suggested fix:** Derive the idempotency key from stable import/product identity, such as tenant/company/import-job/product-or-barcode. Define when `platform_submission_id` is reserved and how webhook results correlate back to placeholders.

### 6. Confirmed issue: Backend import routes are not permission-gated at the operation level

**Evidence:** The spec only describes frontend hiding for parapharmacy advanced tiles while leaving API routes callable (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:116`). Backend import routes use `api`, `auth:sanctum`, `SetPermissionsTeam`, and `EnforceTokenTenantClaim`, but no `can:*` permission middleware (`apps/api/app/Modules/Import/Providers/ImportServiceProvider.php:59` through `:70`). Frontend routes are only guarded by `RequirePermission moduleKey="settings"` (`apps/web/src/routes/index.tsx:2056` through `:2084`).

**Why it matters:** Imports can create partners, products, inventory movements, accounting batches, and documents. The spec does not define who is authorized to perform each class of import, and frontend-only hiding is not an enforcement boundary.

**Suggested fix:** Define backend permissions for viewing imports, uploading/validating, executing, downloading failed rows/workbooks, and creating accounting/inventory side effects. Mirror those permissions in frontend gating.

### 7. Confirmed issue: Result workbook is specified but no API artifact contract exists

**Evidence:** The spec promises a downloadable Excel report/workbook from Done/History (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:12` and `:110`). Existing import API exposes only a failed rows CSV endpoint (`apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:455` through `:484`), and the sync execute response includes `failed_rows_csv_url` only (`apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:415` through `:429`). The frontend stores `failed_rows_csv_url`, not a workbook URL (`apps/web/src/features/import/pages/ImportWizardPage.tsx:117`).

**Why it matters:** The async path, history page, and enrichment reporting have no contract for when the workbook is generated, where it is stored, how long it lives, or how it is authorized.

**Suggested fix:** Define a `GET /imports/{id}/result-workbook` contract, file format, generation timing, authorization, retention, and response wrapping. Include async completion behavior.

### 8. Confirmed issue: Two-phase parties row semantics do not fit the current single-entity row model

**Evidence:** The spec says a row can import the partner successfully while the balance phase fails, leaving the partner imported (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:63`). Current import rows have one `imported_entity_id`, one `is_imported` boolean, and one `import_error` (`apps/api/app/Modules/Import/Domain/ImportRow.php:38` through `:47`). `ImportService::executeImport()` treats a row as success only if the row-level transaction completes (`apps/api/app/Modules/Import/Services/ImportService.php:247` through `:310`).

**Why it matters:** A single row can have mixed outcomes: partner created, AR/AP balance failed, maybe one side of a `both` partner succeeded and the other failed. The existing row model cannot represent that cleanly.

**Suggested fix:** Define row sub-results for partner, AR balance, and AP balance, or create child result records. Specify how success counts, failed-row exports, row retry, and history display work for partial rows.

### 9. Risk: Phase A enrichment lookup may make upload/validation slow or brittle

**Evidence:** The spec performs `ProductSubmissionService::bulkLookup()` during validation with a 5s budget and chunks of 100 (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:99`). Current upload parses and validates synchronously inside `ImportController::store()` with `set_time_limit(300)` (`apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:71`) and has a 10MB file limit (`apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:83`).

**Why it matters:** A network dependency in the validation path can make import creation nondeterministic. The spec does not state what happens when lookup times out, how many unique barcodes are allowed, how cache keys are scoped, or whether a lookup failure is warning-only.

**Suggested fix:** Treat Phase A lookup as best-effort and explicitly define timeout behavior, cache scope including tenant/company/vertical, unique-barcode limits, and whether upload can complete without lookup.

### 10. Confirmed issue: Frontend i18n is not specified for new UI text

**Evidence:** The spec introduces new UI labels and warnings such as "Business partners", "Products", "Advanced imports", "Which field is authoritative for sale price?", and workbook warning strings (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:85` and `:114` through `:116`). Existing import pages use the `import` namespace (`apps/web/src/features/import/pages/ImportDashboardPage.tsx:18`) and locale files exist (`apps/web/src/locales/en/import.json`), but the spec does not require translation keys or updates for other locales.

**Why it matters:** The repo has an i18n convention, and hardcoded frontend strings are a stated review concern. New import UI copy will otherwise land as English-only literals.

**Suggested fix:** Add an i18n section requiring translation keys for new cards, options, warnings, validation messages, workbook labels, and history labels.

### 11. Risk: Module-boundary ownership for cross-module work is ambiguous

**Evidence:** The spec keeps the new behavior "inside the existing Import module engine" (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:18`) but the requested behavior spans Partner, Product, Inventory, Accounting, Document, and PlatformIntegration services. Existing ImportService correctly depends on shared contracts such as `PartnerServiceInterface` and `ProductServiceInterface` (`apps/api/app/Modules/Import/Services/ImportService.php:25`), but the spec does not name new shared contracts for inventory opening, AR/AP opening, enrichment lookup/submission, workbook export, or brand/category enrichment.

**Why it matters:** Implementers may reach across module boundaries directly to meet the spec quickly, violating the hexagonal/module-boundary rule.

**Suggested fix:** Add an architecture section listing every new/changed Shared contract or Domain event needed by Import, and which module owns each side effect.

### 12. Risk: `both` partner balances are underspecified for signs and accounting direction

**Evidence:** The spec allows `type = customer|supplier|both` (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:29`) and allows signed `opening_balance` or side-specific balances (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:33` through `:35`). It maps positive customer balances to AR invoices, negative customer balances to customer credit notes, positive supplier balances to AP bills, and negative supplier balances to supplier credit notes (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:57` through `:60`). Partner cached balances must be non-negative (`apps/api/app/Modules/Partner/Domain/Partner.php:181`).

**Why it matters:** A `both` partner with only `opening_balance` is ambiguous unless the spec says whether it applies to AR, AP, net balance, or is invalid. Negative supplier/customer semantics also need explicit document total/open amount mapping because AR/AP service requires positive `total` and `open_amount` (`apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php:176` through `:189`).

**Suggested fix:** Require side-specific balances for `both`, or define a deterministic rule and examples. State the exact AR/AP row payload for each sign.

## Minor / Nits

### 1. Confirmed issue: Precision handling needs to name the canonical formatter, not only a regex

**Evidence:** The spec says money-like fields use regex `/^-?\d+(\.\d{1,3})?$/` and that `NumericFieldNormalizer` runs first (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:47`). The repo has canonical scale helpers such as `CurrencyScale` and `QuantityScale` (`apps/api/app/Shared/Domain/CurrencyScale.php:11`; `apps/api/app/Shared/Domain/QuantityScale.php:24`).

**Why it matters:** A regex says what is accepted; it does not define rounding, formatting, storage scale, or numeric-string handling through service boundaries.

**Suggested fix:** Require `CurrencyScale::bcformatStrict` or equivalent numeric-string formatting for money, and `QuantityScale` for quantities, after normalization and before persistence.

### 2. Confirmed issue: Tax-exclusive price resolution does not define the tax source when `tax_rate` is absent

**Evidence:** The spec says HT is converted to TTC using tax rate (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:89`) but `tax_rate` is optional (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:76`). Current product creation has tax-resolution behavior through `TaxResolutionService` in `ProductService` (`apps/api/app/Modules/Product/Application/Services/ProductService.php:21`).

**Why it matters:** Rows with `sale_price_excl_tax` and no `tax_rate` need a deterministic tax rate source or warning/error.

**Suggested fix:** State whether default company/category tax is used, whether missing tax is an error, and how the resolved tax rate is shown in warnings/provenance.

### 3. Confirmed issue: `brand` import is called "cross-vertical" but no storage/upsert contract is named

**Evidence:** The spec says `brand` upserts "shared cross-vertical Brand record" (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:80`). Current `ProductService::upsert()` ignores brand fields (`apps/api/app/Modules/Product/Application/Services/ProductService.php:49` through `:113`). Enrichment acceptance can `firstOrCreate` a brand by tenant/slug and assign `brand_id` (`apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:164`).

**Why it matters:** Brand ownership and company/tenant scope affect duplicate creation and vertical visibility.

**Suggested fix:** Name the owning module/service and scope rules for brand upsert.

### 4. Risk: `REALIGNMENT-LOG` path is outside `apps/erp`

**Evidence:** The spec instructs implementers to log the missing bulk-submit endpoint in `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:106`). Existing specs note this path lives at the monorepo root, outside `apps/erp` (`docs/superpowers/specs/2026-06-03-unit-price-disambiguation-rename-design.md:101`).

**Why it matters:** This app workspace cannot assume that file is writable from the current project root. Implementation handoff should call out the repo-root path explicitly.

**Suggested fix:** Write the path as `../../docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` from `apps/erp`, or say "monorepo-root docs".

## Unstated Assumptions

- **Risky:** The spec assumes AR/AP opening balances already create GL entries. Current code says they do not.
- **Risky:** The spec assumes AR/AP opening batches can be posted successfully through the current lifecycle. Current code order appears to invalidate that assumption.
- **Risky:** The spec assumes adding `ImportType::Parties` is a small extension. Existing backend, frontend, and shared types are all keyed around `partners`.
- **Risky:** The spec assumes product rows can validate with barcode but no name/SKU. Existing validation and product service require name/SKU.
- **Risky:** The spec assumes `import_jobs.options` can already be populated from the API/UI. It cannot.
- **Risky:** The spec assumes a product metadata carrier exists for `awaiting_enrichment`. Current product schema has no generic metadata column.
- **Risky:** The spec assumes warnings can share `errors`/`data` without changing row validity, failed-row exports, and frontend summaries. Current code has no warning channel.
- **Risky:** The spec assumes frontend hiding is enough for vertical-exclusive/advanced imports while import APIs remain callable.
- **Probably safe but should be stated:** Import jobs are tenant-scoped through the tenant database/tenant id, but generated batches/documents still need company-scoped IDs and links.
- **Probably safe but should be stated:** Queued imports use the `imports` queue (`apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:61`), so enrichment background work should not starve import execution or vice versa.

## Open Questions for the Spec Author

1. Is the external import type value definitely `parties`, or should the existing `partners` type be extended and relabeled?
2. Should unified parties balances create GL entries, AR/AP historical documents, or both? If both, which service owns each side effect?
3. Must AR/AP opening service be fixed before this implementation starts, or should unified imports avoid posting AR/AP batches for now?
4. For `type=both`, is a single `opening_balance` allowed? If yes, does it mean AR, AP, or net exposure?
5. What is the canonical product upsert key when SKU is blank and barcode is present? Is barcode unique per company?
6. How should product quantity import behave when an opening movement already exists for the product/location?
7. Can barcode-only inactive placeholders receive opening stock, prices, and purchase cost, or are those rows enrichment-only until accepted?
8. What exact backend permissions are required to upload, validate, execute, and download import artifacts?
9. What is the first-class warning API shape, and how are warnings counted separately from invalid/failed rows?
10. How is company currency resolved, and should AR/AP service be changed to avoid the current `'TND'` default?
11. When and where is the enrichment result workbook generated for async imports, and what URL does History use?
12. What are the cache key dimensions and timeout behavior for validation-time barcode lookup?
13. Which Shared contracts or events should Import use for inventory opening, AR/AP opening, enrichment submission, and workbook generation?

## Re-review (round 2)

### Per-blocker/major disposition table

| Original finding | Round-2 disposition | Evidence / verification |
|---|---|---|
| B1: GL entries claimed but AR/AP opening posts no GL | **Resolved** | The revised spec now states AR/AP open-item batches create historical non-fiscal documents and "do NOT post GL"; GL opening remains in Advanced accounting opening balances (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:87`). The test plan now asserts documents/balances and explicitly says "no GL assertion" (`:185`). |
| B2: AR/AP `postBatch` lifecycle unusable | **Resolved as prerequisite** | A blocking "Prerequisite 0" now names the lifecycle-order defect and requires a TDD repair before unified-import work (`:23`-`:30`), with an end-to-end AR batch regression listed in testing (`:183`). |
| B3: `parties` API value ripple | **Resolved** | The spec now declares `parties` as a new external API value and lists affected touchpoints: backend enum match arms, template, smart mapping, `ProcessImportJob`, shared DTO generation, FE union, wizard columns, dashboard, and history (`:163`). |
| B4: partner `code` ignored by service | **Resolved** | The cross-module contract extends partner upsert to persist `code` and use precedence `code -> vat_number -> name` (`:36`). The Parties table repeats `code` as company-scoped upsert key (`:53`), and the conflict rule is explicit (`:62`). |
| B5: barcode-only product rows cannot validate/upsert | **Partially resolved** | Validation is repaired with `barcode OR name` (`:98`), deterministic SKU generation (`:99`), type defaulting (`:100`), and barcode placeholder behavior (`:142`). Remaining ambiguity: `sku = barcode` when barcode is present (`:99`) conflicts with "match by `sku` when provided; else by `barcode`" (`:109`). If a generated SKU counts as "provided", the barcode fallback never runs and existing products with a different SKU but same barcode will not be matched. |
| B6: new price columns silently dropped | **Resolved** | The revised spec makes the Import resolver canonicalize TTC into the unchanged `sale_price` key before Product upsert (`:115`) and defines field precedence, authority persistence, conflict warnings, and default-tax fallback (`:117`-`:123`). |
| B7: no options ingestion path | **Resolved, with sequencing nit below** | `POST /imports` now accepts a validated `options` object and threads it through `ImportService::createJob()` into `import_jobs.options`; the wizard gains an options step and History displays options (`:154`). |
| B8: warnings would break row validity | **Resolved** | The spec now adds a nullable `import_rows.warnings` jsonb column, separate from `errors` and `import_error`, and says warnings never affect validity, success counts, or failed-row export (`:155`). |
| M1: wrong opening-stock service and missing constraints | **Partially resolved** | The service is corrected to `OpeningBalancePostingService`, explicitly not `InventoryOpeningService` (`:127`), and no-cost/duplicate/service/batch-tracked rules are specified (`:129`-`:132`). Remaining issue: locked opening stock is called a per-row error while "rest of the row still imports" (`:133`), but Error Handling says locked-period quantity marks the row failed (`:177`). See new issue 2. |
| M2: batch traceability | **Partially resolved** | The spec now uses `opening_balance_batches.import_file_reference = import job id` and names AR/AP batches by job short id (`:88`, `:173`). The retry rule only covers already-posted batches and does not specify uniqueness/concurrency or how to reconcile an existing draft/partially-populated batch after a failed retry (`:88`). |
| M3: AR/AP hardcoded `TND` default | **Resolved** | Prerequisite 0 includes replacing the hardcoded `'TND'` default with company-currency resolution and rejecting non-company currencies (`:28`). Parties execution injects company currency explicitly on every AR/AP row (`:85`), and v1 scope is company currency only (`:91`). |
| M4: placeholder carrier missing | **Resolved** | The revised spec adds `products.is_enrichment_placeholder`, sets `enrichment_status=pending`, defines placeholder creation, and defines activation/clear behavior through enrichment acceptance or manual completion (`:142`, `:170`). |
| M5: enrichment idempotency overstated | **Resolved** | The background submit contract now requires caller-supplied deterministic idempotency key `uuid5(tenant, company, import_job_id, barcode)` and records `platform_submission_id` for webhook correlation (`:145`). |
| M6: no backend import permission gate | **Resolved for permission, not for vertical gating** | The spec adds `imports.manage`, seeded to admin/owner roles, enforced on upload/validate/execute/download routes and mirrored in FE (`:162`). Separate vertical-gating gap remains for parapharmacy Advanced imports; see new issue 3. |
| M7: no workbook artifact contract | **Resolved** | A new `GET /imports/{id}/result-workbook` endpoint is specified, generated on demand, streamed as XLSX, with auth, sheets, warnings, and Done/History links (`:150`). |
| M8: parties two-phase row semantics | **Resolved for parties** | The spec now says balance failures are warnings, row success follows partner success, and sub-results ride `import_rows.data._results = {partner, ar_balance, ap_balance}` (`:89`). |
| M9: Phase A enrichment brittleness | **Resolved** | The revised spec defines a best-effort validation lookup with chunking, cache key, circuit-breaker awareness, 5s total budget, 5,000 unique-barcode cap, timeout behavior, and platform-outage degradation (`:139`). |
| M10: frontend i18n unspecified | **Resolved** | All new frontend strings must land in the `import` namespace in every supported locale, with no hardcoded literals (`:164`), and FE tests must assert locale keys exist (`:186`). |
| M11: module-boundary ambiguity | **Resolved** | The cross-module contracts table names the owning implementation module and Shared contract surface for Partner, Product, tax resolution, AR/AP opening, product-level opening stock, and enrichment submit/lookup (`:32`-`:43`). |
| M12: `both` partner sign ambiguity | **Mostly resolved** | The spec now says plain `opening_balance` is invalid for `type=both`, side-specific customer/supplier columns are required for balances, and the sign-to-document mapping is explicit (`:56`-`:58`, `:71`, `:80`-`:85`). Minor wording ambiguity remains for `both` partners with no opening balance; see new issue 6. |

### Newly introduced issues found in the new sections

#### 1. Major: Generated SKU and barcode upsert precedence can still create duplicates

**Evidence:** The product table says blank SKU becomes deterministic and specifically `sku = barcode` when barcode is present (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:99`). The upsert rule then says "match by `sku` when provided; else by `barcode`" (`:109`).

**Why this matters:** For a barcode-only row, the implementation may generate a SKU before upsert, making SKU "provided" and preventing the barcode match path from ever running. Existing products with the same barcode but a different SKU would be duplicated instead of updated.

**Suggested fix:** Define "provided" as "provided by the file, before generation", or specify the actual match order as: file SKU -> barcode -> generated SKU create. Add a test for existing product with barcode `X` and SKU `Y`, importing row barcode `X` with blank SKU.

#### 2. Major: Product opening-stock locked-period behavior contradicts row failure semantics

**Evidence:** Product opening stock says "Opening period locked -> per-row error on `quantity` ...; rest of the row still imports" (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:133`). Error Handling says locked-period quantity is a row error that marks the row failed (`:177`).

**Why this matters:** This recreates the same mixed-outcome problem the spec fixed for parties. A row cannot both be failed and have the product part imported unless the row model has product sub-results, or unless locked stock is downgraded to a warning.

**Suggested fix:** Choose one model. Either make locked stock a warning and skip the movement while product upsert succeeds, or add product sub-results analogous to parties: `{product, opening_stock}` with clear success counts, retry behavior, and failed-row export semantics.

#### 3. Major: Parapharmacy "Advanced imports" remains frontend-only vertical gating

**Evidence:** The spec says legacy tiles move to Advanced and "Routes and backends stay; nothing is deleted" (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:160`). It then says the parapharmacy Advanced section is hidden by FE gate via `useCompanyConfig()` vertical check (`:161`). The new backend permission is only `imports.manage` on import routes (`:162`); it does not enforce vertical eligibility.

**Why this matters:** If Advanced imports are meant to be unavailable for parapharmacy, FE hiding is not an enforcement boundary. A parapharmacy admin/owner with `imports.manage` can still call the old import endpoints directly.

**Suggested fix:** Decide whether Advanced imports are merely hidden or actually forbidden. If forbidden, add backend type-level gating for parapharmacy legacy types, with any support/admin bypass documented. If merely hidden, change the wording from "hidden entirely" to "not promoted in the UI" and document that API access remains supported for admin/operator use.

#### 4. Major: "Each phase independently shippable" conflicts with barcode-only placeholders

**Evidence:** Product validation now allows rows with `barcode OR name` (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:98`). The behavior that makes barcode-without-name rows safe is placeholder creation (`:142`), but Build order puts "Products unified, sell-ready" in phase 2 and enrichment placeholders in phase 3 (`:193`-`:194`), while still saying "Each phase independently shippable" (`:196`).

**Why this matters:** If phase 2 ships without phase 3, the spec permits barcode-only rows but has not shipped the placeholder carrier/activation behavior that makes them valid. Implementers will have to guess whether phase 2 rejects barcode-only rows, creates active products named by barcode, or pulls placeholder work forward.

**Suggested fix:** Either move placeholder creation into phase 2, or state that `barcode OR name` validation only becomes active in phase 3 and phase 2 still requires `name` when enrichment is unavailable.

#### 5. Major: Batch retry idempotency is incomplete for non-posted partial batches

**Evidence:** The spec says batch traceability uses `import_file_reference = import job id` and retry looks up by `import_file_reference + type`; "a posted batch is never re-posted (rows already posted are skipped)" (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:88`).

**Why this matters:** The rule only covers the clean case where a previous attempt fully posted the batch. It does not specify what happens when a retry finds a draft/validated batch with rows added but not posted, a partially populated batch, or two queue attempts racing without a unique constraint.

**Suggested fix:** Add a unique key or lock for `(import_file_reference, type)` and define retry reconciliation for draft/validated batches: reuse and diff rows, delete/rebuild if no side effects, or fail with an operator-visible recovery state.

#### 6. Nit: `type=both` balance wording can be read as requiring balances even when none are imported

**Evidence:** The column table says `opening_balance_customer` and `opening_balance_supplier` are the "required form for `type=both` rows" (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:57`-`:58`). Earlier, the import says opening-balance columns are optional (`:47`), and only plain `opening_balance` is explicitly invalid for `type=both` (`:71`).

**Why this matters:** A legitimate customer+supplier partner with no opening balance should not be rejected merely because both side-specific balance columns are blank.

**Suggested fix:** Reword to "required when a `type=both` row imports any opening balance; otherwise both may be blank."

#### 7. Nit: Options step placement is underspecified relative to mapping-dependent options

**Evidence:** Price authority is only asked when two or more price columns are mapped (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:118`), while options ingestion is specified only on `POST /imports` (`:154`).

**Why this matters:** This is implementable if the options step is after mapping and before job creation, but the spec does not say that. Putting the options step after job creation would need an update-options endpoint, which the spec does not define.

**Suggested fix:** State the wizard order explicitly: Upload -> Mapping -> Options -> Create/Validate job -> Execute -> Complete, or add a `PATCH /imports/{id}/options` endpoint before execution.

#### 8. Nit: Deterministic `uuid5` key needs exact namespace/string contract

**Evidence:** The spec requires `uuid5(tenant, company, import_job_id, barcode)` for background submit idempotency (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:145`).

**Why this matters:** UUIDv5 usually takes a namespace UUID plus one name string. Different implementations could serialize those four fields differently and generate different keys across retries or languages.

**Suggested fix:** Define the namespace UUID and name string exactly, for example `autoerp:import-enrichment:{tenant_id}:{company_id}:{import_job_id}:{normalized_barcode}`.

### Final verdict

**NOT-READY.** The v2 contract-repair pass is substantive: most original blockers and majors now have real design text and test expectations, especially GL-vs-AR/AP behavior, Prerequisite 0, warnings, options, permissions, and module contracts. It is still not ready to hand off because several repaired areas now conflict with each other: barcode-only validation depends on placeholder work scheduled later, generated SKU rules can bypass barcode upsert, product opening-stock errors contradict row success semantics, Advanced imports remain backend-callable for parapharmacy despite vertical hiding language, and batch retry idempotency is underdefined for partial batches. These are fixable spec edits, but implementers would currently have to guess on behavior that affects data integrity and access control.

## Re-review (round 3)

### Per-fix verdict table

| Round-2 finding | Verdict | Evidence / one-line justification |
|---|---|---|
| R2-1 file-provided-sku-only matching | **RESOLVED** | Product matching now says file-provided SKU is first, generated SKU "never participates in matching," and barcode-only rows reach barcode match (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:109`). |
| R2-2 locked-period qty -> warning with sub-results | **CONTRADICTS-SPEC** | Opening stock now says locked opening is warning `opening_locked`, movement skipped, product row succeeds, with `data._results.opening_stock` (`:133`), but Error Handling still says "locked-period quantity" is a row error that marks the row failed (`:177`). |
| R2-3 parapharmacy reworded as UI demotion, not access ban | **RESOLVED** | Section 3 explicitly says Advanced imports are "not promoted in the UI," "not an access ban," and legacy endpoints remain callable for users with `imports.manage` (`:161`-`:162`). |
| R2-4 barcode-OR-name relaxation deferred to phase 3 | **RESOLVED** | Product `name` remains required in phase 2, with `barcode OR name` only in phase 3 (`:98`), and accept-then-enrich repeats that the relaxation activates in phase 3 with placeholders (`:142`). |
| R2-5 partial unique index + draft-batch reconciliation | **RESOLVED** | Batch traceability now requires a partial unique index on `(import_file_reference, type)`, posted batches skip, draft/validated batches are rebuilt and posted, and other states warn `balance_batch_unrecoverable` (`:88`). |
| R2-6 both-row balance wording | **RESOLVED** | `opening_balance_customer` is required only when a `type=both` row imports any opening balance, otherwise side columns may be blank; supplier column follows the same rule (`:57`-`:58`). |
| R2-7 wizard order + PATCH options endpoint | **RESOLVED** | Options now include `PATCH /imports/{id}/options` before execution, 409 afterward, and explicit wizard order `Upload -> Mapping -> Options -> Validate -> Execute -> Done` (`:154`). |
| R2-8 exact UUIDv5 namespace/name contract | **RESOLVED** | Phase B now specifies UUIDv5 namespace `Uuid::NAMESPACE_URL` and exact name string `autoerp:import-enrichment:{tenant_id}:{company_id}:{import_job_id}:{normalized_barcode}` (`:145`). |

### Newly discovered contradictions or gaps

- **Major:** R2-2 is still internally contradictory. The opening-stock section makes locked opening stock a warning with successful product import (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:133`), but Error Handling still classifies locked-period quantity as a row error (`:177`).
- **Nit:** The warning list omits the newly specified `opening_locked` warning. Error Handling lists row warnings as `balance_not_posted`, `price_conflict`, `margin_without_cost`, `tax_unresolved`, `qty_without_cost`, and `opening_exists` (`:178`), but `opening_locked` is introduced in Opening stock (`:133`).

### Final overall verdict

**NOT-READY.** V3 resolves R2-1 and R2-3 through R2-8 in the actual spec text, and those fixes do not introduce new contradictions I could find. The remaining R2-2 contradiction is small to edit but material to implement: one section tells implementers to count locked opening stock as a non-blocking warning with a successful product row, while Error Handling still tells them to mark the row failed. Until that is corrected, the row-status, failed-export, warning-count, and retry behavior for locked stock remains ambiguous.

## Re-review (round 4, final)

**READY.** Verified the single remaining R2-2 issue against the current spec text: §2 Opening stock now defines locked opening periods as warning `opening_locked`, movement skipped, product row succeeds, with stock sub-results in `data._results.opening_stock` (`docs/superpowers/specs/2026-07-02-unified-imports-design.md:133`), and Error Handling no longer lists locked-period quantity as a row error; it lists `opening_locked` and `balance_batch_unrecoverable` as row warnings and explicitly says locked-period quantity is `opening_locked` with the product row succeeding (`:177`-`:178`). I found no remaining contradiction on that point, so the unified-imports spec is ready for implementation from this review's perspective.
