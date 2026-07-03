# Unified Imports Phase 0-2 Plan Review

Review target: `docs/superpowers/plans/2026-07-03-unified-imports-phase0-2.md`

Compared against:

- Spec: `docs/superpowers/specs/2026-07-02-unified-imports-design.md` FINAL v3
- Actual repo code under `apps/api`, `apps/web`, `packages/shared`

Scope: phases 0-2 only. Phase 3 enrichment/prefill/placeholders/background submit was treated as out of scope unless the plan accidentally pulled it in.

## Findings

### BLOCKER - Task 8 retry idempotency cannot work with the actual `import_file_reference` contract

The plan treats `opening_balance_batches.import_file_reference` as a scalar import job id and builds both lookup and uniqueness around that: `OpeningBalanceBatch::where('import_file_reference', $job->id)` and `updateFileReference($batch, $job->id)` (plan:460-475). Actual code defines the column as `jsonb` (apps/api/database/migrations/tenant/2025_12_11_100000_create_opening_balance_tables.php:23), casts it as `array` (apps/api/app/Modules/Accounting/Domain/OpeningBalanceBatch.php:32,95), and `OpeningBalanceBatchService::updateFileReference()` requires `array<string,mixed>` (apps/api/app/Modules/Accounting/Application/Services/OpeningBalanceBatchService.php:462-474).

Implemented literally, Task 8 either fails PHPStan/type checks by passing a string to `updateFileReference()`, or persists/query-compares JSON in a way that never finds the existing batch. The proposed unique index on `(import_file_reference, type)` is also ambiguous over a jsonb object/value and will not enforce "one batch per import job/type" unless the plan first changes the storage contract or indexes an expression such as `(import_file_reference->>'import_job_id', type)`.

Fix the plan before implementation: define the exact stored JSON shape, lookup expression, migration SQL, and service call. Example: `['import_job_id' => $job->id, 'source' => 'unified-import']` plus expression index/lookups.

### BLOCKER - New `ImportService` constructor dependencies are not wired into the manual service provider

The plan repeatedly says to inject new production dependencies into `ImportService`: `PartiesRowMapper` in Task 7 (plan:438-446), finalize phases in Task 8 (plan:487), and product price/tax/opening-stock collaborators in Task 14 (plan:620-627). But `ImportService` is not autowired: `ImportServiceProvider` manually constructs it with a fixed argument list (apps/api/app/Modules/Import/Providers/ImportServiceProvider.php:33-44). Task 7 and Task 14 file lists omit `ImportServiceProvider` (plan:402-405,614-617), so following the plan literally creates a constructor/provider mismatch and the container fails before tests run.

Fix the plan: every task that changes `ImportService::__construct()` must also update `app/Modules/Import/Providers/ImportServiceProvider.php`, and the tests should include a container-resolution assertion or any HTTP test that reaches the service.

### BLOCKER - Task 1's test skeleton does not compile in this repo as written

The first task's test uses `$this->createCompanyWithUser()` and `$this->testUserId` (plan:83-95). `Tests\TestCase` only resets the time limit and defines no such helper/property (apps/api/tests/TestCase.php:7-27). The referenced neighboring setup is not a helper; it is explicit per-test setup with private `$tenant`, `$company`, and `$user` properties (apps/api/tests/Feature/Accounting/OpeningBalanceBatchTest.php:51-107).

The note says to adapt setup (plan:119), but the sample code is the worker's starting point and would fail literally with undefined method/property. Since Task 1 is the prerequisite for all later parties work, the plan should include a real compiling fixture helper or tell implementers to copy the exact `OpeningBalanceBatchTest` setup and use `$this->user->id`.

### BLOCKER - Task 13 violates the spec's product idempotency requirement for generated SKUs

The spec says blank `sku` is auto-generated and "deterministic so re-import stays idempotent" (spec:97-100). The plan instructs `sku = $fileSku ?? ($barcode ?: strtoupper((string) \Illuminate\Support\Str::ulid()))` (plan:600-604). `Str::ulid()` is not deterministic; a row with no file SKU and no barcode duplicates on every re-import.

This is not just implementation detail: the spec explicitly requires idempotency. The plan needs a deterministic key strategy for name-only phase-2 products, or it must narrow the claim and add a duplicate-risk warning/test. As written, Task 13's test "blank sku + no barcode -> 26-char ULID sku" (plan:608) proves non-idempotency rather than preventing it.

### MAJOR - The plan relaxes the spec's Shared Contracts boundary for AR/AP and inventory services

The spec requires Import to consume cross-module behavior through `Shared/Contracts` interfaces only (spec:32-43), and explicitly calls for exposing AR/AP opening and product-level opening stock through shared contracts (spec:39-40). The plan's architecture repeats "Cross-module access only via `Shared/Contracts`" (plan:8), but later consumes concrete services directly: `ArApOpeningService`, `OpeningBalanceBatchService` (plan:457), and `OpeningBalancePostingService` plus DTOs (plan:620).

This may work in Laravel, but it is not the boundary the spec asked for. It also increases PHPStan/deptrac risk because Import would depend directly on Document/Accounting/Inventory application services. Either add the missing shared contracts to the plan or explicitly amend the spec/boundary rule.

### MAJOR - Task 8 includes a direct `Partner` model lookup/write path inside Import

Task 8 says to resolve missing partner codes with `Partner::find($row->imported_entity_id)->code`, and if null set the partner's code (plan:469-470). The same paragraph then says a boundary-clean simplification is to generate the code before the partner phase via `PartnerServiceInterface` (plan:470). Leaving both instructions is hazardous: the literal first path violates the cross-module rule, while the second path is the correct direction.

Actual Import already uses `PartnerServiceInterface` for partner writes (apps/api/app/Modules/Import/Services/ImportService.php:28,349-354). Delete the direct-model instruction and make pre-partner-phase code generation the only accepted behavior.

### MAJOR - Parties balance date default contradicts the spec and the repo has no named company opening date source

The spec says `balance_date` defaults to the company opening date (spec:59). Task 8 defaults to `$job->created_at->toDateString()` (plan:469-470). The current `Company` model has fiscal-year fields, but no obvious `opening_date` property or migration column (apps/api/app/Modules/Company/Domain/Company.php:56-63; apps/api/database/migrations/tenant/2025_11_30_104000_create_companies_table.php:65).

The plan needs a concrete rule: derive from the active fiscal year start, add/use an existing onboarding opening-date setting, or change the spec. Job creation date is not equivalent and will create wrong historical document dates.

### MAJOR - Products opening-stock task misses the spec's locked-period behavior

The spec requires quantity imports during a locked opening period to succeed as product updates but warn `opening_locked` and skip movement (spec:127-133,177-178). Task 14 only maps `OpeningAlreadyExistsException` to `opening_exists` and "any other domain exception" to `opening_locked` (plan:621-627). Actual `OpeningBalancePostingService::post()` checks duplicate active opening movements (apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:86-100), but it does not enforce an opening-period lock; ProductController uses it directly for inline opening stock as well (apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:492-512).

So the planned behavior is not implemented by the consumed service, and no explicit lock check is specified. The plan must name the actual lock source/check and add a test for locked inventory-opening period.

### MAJOR - Task 14 hardcodes currency scale `3` where the existing product-opening path resolves company scale

Task 14 constructs `OpeningBalanceLine::make(..., $qty4, $cost3, 3)` (plan:626). The existing `ProductController` resolves scale from company currency and passes that value (apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:489-510). `OpeningBalancePostingService` also resolves monetary scale from `$company->currency` for calculations (apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:59-63).

Hardcoding 3 may be consistent with the import staging convention, but it is inconsistent with the service's public DTO usage and could reject/format differently for non-3-decimal companies. The plan should explicitly reconcile "import storage scale 3" with the DTO's company-currency scale, or follow the existing controller pattern.

### MAJOR - Task 5 under-specifies all non-enum backend touchpoints for the new `Parties` case

The plan says `MigrationWizardService` only needs modification "if it switches on type" and template generation is enum-driven (plan:339-365). Actual `MigrationWizardService` has several concrete type switches/matches beyond enum columns: recommended order (apps/api/app/Modules/Import/Services/MigrationWizardService.php:23-31), dependencies (44-82), aliases (153-173), example rows (198-323), migration status (347-376), and metadata (384-417). Adding `ImportType::Parties` without updating every `match` arm will raise `UnhandledMatchError`; failing to update `switch`/status/order silently omits the new primary type.

The plan's "grep `ImportType::`" instruction helps, but Task 5 should list the exact methods and expected behavior. It also needs FR aliases requested by the spec (`solde`, `solde_client`, `solde_fournisseur`, etc.; spec:168), not just enum columns.

### MAJOR - Backend route examples in permission tests omit the actual `/api/v1` prefix

Task 9 says to assert `GET /imports` and `POST /imports` return 403/200 (plan:501). Actual routes are registered under `prefix('api/v1')` (apps/api/app/Modules/Import/Providers/ImportServiceProvider.php:59-70), and existing import tests use `/api/v1/imports` (apps/api/tests/Feature/Import/ImportTypesTest.php:98-115). Literal tests against `/imports` will hit the wrong route.

Fix the plan's test instructions to use `/api/v1/imports` and `/api/v1/migration-wizard/...`.

### MAJOR - Task 9 contradicts the plan's own test-running constraint

Global constraints say never run the full PHPUnit suite and "Run tests BY PATH only" (plan:14). Task 9 says to run `./vendor/bin/phpunit tests/Feature/Import/` by directory (plan:503). That is not a file path and will run the whole import feature directory, contradicting the constraint. Replace it with an explicit file list.

### MAJOR - Options PATCH implementation has an undefined-index path

Task 4's implementation says validate `options` as `sometimes|array`, then update with `$validated['options']` (plan:324-333). If a caller sends `{}` or a form/body that passes validation without `options`, `$validated['options']` is undefined. This is small to fix but will show up as a 500 in the new endpoint.

Make `options` required for PATCH, or use `$validated['options'] ?? []` and reject empty updates intentionally.

### MAJOR - Phase-2 i18n plan conflicts with actual supported Arabic import namespace

The spec requires all new FE strings in every supported locale file (spec:164). The plan says only `en`/`fr` are updated and "`ar` is a pre-existing stub; do not expand it" (plan:18,516,642,652). But Arabic is a supported language (apps/web/src/lib/i18n.ts:135-139), and the import namespace is loaded for Arabic by merging `enImport` with `arImport` (apps/web/src/lib/i18n.ts:131,345). The current Arabic import file is indeed a stub (apps/web/src/locales/ar/import.json:1-4), but "supported locale" still includes it.

If the product decision is to let Arabic fall back to English for this namespace, the plan should say this is a deliberate spec exception and add/update the i18n key coverage expectation accordingly. Otherwise add `ar/import.json` keys.

### NIT - Product brand implementation reference points at the wrong service

Task 13 says `brand` should use `Brand::firstOrCreate` "exactly like `EnrichmentReviewService::accept()`" (plan:605). Actual `EnrichmentReviewService::accept()` delegates to `BrandResolutionService` (apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:163-177). The direct `Brand::firstOrCreate` example is in `CatalogEnrichmentService` (apps/api/app/Modules/Product/Application/Services/CatalogEnrichmentService.php:78-85).

The intended behavior is probably fine, but the plan should point implementers at the correct code path to avoid duplicating slug/race handling differently.

### NIT - Warning rows count should decide how empty arrays are treated

Task 3 says `warning_rows` is `whereNotNull('warnings')->count()` (plan:299). `addRowWarning()` only writes non-empty arrays (plan:291-296), so this works for the planned helper. But if a future repair clears warnings to `[]`, those rows still count. Prefer a JSON-length predicate or document that warnings are cleared by setting `null`, not `[]`.

### NIT - Result workbook sheet names are a conscious spec reduction but should be called out in the task, not only self-review

The spec calls for three sheets: imported & enriched, imported pending/unavailable, and rejected (spec:150). Task 15 produces only `Imported` and `Rejected` during phase 2 (plan:639-640), while the self-review explains this as a phase-2 stand-in (plan:666-667). That is acceptable if phase 3 owns the split, but Task 15 itself should explicitly state this is a phase-scoped deviation so reviewers do not expect three sheets yet.

## Coverage Notes

The plan does cover the major phase-0/1/2 areas from the spec: AR/AP lifecycle repair, warnings/options, new `parties` API value, partner code upsert, signed balance quadrants, product price resolution, product upsert changes, product opening stock, permissions, FE dashboard/options, and result workbook.

The main missing or wrong coverage within scope is: scalar-vs-json idempotency for AR/AP batches, product SKU determinism, locked opening-period handling for product quantities, company opening-date source, shared-contract boundary work for AR/AP and inventory, and full i18n locale treatment.

## Verdict

NOT-READY

The plan is close in intent, but it is not safe to hand to implementers yet. At least the `import_file_reference` contract, `ImportServiceProvider` constructor wiring, Task 1 fixture, deterministic SKU rule, and boundary-contract decisions need to be repaired before execution starts.
