# K-7 Import-Time Platform Enrichment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the product-import `enrichment_enabled` option perform bounded, tenant-safe background catalogue enrichment and accurately expose its outcomes.

**Architecture:** Both synchronous and asynchronous imports dispatch one tenant-anchored `EnrichImportedProductsJob` only after import finalization. The job binds the tenant database and company context, uses the shared catalogue lookup contract, writes backlinks through one company-scoped product service shared with product creation, and stores a typed enrichment summary that the existing import API merges with row warnings.

**Tech Stack:** Laravel 12, PHP 8.2 enums/readonly DTOs, Eloquent tenant databases, Laravel queues, React 19, TypeScript strict, TanStack Query, Vitest, PHPUnit.

**Spec:** `docs/sessions/session-K-otospex-money-2026-08-30/LANE-K7-import-time-enrichment-BRIEF.md`

## Global Constraints

- Implement every C1-C12 condition in the binding v2 brief and gate register.
- Keep all catalogue calls sequential and cap each import at 500 distinct normalized barcodes.
- Use constructor injection and enums; do not use `app()` in production code.
- Preserve K-6 changes in `ImportWizardPage.tsx`, `queries.ts`, the provider route guard, and their tests.
- Add no route, do not edit `ImportErrorCode`, do not run browser/Playwright, and do not commit or push.
- Run touched PHPUnit files by path only, with the specified PostgreSQL database names for PG-specific behavior.

---

### Task 1: Typed lookup and backlink contracts

**Files:**
- Create: `apps/api/app/Shared/Enums/CatalogLookupOutcome.php`
- Create: `apps/api/app/Shared/Contracts/CatalogLookupResultInterface.php`
- Modify: `apps/api/app/Shared/Contracts/CatalogLookupInterface.php`
- Modify: `apps/api/app/Modules/PlatformIntegration/Application/DTOs/BarcodeLookupResultData.php`
- Modify: `apps/api/app/Modules/PlatformIntegration/Application/Services/BarcodeLookupService.php`
- Create: `apps/api/app/Modules/Product/Application/Services/CatalogBacklinkDispatcher.php`
- Modify: `apps/api/app/Modules/Product/Application/Jobs/ApplyCatalogEnrichmentJob.php`
- Modify: `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php`
- Modify: `apps/api/app/Modules/Product/Presentation/Console/RunEnrichmentCommand.php`
- Test: `apps/api/tests/Feature/Product/CreateProductPlatformBacklinkTest.php`
- Test: `apps/api/tests/Feature/Product/ApplyCatalogEnrichmentJobTest.php`

**Interfaces:**
- `CatalogLookupInterface::normalizeBarcode(string): ?string`, `lookup(string, ?string): CatalogLookupResultInterface`, `isCircuitOpen(): bool`, and the existing `lookupCatalogProduct()`.
- `CatalogLookupResultInterface::outcome(): CatalogLookupOutcome` and `platformProductId(): ?string`.
- `CatalogBacklinkDispatcher::linkAndApply(string $productId, string $companyId, string $tenantId, string $platformProductId, string $barcode, string $vertical): bool` validates UUID, performs a company-scoped update, and dispatches `ApplyCatalogEnrichmentJob` on `enrichment`.

- [ ] Add failing tests proving the shared dispatcher writes only the requested company's product, rejects a non-UUID platform id, and carries the tenant id into the apply job.
- [ ] Run both touched product test files and confirm failures name the missing dispatcher/tenant payload.
- [ ] Implement the typed lookup outcome seam and dispatcher, then refactor product creation to call it.
- [ ] Wrap all `ApplyCatalogEnrichmentJob` database work in `BindsTenantContext::withTenantContext()` and retain the `CompanyContext` set/finally-clear bracket.
- [ ] Re-run both touched product test files green.

### Task 2: Queued import enrichment and durable summary

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_09_01_100000_add_enrichment_summary_to_import_jobs.php`
- Create: `apps/api/app/Modules/Import/Domain/Data/ImportEnrichmentSummaryData.php`
- Create: `apps/api/app/Modules/Import/Domain/Casts/ImportEnrichmentSummaryCast.php`
- Modify: `apps/api/app/Modules/Import/Domain/ImportJob.php`
- Create: `apps/api/app/Modules/Import/Application/Jobs/EnrichImportedProductsJob.php`
- Create: `apps/api/app/Modules/Import/Application/Services/ImportEnrichmentDispatcher.php`
- Modify: `apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php`
- Modify: `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php`
- Test: `apps/api/tests/Feature/Import/ImportTimeEnrichmentTest.php`

**Interfaces:**
- `ImportEnrichmentDispatcher::dispatchIfEnabled(ImportJob, string $companyId, string $tenantId): bool` dispatches only terminal product jobs with `enrichment_enabled=true`.
- `ImportEnrichmentSummaryData` stores `enriched` and the five enrichment warning counters as non-negative integers and exports API keys verbatim.
- `EnrichImportedProductsJob(importJobId, tenantId, companyId)` runs on `enrichment`, accepts `CatalogLookupInterface`, `CatalogBacklinkDispatcher`, and `CompanyContext` in `handle()`, and updates only a terminal job's summary.

- [ ] Write failing feature tests for sync and 100-row async dispatch, cleared company context, found-with-images dispatch, every lookup outcome, circuit stop, 500-distinct cap, normalized dedupe, UUID rejection, rerun zero calls, company scoping, and typed summary merging.
- [ ] Run the new test by path and record the expected red failures.
- [ ] Add the guarded tenant migration, DTO/cast/model property, import dispatcher, and enrichment worker.
- [ ] Dispatch after synchronous finalization and after the async worker wins terminal finalization, while still inside tenant context.
- [ ] Merge the stored enrichment summary with computed row warnings in terminal `formatJob()` responses; keep non-terminal summaries null and make history list terminal summaries durable.
- [ ] Re-run the new test and touched existing import tests green on SQLite, then run the new test on PostgreSQL with the K-7 database names.

### Task 3: Warning-code and documentation contract

**Files:**
- Modify: `apps/api/app/Modules/Import/Domain/Enums/ImportWarningCode.php`
- Modify: `apps/web/src/features/import/warningCodes.ts`
- Modify: `apps/web/src/locales/en/import.json`
- Modify: `apps/web/src/locales/fr/import.json`
- Modify: `apps/web/src/locales/ar/import.json`
- Modify: `docs/modules/imports.md`
- Modify: `apps/api/tests/feature-lane-manifest.json`
- Test: `apps/web/src/features/import/__tests__/ImportWarningLocales.test.ts`

- [ ] Extend the locale test first so all five new warning values fail against the old enum/locales.
- [ ] Add `enrichment_not_found`, `enrichment_unavailable`, `enrichment_invalid_barcode`, `enrichment_cap_exceeded`, and `enrichment_vertical_not_supported` everywhere in the five-file contract.
- [ ] Recompute the manifest from the current worktree union rather than incrementing stale G-2/G-5 values; document whether existing headroom changes the numeric ceiling.
- [ ] Run the locale test and manifest checker green.

### Task 4: Capability flag and import UI

**Files:**
- Modify: `apps/api/app/Http/Controllers/Api/CompanyConfigController.php`
- Test: `apps/api/tests/Feature/Api/CompanyConfigControllerTest.php`
- Modify: `apps/web/src/contexts/CompanyConfigContext.tsx`
- Modify: `apps/web/src/test/fixtures/companyConfig.ts`
- Modify: `apps/web/src/features/import/pages/ImportWizardPage.tsx`
- Modify: `apps/web/src/features/import/pages/ImportHistoryPage.tsx`
- Modify: `apps/web/src/features/import/__tests__/ImportWizardPage.options.test.tsx`
- Create: `apps/web/src/features/import/__tests__/ImportHistoryPage.test.tsx`
- Modify: `apps/web/src/locales/{en,fr,ar}/import.json`

- [ ] Add failing backend capability tests for non-empty platform key plus supported vertical, empty key, and unsupported vertical.
- [ ] Add failing Vitest coverage for toggle visibility, enrichment-only options step, PATCH persistence, completion enriched/warning rendering, and durable history rendering.
- [ ] Expose `platform_enrichment_available` from the existing company-config endpoint and add it to the strict frontend config contract/fixtures.
- [ ] Preserve K-6 preview guards while adding `optionVisibility.enrichment`, `shouldShowOptionsStep`, the toggle, and PATCH value.
- [ ] Render `enriched` separately from warnings on completion and history; render the five coded warning translations when counts exist.
- [ ] Run the touched backend and frontend tests green.

### Task 5: Push-disabled distinction and truthful toast

**Files:**
- Modify: `apps/api/app/Modules/PlatformIntegration/Application/Services/ProductSubmissionService.php`
- Modify: `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/ProductSubmissionController.php`
- Modify: `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/PhotoUploadUrlController.php`
- Test: `apps/api/tests/Feature/Modules/PlatformIntegration/ProductSubmissionControllerTest.php`
- Test: `apps/api/tests/Feature/Modules/PlatformIntegration/ProductSubmissionCorrelationTest.php`
- Modify: `apps/web/src/features/inventory/ProductForm.tsx`
- Modify: `apps/web/src/features/inventory/ProductForm.test.tsx`
- Modify: `apps/web/src/locales/{en,fr,ar}/inventory.json`

- [ ] Add failing backend tests that disabled submit and photo upload return 422 `enrichment_submission_disabled` while genuine platform failure remains 502.
- [ ] Add failing ProductForm tests that disabled and unavailable submission errors show coded error messages and never show the enrichment-success toast.
- [ ] Add `submissionEnabled(): bool`, guard both controllers with the explicit signal, and leave `EnrichmentRefreshController` untouched.
- [ ] Await the submission mutation before showing success; map disabled/unavailable errors to translated messages and return on failure.
- [ ] Run the touched backend and ProductForm tests green, including the existing correlation outage test.

### Task 6: Dev fixture, local evidence spec, glossary, and handoff

**Files:**
- Create: `apps/api/app/Modules/PlatformIntegration/Infrastructure/Fixtures/DevelopmentCatalogLookupStub.php`
- Modify: `apps/api/app/Providers/AppServiceProvider.php`
- Modify: `apps/api/config/services.php`
- Modify: `apps/api/.env.example`
- Create: `apps/web/e2e-local/k7-import-enrichment.spec.ts`
- Modify: `docs/glossary.md`
- Create: `docs/sessions/session-K-otospex-money-2026-08-30/lane-k7-summary.md`

- [ ] Add a local-only, opt-in `SYNERIVA_PLATFORM_DEV_LOOKUP_STUB` binding with stable found/not-found responses and no image URLs.
- [ ] Write (but do not run) the local Playwright spec covering toggle-on import, warnings/backlink evidence, rerun, second company, zero 5xx, and zero console errors.
- [ ] Add glossary rows for the shared platform-enrichment trigger surfaces and tenant-asset/per-company-attachment image semantics.
- [ ] Run `CACHE_STORE=array php artisan typescript:transform` because the warning enum changed.
- [ ] Run scoped PHPUnit SQLite/PG commands, scoped Vitest, touched-file PHPStan, `pint --test`, typecheck/lint as applicable, and React Doctor; fix only K-7 regressions.
- [ ] Write the summary with files changed, verbatim command counts, deviations/blockers, union arithmetic, dev-stub name, and every collision file touched.

