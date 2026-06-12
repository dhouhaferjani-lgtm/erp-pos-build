# Catalog Media Backend Foundation Plan Review - Round 2

Date: 2026-06-12
Reviewer: Codex adversarial verification, round 2
Plan reviewed: `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md`
Spec reviewed: `docs/superpowers/specs/2026-06-12-catalog-media-backend-foundation-design.md`

Note: the round-1 review summary says 17 findings, but the file contains 18 heading-level findings; this review covers all 18.

## 1. Round-1 findings table

| ID | Severity | Title | Status | Justification |
|---|---|---|---|---|
| R1-01 | HIGH | Task 15 final reference check is too narrow/impossible | PARTIALLY RESOLVED | Task 15 Step 5 now searches `apps/api/app`, `apps/api/tests`, seeders, `packages/shared`, `apps/web`, and `apps/pos` while excluding the historical/drop migrations (`plan:1177-1184`), but the same regex still matches intentional POS local-cache `product_images` (`apps/pos/src/lib/db/migrations.ts:243-250`) and therefore echoes `STILL REFERENCED` before the manual exception note (`plan:1184-1191`). |
| R1-02 | HIGH | Existing tests importing deleted classes are not assigned/run | RESOLVED | Task 15 Step 3c explicitly migrates/replaces every old image test before deletion (`plan:1153-1160`) and Step 6 verifies key old files are gone (`plan:1197-1200`). |
| R1-03 | LOW | Plan references non-existent Task 17 | RESOLVED | The deletion note now says Task 15 and names the Task 8/Task 14 prerequisites (`plan:53`), and the watch-item also points to Task 15 (`plan:1221`). |
| R1-04 | HIGH | `MediaAttachmentData::fromModel()` reintroduces static DTO service-boundary problem | RESOLVED | Task 5 makes `MediaAttachmentData::fromModel($a, $url)` pure (`plan:667-678`) and Task 6 injects `MediaUrlResolver` into `CatalogMediaQuery` to compute the URL (`plan:753-766`). |
| R1-05 | MEDIUM | `MediaAttachment` relation name is not explicit | RESOLVED | Task 3 defines the canonical `mediaAsset()` relation and forbids an `asset()` alias (`plan:498-505`), and Task 4 uses `mediaAsset` in `whereHas`/`with` (`plan:589-595`). |
| R1-06 | MEDIUM | POS call site omits tenant argument | RESOLVED | Task 6 defines `forProduct(string $productId, string $tenantId)` and `forProducts(array $productIds, string $tenantId)` (`plan:741-747`), and Task 14 requires POS sync to call `forProducts(..., $company->tenant_id)` once (`plan:1120`). |
| R1-07 | MEDIUM | Upload lifecycle contradicts spec | RESOLVED | Task 10 now asserts upload returns `MediaStatus::Uploaded` (`plan:952-958`) and implements `UPLOADED` followed by job-side `Uploaded->Processing->Ready` (`plan:973-978`, `plan:917-928`). |
| R1-08 | MEDIUM | Catalog service-provider path/glob inaccurate | PARTIALLY RESOLVED | Task 4 uses the real provider path `app/Modules/Catalog/Providers/CatalogServiceProvider.php` (`plan:531`, `plan:623`), but the top-level modified-file list still names stale `app/Modules/Catalog/CatalogServiceProvider.php` (`plan:42`) while the actual provider is under `Providers` (`apps/api/app/Modules/Catalog/Providers/CatalogServiceProvider.php:24-33`). |
| R1-09 | LOW | Public facade wording names deleted `canBeAccessedPublicly()` helper | RESOLVED | Task 12 now gates directly on `is_active_for_ecommerce` and says not to carry forward `ProductImage::canBeAccessedPublicly()` (`plan:1031-1038`), matching the current controller gate (`apps/api/app/Modules/Product/Presentation/Controllers/PublicProductImageController.php:23-29`). |
| R1-10 | HIGH | Repository eager loads not fully tenant-scoped | RESOLVED | Task 4 scopes the attachment, `whereHas('mediaAsset')`, `mediaAsset`, and `mediaAsset.renditions` eager loads by `tenant_id` (`plan:583-595`) and requires `find($id, $tenantId)` to scope both columns (`plan:605`). |
| R1-11 | MEDIUM | `GenerateRenditions` tenancy test does not prove scoping | RESOLVED | Task 9 seeds two tenants, asserts tenant B remains untouched (`plan:876-891`), and requires the job lookup to call `$assets->find($id, $tenantId)` (`plan:893`, `plan:917-924`). |
| R1-12 | HIGH | Facade test omits update/delete and tenant-isolation regressions | RESOLVED | Task 11 now tests index/download/store/PATCH/reorder/DELETE (`plan:998-1008`) and ports the old tenant-isolation regressions (`plan:1010-1018`). |
| R1-13 | MEDIUM | No-N+1 assertion misses controller/formatter path | RESOLVED | Task 13 adds a controller-level test expecting one `forProducts()` call and no `forProduct()` calls (`plan:1062-1070`) and instructs `index()` to remap with one batched media lookup instead of relying on the generic formatter (`plan:1075-1082`). |
| R1-14 | MEDIUM | ZIP import lacks concrete behavior test | RESOLVED | Task 14 adds `ProductImageImportServiceMediaTest` and asserts a ZIP creates one asset, one PRIMARY attachment, and returns the attachment id as `image_id` (`plan:1090-1095`, `plan:1110-1115`). |
| R1-15 | MEDIUM | Generated TS does not assert `ProductData.media` type | RESOLVED | Task 13 adds the constructor docblock and requires grepping generated types for `media:` under `ProductData` after `typescript:transform` (`plan:1075-1082`). |
| R1-16 | LOW | POS cache invariant not named | RESOLVED | Task 14 now asserts POS keeps one scalar `product.image_url`, no `media` array, and the `/images/...variant=sm` shape (`plan:1099-1108`, `plan:1120`). |
| R1-17 | MEDIUM | External URL validation not tied to a tested service path | RESOLVED | Task 15 routes seeding through `MediaUploadService::registerExternalUrl(...)` and adds validation tests rejecting `http`, invalid, overlong, localhost, and `127.0.0.1` URLs (`plan:1149-1151`). |
| R1-18 | LOW | MIME enforcement prose lacks exact allow-list | RESOLVED | Task 10 now gives the exact request rule and service constant (`plan:973-976`), matching the current allow-list (`apps/api/app/Modules/Product/Application/Services/ProductImageService.php:20-27`) and request rule (`apps/api/app/Modules/Product/Presentation/Controllers/ProductImageController.php:55-57`). |

## 2. New issues found

| ID | Severity | Title | Status | Justification |
|---|---|---|---|---|
| N2-01 | HIGH | Task 6 type-hints `MediaUrlResolver` before the class exists | OPEN | Task 6 creates `CatalogMediaQuery` with `private readonly MediaUrlResolver $urls` (`plan:751-756`) and calls it (`plan:764-766`), but Task 7 is the first task that creates `app/Modules/Catalog/Application/Services/MediaUrlResolver.php` (`plan:792-798`), so a strict task-by-task TDD implementation cannot compile/pass Task 6. |
| N2-02 | MEDIUM | Spec and plan disagree on `CatalogMediaQueryInterface` tenant arguments | OPEN | The revised plan consistently uses `forProduct($productId, $tenantId)` / `forProducts($productIds, $tenantId)` (`plan:741-747`, `plan:1082`, `plan:1120`), but the spec still defines no-tenant signatures (`spec:124-127`), leaving the source-of-truth contract inconsistent. |

## 3. Cross-cutting consistency checks

1. `ProductData::fromModel(?ProductMediaData $media = null)` and pagination: PASS. The null default in Task 13 preserves the existing `formatOffsetPaginatedResponse()` one-argument call (`apps/api/app/Support/Traits/PaginatesResults.php:70-74`, `plan:1075-1080`), and Task 13 explicitly tells `ProductController::index()` to inject media via one `forProducts()` call and an explicit remap (`plan:1082`) instead of letting the formatter produce the final DTOs.
2. Controller no-N+1 test using `$this->mock()`: PASS, with style caveat. The project has no current `$this->mock(` usages in `apps/api/tests`, but Laravel's base testing trait provides `mock()` and binds it into the container (`apps/api/vendor/laravel/framework/src/Illuminate/Foundation/Testing/Concerns/InteractsWithContainer.php:75-77`), so the proposed Task 13 test is technically valid.
3. `MediaUrlResolver` ordering: FAIL. `CatalogMediaQuery` needs `MediaUrlResolver` in Task 6 (`plan:751-756`), but the class is introduced only in Task 7 (`plan:792-798`) and does not exist in the current codebase, so Task 6 is ordered incorrectly.
4. `forProduct`/`forProducts` signatures and `mediaAsset` relation consistency: PARTIAL PASS. The plan is internally consistent on tenant-aware method signatures across Tasks 6, 13, and 14 (`plan:741-747`, `plan:1082`, `plan:1120`) and on `mediaAsset` naming across Tasks 3/4/5/6 (`plan:498-505`, `plan:589-595`, `plan:671`, `plan:764-766`), but the spec still shows old no-tenant signatures (`spec:124-127`).

## 4. Final verdict + confidence %

VERDICT: REJECT

Confidence: 87%

Counts: round-1 still-open/partial = 2 (1 HIGH, 1 MEDIUM); new issues = 2 (1 HIGH, 1 MEDIUM).
