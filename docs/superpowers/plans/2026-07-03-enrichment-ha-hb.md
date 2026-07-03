# Enrichment H-A (FOUND path) + H-B (capture panel) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** (H-A) Persist the platform backlink and auto-apply the FOUND lookup payload as an accepted enrichment result; (H-B) photo-first capture panel on not_found with nullable brand, server-normalized barcode, correlation safety, and ~45s fast-path polling with an apply-now card.

**Architecture:** ERP monorepo — Laravel 12 hexagonal modules (`apps/api`), React 19 + TanStack Query (`apps/web`). Cross-module communication ONLY via `App\Shared\Contracts` interfaces. Multi-tenant db-per-tenant (Stancl); `QueueTenancyBootstrapper` is enabled, so jobs dispatched from tenant-context requests re-initialize tenancy in the worker automatically.

**Tech Stack:** PHP 8.2 strict types, PHPStan level 8, PHPUnit (RefreshDatabase + RolesAndPermissionsSeeder), Vitest + Testing Library, react-hook-form, react-i18next.

**Parent docs:**
- Handovers: `/Users/houssamr/Projects/syneriva/docs/superpowers/handovers/2026-07-02-enrichment-ha-found-path.md` and `...-hb-capture-panel.md`
- Spec: `/Users/houssamr/Projects/syneriva/docs/superpowers/specs/2026-07-02-enrichment-loop-design.md` (§3, §4.1, §4.2)

## Global Constraints

- TDD every task: failing test first, then minimal implementation, then commit. One commit per task.
- **NEVER run the full PHPUnit suite** — run tests BY PATH only (e.g. `./vendor/bin/phpunit tests/Feature/Product/...`). Never run PHPUnit and Vitest concurrently.
- Strict typing: no `mixed` leaks in new signatures (DTOs), no `any` in TS.
- Constructor injection with `private readonly` ONLY — `app()` helper is forbidden.
- Cross-module: PlatformIntegration ↔ Product communicate only via `App\Shared\Contracts` / `App\Shared\DTOs` / events. Never import a model across modules.
- All new user-facing FE strings via `t()` (react-i18next). Add keys to **en, fr, ar** locale files of the namespace you touch (check `apps/web/src/i18n/` or `apps/web/public/locales/` — follow existing layout).
- New `.tsx` code uses design tokens from `@/lib/designTokens` — no hardcoded Tailwind colors (ESLint enforces in new dirs).
- Enums for every new status/type value. Events are immutable (we add none).
- After changing any `#[TypeScript]` PHP DTO run `php artisan typescript:transform` and commit the generated diff in `packages/shared/types/`.
- **Photo uploads: ≤5 MB** (5_242_880 bytes) — the platform contract note supersedes the handover's 10 MB figure. Platform submit returns **200 + the SAME tracking_id** when merging into the lookup-created submission (201 on fresh); **422 `invalid_barcode`** for barcodes with no alphanumeric characters; photo uploads are partner-bound (403 on foreign photo_ids, 413 on oversize).
- Platform track 1 (nullable brand, merge-or-create submit, photo ownership) is NOT deployed yet — end-to-end platform testing is out of scope; build against the contract, verify with feature tests + mocked platform client (existing patterns: `tests/Feature/Modules/PlatformIntegration/ProductSubmissionControllerTest.php`, `ProductSubmissionCorrelationTest.php`, and `tests/Feature/PlatformIntegration/BarcodeLookupTest.php`).

## Verified code map (claims validated 2026-07-03 against origin/dev 275bd2573)

| Fact | Location |
|---|---|
| `handleProductData` prefills only name/description/barcode | `apps/web/src/features/inventory/ProductForm.tsx:222-237` |
| `suggestedProductRef` already holds `platform_product_id` (typed) | `ProductForm.tsx:156`, `apps/web/src/features/inventory/types/platform.ts` (`SuggestedProduct`) |
| `CreateProductRequest` has NO `platform_product_id` rule → stripped by `validated()` | `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php:161-283` |
| `Product` fillable includes `platform_product_id` | `apps/api/app/Modules/Product/Domain/Product.php:120` |
| store() spreads `$validated` into `Product::create` inside `DB::transaction` | `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:346-450` |
| Stale comment claiming platform_product_id "is set during barcode lookup" | `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:88` |
| `accept()` applies name/description/barcode/brand; **NO ingredients path exists**; sets `enrichment_status=null` | `EnrichmentReviewService.php:75-145` |
| `enrichment_results.tracking_id` is **NOT NULL + unique** | `apps/api/database/migrations/tenant/2026_03_28_100001_create_enrichment_results_table.php:18,31` |
| Barcode normalization (private) — trim, strip non-alnum, UPC-12→EAN-13, EAN-13 check digit | `apps/api/app/Modules/PlatformIntegration/Application/Services/BarcodeLookupService.php:98-140` |
| Lookup result caches 1h under `platform:lookup:{vertical}:{normalizedBarcode}`; `BarcodeLookupResultData.barcode` IS the normalized value | `BarcodeLookupService.php:16-93` |
| Submission controller REQUIRES brand, passes RAW barcode, hardcodes `photoIds: []` | `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/ProductSubmissionController.php:34-76` |
| `ProductSubmissionData.brand` is non-null `string` | `apps/api/app/Modules/PlatformIntegration/Application/DTOs/ProductSubmissionData.php:19` |
| `requestUploadUrl` / `uploadPhoto` exist but NO route exposes them | `apps/api/app/Modules/PlatformIntegration/Application/Services/ProductSubmissionService.php:26-53`, `apps/api/app/Modules/PlatformIntegration/Presentation/routes.php` |
| `correlateSubmission` handles tracking-id conflict only via QueryException → generic 409 | `apps/api/app/Modules/Product/Infrastructure/Services/ProductEnrichmentCorrelationService.php:46-74` |
| Refresh endpoint exists: `POST /products/{productId}/enrichment/refresh` → `ManualEnrichmentRefreshService` (fetchAndStore on completed) | `apps/api/app/Modules/Product/routes.php:131`, `.../Application/Services/ManualEnrichmentRefreshService.php` |
| Review accept endpoint: `POST /enrichment-results/{id}/accept` (`can:enrichment.review`); index has NO product_id filter | `apps/api/app/Modules/Product/routes.php:125-137`, `EnrichmentReviewController.php` |
| `enrichment` queue already consumed by Horizon defaults (rule 20 satisfied) | `apps/api/config/horizon.php:209` |
| Ingredient = global slug-keyed model + translations; pivot `product_ingredient` ↔ `ParapharmacyProductMetadata`; creation pattern to mirror | `apps/api/app/Modules/Product/Domain/Ingredient.php`, `IngredientController.php:106` |
| `parapharmacy_product_metadata.category` NOT NULL; `ParapharmacyCategory::Other` exists | migration `2026_01_05_105259`, enum line 18 |
| FE submit payload/API | `apps/web/src/features/inventory/api/platformApi.ts`, `api/platformQueries.ts` |
| Capture-panel mount point: opt-in checkbox block on not_found | `ProductForm.tsx:695-705`; onSubmit not_found branch `ProductForm.tsx:483-499` (note `brand: suggestedProductRef.current?.brand ?? ''` — empty-string bug) |
| Hero component (do NOT put panel inside it) | `apps/web/src/features/products/editor/components/BarcodeHero.tsx` |

## Locked design decisions (do not relitigate)

1. **H-A payload source = server-side re-fetch, queued.** The FE sends only `platform_product_id`; the server never trusts a client-supplied enrichment payload. `ProductController::store` dispatches `ApplyCatalogEnrichmentJob` (queue `enrichment`) after commit; the job re-runs the lookup through a new Shared contract (cache hit is the normal case — FE looked the barcode up seconds earlier, 1h TTL). On platform miss/unavailability the job logs and exits — product stays created, no retry storm.
2. **Auto-accept is a NEW service method**, not a call into `accept()` — `accept()` clears `enrichment_status` to null and requires an existing PendingReview row; the FOUND path needs `enrichment_status=completed` and a row born `accepted`.
3. **Ingredients application is NEW code** (nothing exists today): parapharmacy-vertical tenants only; ensure a `parapharmacyMetadata` row (create with `category` mapped from classification when it matches `ParapharmacyCategory::tryFrom`, else `Other`); upsert `Ingredient` by slug mirroring `IngredientController::store` (translations included, locale `config('app.locale')`; supply `is_allergen: false`, `regulatory_status: null` — the DB defaults). **Pivot warning (review B3):** `product_ingredient.id` is a uuid PRIMARY KEY with NO default and the relation has no `->using()` pivot model — a bare `attach`/`syncWithoutDetaching` violates NOT NULL. Attach with an explicit id per row: `$metadata->ingredients()->syncWithoutDetaching([$ingredient->id => ['id' => (string) Str::uuid()]])` (mirror `ParapharmacySeeder.php:1011-1012`), and only for ingredient ids not already attached. If the tenant is NOT parapharmacy, skip ingredients silently.
4. **Brand: user input wins.** Auto-accept sets `brand_id`/`brand_source=Enriched` only when the product's `brand_id` is null (store() already set `brand_source=User` when the creator picked a brand).
5. **Barcode normalization is enforced server-side** by extracting the existing private logic into a `BarcodeNormalizer` class used by BOTH `BarcodeLookupService` and `ProductSubmissionController`. The FE keeps sending the form value; parity is guaranteed at the API. (Satisfies "expose from the service rather than re-implement in the frontend".)
6. **Correlation safety = pre-check via the Shared correlator contract** (PlatformIntegration must not read the Product model): new interface method returning the holder's `{productId, productName}`; controller returns 409 with holder details; the unique-constraint catch stays as the race backstop.
7. **Fast-path polling lives on `ProductDetailPage`** (review M1: post-create navigation `nav.goToRecord` lands on `/inventory/products/{id}` → ProductDetailPage, NOT the edit form), keyed on `product.enrichment_status === 'pending'` AND the `enrichment.view` permission (the refresh route sits under `can:enrichment.view` — poll without it = 5×403). Also mount in ProductForm edit mode (cheap reuse, covers users who click straight into Edit). Backoff 3/5/8/13/21s (~50s total ≈ the ~45s ask), stop on terminal status / error response / unmount. Accepted cost: each tick is one platform `checkStatus` call — max 5 per pending-product visit.
8. **`enrichment_results.tracking_id` becomes nullable** (migration; PG unique indexes treat NULLs as distinct, keep the same unique index). Catalog-hit rows have `tracking_id=null`, `reviewed_by=null` (system), `status=accepted`.
9. **No local image persistence** — FOUND images keep rendering from `images[].url` (already the case). Photo files for H-B go straight to the presigned URL from the browser.

---

### Task 1: `CreateProductRequest` accepts `platform_product_id`; store() persists it

**Files:**
- Modify: `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php` (rules array)
- Test: `apps/api/tests/Feature/Product/CreateProductPlatformBacklinkTest.php` (new)

**Interfaces:**
- Produces: `POST /api/v1/products` accepts optional `platform_product_id: uuid|null` and persists it to `products.platform_product_id`.

- [ ] **Step 1: Failing feature test** — mirror an existing product-create feature test's setup (tenant + company + auth + `RolesAndPermissionsSeeder`; copy the arrange block from the nearest existing test in `tests/Feature/Product/` that posts to `/api/v1/products`). Cases:

```php
public function test_create_persists_platform_product_id(): void
{
    $platformProductId = (string) Str::uuid();
    $response = $this->postJson('/api/v1/products', [
        'name' => 'Enriched Cream', 'sku' => 'SKU-ENR-1',
        'barcode' => '3017620422003',
        'platform_product_id' => $platformProductId,
    ]);
    $response->assertCreated();
    $this->assertDatabaseHas('products', [
        'id' => $response->json('data.id'),
        'platform_product_id' => $platformProductId,
    ]);
}

public function test_create_without_lookup_leaves_platform_product_id_null(): void
{
    $response = $this->postJson('/api/v1/products', ['name' => 'Manual', 'sku' => 'SKU-MAN-1']);
    $response->assertCreated();
    $this->assertNull(Product::find($response->json('data.id'))->platform_product_id);
}

public function test_invalid_platform_product_id_rejected(): void
{
    $this->postJson('/api/v1/products', ['name' => 'X', 'sku' => 'SKU-X', 'platform_product_id' => 'not-a-uuid'])
        ->assertStatus(422); // use Tests\Traits\AssertsApiValidation if the envelope helper exists here
}
```

- [ ] **Step 2: Run it** — `cd apps/api && ./vendor/bin/phpunit tests/Feature/Product/CreateProductPlatformBacklinkTest.php` → first test FAILS (column stays null).
- [ ] **Step 3: Implement** — add to `rules()` near `barcode`:

```php
'platform_product_id' => ['nullable', 'uuid'],
```

No store() change needed — `$validated` spread + fillable already persist it.
- [ ] **Step 4: Re-run → PASS.**
- [ ] **Step 5: Commit** `feat(product): accept + persist platform_product_id backlink on create`

### Task 2: Migration — `enrichment_results.tracking_id` nullable end-to-end + model/DTO/service doc fix

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_03_000001_make_enrichment_results_tracking_id_nullable.php`
- Modify: `apps/api/app/Modules/Product/Domain/EnrichmentResult.php` (PHPDoc `@property string|null $tracking_id`)
- Modify: `apps/api/app/Modules/Product/Application/DTOs/EnrichmentResultData.php` (**review B1**: `public string $tracking_id` → `public ?string $tracking_id`; this DTO is `#[TypeScript]` and is constructed from every row by `EnrichmentReviewController::index/show` — a null row would 500 the whole review queue otherwise). Run `php artisan typescript:transform`, commit generated diff.
- Modify: `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:87-89` (comment only)
- Test: add a case to the enrichment review controller test — index listing that includes a row with `tracking_id = null` returns 200 and serializes `tracking_id: null` (also covers the migration; full apply-path coverage lands in Task 3)

- [ ] **Step 1: Write migration**

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrichment_results', function (Blueprint $table) {
            $table->uuid('tracking_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Catalog-accepted rows have no tracking id; they cannot survive a
        // NOT NULL restore — delete them first or the change() fails.
        DB::table('enrichment_results')->whereNull('tracking_id')->delete();

        Schema::table('enrichment_results', function (Blueprint $table) {
            $table->uuid('tracking_id')->nullable(false)->change();
        });
    }
};
```

(Requires doctrine/dbal or native `->change()` on Laravel 12 — native works. The unique index `idx_enrichment_results_tracking` is untouched; PG uniques allow multiple NULLs.)
- [ ] **Step 2: Replace the stale comment** at `EnrichmentReviewService.php:87-89` with the truth:

```php
// Start with mandatory tracking clear; merge scalar-field updates on top.
// platform_product_id is written at product create (CreateProductRequest)
// when the product originated from a FOUND catalog lookup — it is NOT set
// by this review flow, which correlates via tracking_id (submission id).
```

- [ ] **Step 3: Commit** `chore(enrichment): nullable tracking_id for catalog-accepted results; fix stale backlink comment`

### Task 3: Shared catalog-lookup contract + `CatalogEnrichmentService` (auto-accept apply)

**Files:**
- Create: `apps/api/app/Shared/Contracts/CatalogLookupInterface.php`
- Create: `apps/api/app/Shared/DTOs/CatalogProductDTO.php`
- Modify: `apps/api/app/Modules/PlatformIntegration/Application/Services/BarcodeLookupService.php` (implement interface)
- Modify: `apps/api/app/Providers/AppServiceProvider.php` (~line 93 — bind interface exactly where `PlatformSubmissionInterface` and the other Shared contracts are bound)
- Create: `apps/api/app/Modules/Product/Application/Services/CatalogEnrichmentService.php`
- Test: `apps/api/tests/Unit/PlatformIntegration/CatalogLookupAdapterTest.php`, `apps/api/tests/Feature/Product/CatalogEnrichmentServiceTest.php`

**Interfaces:**
- Produces:

```php
// App\Shared\DTOs
final readonly class CatalogProductDTO
{
    /**
     * @param array<string, mixed> $classification
     * @param list<array{name: string, position: int}> $ingredients
     * @param list<array{url: ?string, thumbnail: ?string, type: ?string}> $images
     */
    public function __construct(
        public string $platformProductId,
        public string $barcode,
        public string $name,
        public ?string $brand,
        public ?string $description,
        public array $classification,
        public array $ingredients,
        public array $images,
        public int $confidenceScore,
        public ?string $enrichmentTier,
    ) {}
}

// App\Shared\Contracts
interface CatalogLookupInterface
{
    /** Normalizing lookup (cache-first). Null when not found / platform unavailable / invalid barcode. */
    public function lookupCatalogProduct(string $barcode, string $vertical): ?CatalogProductDTO;
}

// App\Modules\Product\Application\Services
final class CatalogEnrichmentService
{
    public function applyCatalogHit(Product $product, CatalogProductDTO $catalog): void;
}
```

- [ ] **Step 1: Failing adapter test** — `BarcodeLookupService::lookupCatalogProduct` returns a mapped `CatalogProductDTO` on found (fake `PlatformHttpClient` per existing lookup tests), null on not_found/error.
- [ ] **Step 2: Implement adapter** — `BarcodeLookupService implements CatalogLookupInterface`; method delegates to `$this->lookup($barcode, $vertical)` and maps `PlatformProductData` → `CatalogProductDTO` (`id`→`platformProductId`, rest 1:1). Bind in the module's provider. Note `lookup()` resolves vertical from CompanyContext only when `$vertical` is null — the contract always passes it explicitly, so the adapter is queue-safe.
- [ ] **Step 3: Failing `CatalogEnrichmentServiceTest`** (feature, RefreshDatabase, tenant setup; parapharmacy-vertical tenant). Cases:

```text
test_apply_creates_accepted_enrichment_result_and_completes_status
  — arrange product (brand_id null, platform_product_id set), DTO with brand "La Roche-Posay",
    ingredients [{name:"Aqua",position:1},{name:"Glycerin",position:2}], classification {"category":"cosmetic"}
  — assert: enrichment_results row: product_id, status='accepted', tracking_id NULL, reviewed_by NULL,
    accepted_fields covers applied fields, enriched_data captures payload
  — assert: products.enrichment_status='completed'
  — assert: brands row (tenant, slug) exists; products.brand_id set; brand_source='enriched'
  — assert: parapharmacy_product_metadata row created with category='cosmetic';
    2 ingredients exist (slugs aqua/glycerin) attached via product_ingredient
  — assert: NO enrichment_results row with status='pending_review'
test_apply_does_not_overwrite_user_brand
  — product created with brand_id set (brand_source='user') → brand_id unchanged, brand_source stays 'user'
test_apply_skips_ingredients_for_non_parapharmacy_vertical
  — automotive-vertical tenant → no metadata/ingredient writes; brand + status still applied
test_apply_maps_unknown_classification_category_to_other
test_apply_is_idempotent_for_existing_ingredients
  — pre-existing Ingredient slug 'aqua' → no duplicate; syncWithoutDetaching keeps one pivot row
```

- [ ] **Step 4: Implement `CatalogEnrichmentService`** — constructor takes no cross-module deps (all Product-module models). Single `DB::transaction` with the same unique-violation retry wrapper as `EnrichmentReviewService::accept()` (fresh-transaction retry for brand/ingredient races). Inside:
  1. `EnrichmentResult::create([...])` — status `EnrichmentReviewStatus::Accepted`, `tracking_id` null, `reviewed_at` now, `reviewed_by` null, `enriched_data` = `EnrichedProductData` mapped from the DTO (follow `fetchAndStore()`'s constructor call; pass ingredients as given; `confidence_score: $catalog->confidenceScore`, `enrichment_tier: $catalog->enrichmentTier` — both ARE carried by the DTO), `accepted_fields` map of what was applied, `enrichment_quality` `'catalog'`.
  2. Brand upsert (same `Brand::firstOrCreate` semantics as accept(), lines 104-116) **only if `$product->brand_id === null`**.
  3. Ingredients per locked decision 3 — parapharmacy vertical check via the product's tenant; mirror `IngredientController::store` creation incl. translations (`is_allergen: false`, `regulatory_status: null`); slug via `Str::slug`; **explicit pivot id on every attach** (see decision 3 — bare sync violates the pivot's uuid PK NOT NULL).
  4. `$product->update(['enrichment_status' => EnrichmentStatus::Completed, ...brand updates])`.
- [ ] **Step 5: Run both test files by path → PASS. PHPStan on changed paths** (`./vendor/bin/phpstan analyse app/Shared app/Modules/Product/Application/Services/CatalogEnrichmentService.php app/Modules/PlatformIntegration/Application/Services/BarcodeLookupService.php` or the project's configured invocation).
- [ ] **Step 6: Commit** `feat(enrichment): shared catalog-lookup contract + auto-accepted catalog apply service`

### Task 4: `ApplyCatalogEnrichmentJob` + dispatch from store()

**Files:**
- Create: `apps/api/app/Modules/Product/Application/Jobs/ApplyCatalogEnrichmentJob.php`
- Modify: `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php` (store(), after the create transaction)
- Test: `apps/api/tests/Feature/Product/ApplyCatalogEnrichmentJobTest.php` + a `Queue::fake()` dispatch assertion added to `CreateProductPlatformBacklinkTest.php`

**Interfaces:**
- Consumes: `CatalogLookupInterface`, `CatalogEnrichmentService` (Task 3).
- Produces: `ApplyCatalogEnrichmentJob::dispatch(string $productId, string $expectedPlatformProductId, string $barcode, string $vertical)` on queue `enrichment`.

- [ ] **Step 1: Failing job test** — bind a fake `CatalogLookupInterface`:
  - found + matching `platformProductId` → `CatalogEnrichmentService` effects asserted (enrichment_status completed etc.);
  - lookup returns null → product untouched EXCEPT `platform_product_id` is cleared to null (review M3: the backlink was persisted at create; if the catalog can no longer confirm it, remove it), no enrichment_results row, job does not throw;
  - lookup returns a DIFFERENT `platformProductId` than expected → skip apply AND clear `products.platform_product_id` (stale-FOUND persisted a wrong backlink — correct it), log warning.
- [ ] **Step 2: Implement job** — `ShouldQueue`, `Dispatchable`, `InteractsWithQueue`, `Queueable`, constructor promotes the four readonly strings; `handle(CatalogLookupInterface $lookup, CatalogEnrichmentService $enricher): void` (method injection is the Laravel-idiomatic exception to constructor injection for jobs — matches existing jobs). Re-fetch `Product::find($productId)` (null → return). Tenancy note: dispatched from tenant-context request; `QueueTenancyBootstrapper` restores the tenant DB in the worker — do NOT touch `CompanyContext` in the job.
- [ ] **Step 3: store() dispatch** — in `ProductController::store`, after the `DB::transaction(...)` block returns, when `$validated['platform_product_id']` and barcode are both present:

```php
$platformVertical = $company->tenant->vertical->platformVertical();
$platformProductId = $validated['platform_product_id'] ?? null;
$backlinkBarcode = $validated['barcode'] ?? null;

if (is_string($platformProductId) && is_string($backlinkBarcode) && $platformVertical !== null) {
    ApplyCatalogEnrichmentJob::dispatch(
        $product->id,
        $platformProductId,
        $backlinkBarcode,
        $platformVertical,
    )->onQueue('enrichment');
}
```

(`enrichment` queue is already in horizon defaults — no config change.) Add a `Queue::fake()` NEGATIVE test: manual create without `platform_product_id` dispatches nothing.
- [ ] **Step 4: Run tests by path → PASS.**
- [ ] **Step 5: Commit** `feat(enrichment): auto-apply FOUND catalog payload via queued job on product create`

### Task 5: FE — send `platform_product_id` from the FOUND suggestion

**Files:**
- Modify: `apps/web/src/features/inventory/ProductForm.tsx` (create mutation payload, onSubmit)
- Test: `apps/web/src/features/inventory/ProductForm.test.tsx` (extend)

- [ ] **Step 1: Failing Vitest** — following the file's existing test patterns: simulate a FOUND lookup (`handleProductData` via the hero's `onProductData` callback or by driving the mocked lookup hook — reuse the existing mock approach in `ProductForm.test.tsx`), submit, assert the `apiPost('/products', ...)` payload contains `platform_product_id` equal to the suggestion's id; second test: manual create (no lookup) → payload has NO `platform_product_id` key.
- [ ] **Step 2: Implement** — in the create mutation (`ProductForm.tsx:~370-388`), spread into `basePayload`:

```ts
// Review M3: lookupState does NOT reset to idle when the barcode is edited to a
// different ≥8-char value (useCatalogBarcodeLookup only idles below 8 chars), so a
// stale FOUND suggestion can outlive a barcode edit through the debounce window.
// Trust the suggestion only when its barcode still matches the form value.
const suggestion = suggestedProductRef.current
const platformProductId =
  lookupState === 'found' && suggestion && suggestion.barcode === data.barcode
    ? suggestion.platform_product_id
    : null
// include only when non-null:
...(platformProductId ? { platform_product_id: platformProductId } : {})
```

`lookupState` and `suggestedProductRef` are already in scope. Add a Vitest case: FOUND suggestion applied, then barcode edited to a different ≥8-char value, submit → payload has NO `platform_product_id`.
- [ ] **Step 3: Run → PASS** (`cd apps/web && pnpm test -- ProductForm`).
- [ ] **Step 4: Commit** `feat(web): send platform_product_id with create when FOUND suggestion applied`

### Task 6: Extract `BarcodeNormalizer` (shared normalization)

**Files:**
- Create: `apps/api/app/Modules/PlatformIntegration/Domain/Services/BarcodeNormalizer.php`
- Modify: `apps/api/app/Modules/PlatformIntegration/Application/Services/BarcodeLookupService.php` (constructor-inject, delegate; DELETE the private copies)
- Test: `apps/api/tests/Unit/PlatformIntegration/BarcodeNormalizerTest.php`

**Interfaces:**
- Produces: `final class BarcodeNormalizer { public function normalize(string $barcode): ?string }` — verbatim behavior of `BarcodeLookupService::normalizeBarcode` (`BarcodeLookupService.php:98-140`).

- [ ] **Step 1: Failing unit test** — vectors (compute real check digits!): valid EAN-13 passthrough (`'3017620422003'`), whitespace/dash stripping (`' 3017-6204-22003 '` → `'3017620422003'`), UPC-A 12-digit → prepend 0 (use a real UPC whose 13-digit form validates, e.g. `'036000291452'` → `'0036000291452'`), EAN-8 passthrough (`'96385074'`), alphanumeric passthrough (`'ABC123XYZ'`), symbols-only → null (`'!!!'`), bad EAN-13 check digit → null.
- [ ] **Step 2: Implement + rewire lookup service** (inject `BarcodeNormalizer`), run `tests/Unit/PlatformIntegration/` + existing lookup feature tests by path.
- [ ] **Step 3: Commit** `refactor(platform): extract BarcodeNormalizer from BarcodeLookupService`

### Task 7: Submission correctness — nullable brand, photo_ids, attributes, normalized barcode

**Files:**
- Modify: `apps/api/app/Modules/PlatformIntegration/Application/DTOs/ProductSubmissionData.php` (`public ?string $brand`)
- Modify: `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/ProductSubmissionController.php`
- Test: extend `apps/api/tests/Feature/Modules/PlatformIntegration/ProductSubmissionControllerTest.php`

**Interfaces:**
- Produces: `POST /api/v1/platform/submit-for-enrichment` accepts `brand: nullable`, `photo_ids: string[] (≤5)`, `attributes: object|null`; sends the platform the NORMALIZED barcode; 422 `invalid_barcode` when a supplied barcode normalizes to null.

- [ ] **Step 1: Failing tests:**

```text
test_submit_accepts_null_brand — brand omitted → 200, platform payload brand=null
test_submit_normalizes_barcode — post barcode ' 3017-6204-22003 ' → fake platform client
  received '3017620422003' (assert on the recorded postRaw payload)
test_submit_rejects_unnormalizable_barcode — barcode '!!!' → 422 error.code='invalid_barcode', no platform call
test_submit_passes_photo_ids_and_attributes — photo_ids [uuid,uuid] + attributes {volume:'50ml'} forwarded
test_submit_rejects_more_than_five_photo_ids
```

- [ ] **Step 2: Implement** — validation changes: `'brand' => ['nullable','string','max:255']`, `'photo_ids' => ['sometimes','array','max:5']`, `'photo_ids.*' => ['string','max:255']`, `'attributes' => ['sometimes','nullable','array']`. Constructor-inject `BarcodeNormalizer`; after validation, when barcode present: normalize, null → 422 `['error'=>['code'=>'invalid_barcode','message'=>...]]`; pass normalized into the DTO along with `photoIds: $validated['photo_ids'] ?? []`, `attributes: $validated['attributes'] ?? null`, `brand: $validated['brand'] ?? null`. Update `ProductSubmissionData` brand type + PHPDoc. (The DTO is constructed only at `ProductSubmissionController.php:66` + one unit test — widening to nullable breaks nothing else; `bulkSubmit` has zero app callers.)
- [ ] **Step 3: Run submission tests + bulk-import DTO construction tests by path; PHPStan on the module.**
- [ ] **Step 4: Commit** `feat(platform): nullable brand + photo_ids/attributes + server-normalized barcode on submit`

### Task 8: Correlation safety — holder pre-check, 409 with holder identity

**Files:**
- Modify: `apps/api/app/Shared/Contracts/EnrichmentSubmissionCorrelatorInterface.php` (new method)
- Create: `apps/api/app/Shared/DTOs/TrackingIdHolderDTO.php` (`public string $productId, public string $productName`)
- Modify: `apps/api/app/Modules/Product/Infrastructure/Services/ProductEnrichmentCorrelationService.php`
- Modify: `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/ProductSubmissionController.php`
- Test: correlation service unit/feature test + controller feature test (extend existing files)

**Interfaces:**
- Produces: `findTrackingIdHolder(string $trackingId, string $companyId): ?TrackingIdHolderDTO` on the correlator contract; submit endpoint 409 body gains `error.details.holder_product_id` + `holder_product_name`.

- [ ] **Step 1: Failing tests:**

```text
test_find_tracking_id_holder_returns_holder — product A holds tracking id T → DTO{A.id, A.name}
test_find_tracking_id_holder_null_when_unbound
test_submit_conflict_when_tracking_id_held_by_other_product
  — product A already bound to T; fake platform returns T for product B's submit (the platform
    merge path) → 409, error.code='enrichment_tracking_conflict',
    error.details.holder_product_id=A.id, holder_product_name=A.name;
    product B unchanged (platform_submission_id null, enrichment_status null)
test_submit_succeeds_when_tracking_id_returned_for_same_product — re-submit for A itself with
  platform returning A's existing T → 200 (idempotent merge; correlateSubmission may throw
  EnrichmentAlreadyPendingException — pre-check BEFORE assertSubmittable's already-pending 409
  is NOT required; keep current already-pending behavior, this test just pins whichever
  current-behavior status results — document actual in the test name if 409)
```

- [ ] **Step 2: Implement** — service: `Product::query()->where('platform_submission_id',$trackingId)->where('company_id',$companyId)->first()` → DTO or null. Controller: after `submit()` returns `$result`, call `findTrackingIdHolder($result->trackingId, $company->id)`; if holder exists and `holder->productId !== $validated['product_id']` → 409 with holder details and SKIP correlate. Keep the existing `EnrichmentCorrelationConflictException` catch as race backstop (on catch, re-query the holder to enrich the 409 body when found).
- [ ] **Step 3: Run by path → PASS.**
- [ ] **Step 4: Commit** `feat(enrichment): correlation-safety pre-check — surface holding product on tracking-id conflict`

### Task 9: Upload-url proxy endpoint

**Files:**
- Create: `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/PhotoUploadUrlController.php` (invokable)
- Modify: `apps/api/app/Modules/PlatformIntegration/Presentation/routes.php` (inside the existing auth'd `api/v1/platform` group)
- Test: `apps/api/tests/Feature/PlatformIntegration/PhotoUploadUrlTest.php`

**Interfaces:**
- Produces: `POST /api/v1/platform/upload-url` `{filename, content_type, size_bytes}` → relays `ProductSubmissionService::requestUploadUrl` response (`photo_id`, `upload_url`, whatever else the platform returns) under the standard `data` envelope. Permission `enrichment.submit`. 422 on `size_bytes > 5242880` or non-image `content_type`; 502 when the platform client returns null.

- [ ] **Step 1: Failing tests** — happy path relays the fake platform response; >5 MB → 422; `content_type: application/pdf` → 422; platform null → 502; missing permission → 403.
- [ ] **Step 2: Implement** — validation: `'filename' => ['required','string','max:255']`, `'content_type' => ['required','string','in:image/jpeg,image/png,image/webp,image/heic']`, `'size_bytes' => ['required','integer','min:1','max:5242880']`. Constructor-inject `ProductSubmissionService`. Route: `Route::post('upload-url', PhotoUploadUrlController::class)->name('platform.upload-url');` (middleware inherited from the group; add the permission check in the controller like `ProductSubmissionController` does with `$user?->can('enrichment.submit')`).
- [ ] **Step 3: Run by path → PASS.**
- [ ] **Step 4: Commit** `feat(platform): upload-url proxy endpoint for enrichment photos`

### Task 10: FE — photo upload util + capture panel component

**Files:**
- Create: `apps/web/src/features/inventory/api/enrichmentPhotos.ts`
- Create: `apps/web/src/features/inventory/components/EnrichmentCapturePanel.tsx`
- Test: `apps/web/src/features/inventory/components/__tests__/EnrichmentCapturePanel.test.tsx`
- Modify: locale files — new keys under the `inventory` namespace, `barcodeLookup.capture*` (en, fr, ar)

**Interfaces:**
- Produces:

```ts
// enrichmentPhotos.ts
export interface UploadedPhoto { photoId: string; filename: string }
export const MAX_PHOTO_BYTES = 5_242_880
export async function uploadEnrichmentPhoto(file: File): Promise<UploadedPhoto>
// POST /platform/upload-url via apiPost → { photo_id, upload_url } (snake_case from BE)
// then fetch(upload_url, { method: 'PUT', body: file, headers: {'Content-Type': file.type} })
// throws on non-ok PUT; caller catches

// EnrichmentCapturePanel.tsx
export function EnrichmentCapturePanel(props: {
  photos: UploadedPhoto[]
  onPhotosChange: (photos: UploadedPhoto[]) => void
  brand: string
  onBrandChange: (value: string) => void
  attributes: Array<{ key: string; value: string }>
  onAttributesChange: (rows: Array<{ key: string; value: string }>) => void
  disabled?: boolean
}): React.JSX.Element
```

- [ ] **Step 1: Failing component tests** — renders photo-first copy ("a photo helps us identify the exact product" key), file input `accept="image/*"`, max 2 photos; selecting a valid file calls the (mocked) upload util and emits `onPhotosChange`; upload failure shows the fallback message and does NOT block (panel stays usable, no throw); >5 MB file rejected client-side with error copy and no network call; brand input optional (no required marker) and emits `onBrandChange`.
- [ ] **Step 2: Implement** — photo-FIRST layout: photo dropzone/buttons on top with explanatory copy, then optional brand text input, then compact free-form attribute key/value rows (add/remove). Design tokens only (`tokens`, `textColors` from `@/lib/designTokens`). All copy via `t('inventory:barcodeLookup.capture…')`. Uploads run immediately on file selection (spinner per tile, remove button per uploaded tile). Comment the 2-photo cap: intentional UX ceiling (front-of-pack + barcode side) below the API's `max:5`. NOTE for the presigned PUT: it crosses origins (MinIO locally) — the orchestrator verifies CORS manually at review time; do not chase "frontend bugs" if a local PUT fails, per the handover gotcha.
- [ ] **Step 3: Run → PASS** (`pnpm test -- EnrichmentCapturePanel`).
- [ ] **Step 4: Commit** `feat(web): photo-first enrichment capture panel + presigned upload util`

### Task 11: FE — wire panel into ProductForm; fix submit payload (null brand, photo_ids)

**Files:**
- Modify: `apps/web/src/features/inventory/ProductForm.tsx` (state + not_found block `~695-705` + onSubmit branch `~483-499`)
- Modify: `apps/web/src/features/inventory/api/platformApi.ts` (`SubmitForEnrichmentPayload`: `brand: string | null`, add `photo_ids?: string[]`, `attributes?: Record<string, string>`)
- Test: extend `ProductForm.test.tsx`

- [ ] **Step 1: Failing tests** — on not_found + opt-in: capture panel renders; submitting with panel-entered brand + 1 uploaded photo sends `{ brand: 'BrandX', photo_ids: ['ph_1'], … }`; submitting with empty brand sends `brand: null` (NOT `''` — this kills the `?? ''` bug at `ProductForm.tsx:488`); found/idle states render NO panel.
- [ ] **Step 2: Implement** — local state `capturePhotos` / `captureBrand` / `captureAttributes` beside `enrichmentOptIn`; render `<EnrichmentCapturePanel>` inside the existing `lookupState === 'not_found'` block under the opt-in checkbox (panel visible only while opt-in checked). onSubmit not_found branch:

```ts
const payload: SubmitForEnrichmentPayload = {
  product_id: created.id,
  barcode: data.barcode || null,        // server normalizes (BarcodeNormalizer)
  name: data.name,
  brand: captureBrand.trim() !== '' ? captureBrand.trim() : null,
  photo_ids: capturePhotos.map(p => p.photoId),
}
if (data.description) payload.description = data.description
// optional category pass-through: when the user picked a local category, send its NAME
// (payload type already has category?: string) — resolve from the loaded categories list
if (selectedCategoryName) payload.category = selectedCategoryName
if (captureAttributes.length > 0)
  payload.attributes = Object.fromEntries(
    captureAttributes.filter(r => r.key.trim() !== '').map(r => [r.key.trim(), r.value]))
```

Surface submission errors from `submissionMutation` (onError): 409 `enrichment_tracking_conflict` → toast/inline `t('inventory:barcodeLookup.trackingConflict', { name: holderName })` with a link to `/inventory/products/{holder_product_id}` (verify the exact product-detail route the app uses — follow `nav.goToRecord`); 422 `invalid_barcode` → `t('inventory:barcodeLookup.invalidBarcode')` toast. Any other error keeps today's silent-ish behavior (product is already created; enrichment is best-effort).
- [ ] **Step 3: Run → PASS** (`pnpm test -- ProductForm`).
- [ ] **Step 4: Commit** `feat(web): wire capture panel into ProductForm; null-brand + photo_ids submit; conflict surfacing`

### Task 12: BE support for the fast path — `product_id` filter, accept() null-guards, ProductData exposure

**Files:**
- Modify: `apps/api/app/Modules/Product/Presentation/Controllers/EnrichmentReviewController.php` (index) + `EnrichmentReviewService::listForReview` (new nullable param)
- Modify: `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php` (`accept()` field mapping)
- Modify: `apps/api/app/Modules/Product/Application/DTOs/ProductData.php` (**review B2**: expose `enrichment_status` and `platform_product_id` in the DTO + `fromModel`; without them the FE gate `product.enrichment_status === 'pending'` reads an absent field and the whole fast path is dead code)
- Test: extend the enrichment review controller test + an accept() unit/feature test + a product show/serialization test

- [ ] **Step 1: Failing tests**
  - `GET /api/v1/enrichment-results?product_id={uuid}` returns only that product's results; invalid uuid → 422 (validated rule — remember the PG uuid-500 pitfall).
  - **accept() null-guard (review M2):** result whose `enriched_data` has `assigned_barcode: null` + `description: null`, accepted with `['name','brand','description','barcode']` → product's existing barcode/description are UNCHANGED (today they are overwritten with NULL — `EnrichmentReviewService.php:97-99` has no guards; the apply-now card makes blanket accept a mainline path).
  - Product API response (show/index) serializes `enrichment_status` and `platform_product_id`.
- [ ] **Step 2: Implement** — index: `product_id => ['sometimes','uuid']` → `->where('product_id', ...)`. accept(): in the `match`, only assign when the source value is non-null (`if ($enrichedData->description !== null)` etc.); name keeps its existing fallback semantics. ProductData: add the two fields (`?string`), map in `fromModel`, run `php artisan typescript:transform`, commit generated types.
- [ ] **Step 3: Run by path → PASS. Commit** `feat(enrichment): fast-path BE — product_id filter, null-safe accept, expose enrichment_status/platform_product_id`

### Task 13: FE — fast-path polling hook + "apply now" card

**Files:**
- Create: `apps/web/src/features/inventory/hooks/useEnrichmentFastPath.ts`
- Create: `apps/web/src/features/inventory/components/EnrichmentReadyCard.tsx`
- Modify: **`ProductDetailPage`** (review M1: this is where `nav.goToRecord` actually lands after create — find it under `apps/web/src/features/inventory/` or via the `/inventory/products/:id` route in `apps/web/src/routes/index.tsx:~888`) AND `apps/web/src/features/inventory/ProductForm.tsx` (edit mode, cheap reuse)
- Test: `apps/web/src/features/inventory/hooks/__tests__/useEnrichmentFastPath.test.tsx` (fake timers), extend the ProductDetailPage test (or create one following siblings) + `ProductForm.test.tsx` for the card

**Interfaces:**
- Produces:

```ts
export type FastPathState =
  | { phase: 'idle' | 'polling' | 'timeout' }
  | { phase: 'ready'; result: EnrichmentResult }
export function useEnrichmentFastPath(opts: { productId: string; enabled: boolean }): FastPathState
```

- [ ] **Step 1: Failing hook tests** (vi.useFakeTimers, mock `refreshEnrichment` + `getEnrichmentResults`):
  - delays fire at 3/5/8/13/21s cumulative; refresh returning `completed` → fetch pending result via `getEnrichmentResults({ product_id })` → phase `ready` with the result, no further polls;
  - refresh keeps returning `pending` through all 5 ticks → phase `timeout`;
  - terminal `failed`/`rejected` → stop polling (phase `timeout` with no card — or a distinct `stopped`; keep the union minimal, `timeout` is fine);
  - refresh REJECTS (403 / 422 `no_pending_submission` / 502) → treat as terminal, stop polling, no unhandled rejection;
  - the ready-path result fetch passes `status: 'pending_review'` alongside `product_id` (an older accepted/rejected row must not surface);
  - unmount mid-sequence → pending timers cleared, no state update after unmount (no act warnings);
  - `enabled: false` → never polls.
- [ ] **Step 2: Implement hook** — chained `setTimeout` (not setInterval), `useRef` for cancellation, ignore in-flight resolution after cancel. Reuse `refreshEnrichment` from `api/platformApi.ts` and `getEnrichmentResults` from `features/enrichment/api/enrichmentApi.ts` (add the `product_id` param to its signature — it passes `params` through already).
- [ ] **Step 3: Failing card/integration test** — edit mode with `product.enrichment_status === 'pending'`: hook enabled; when phase `ready`, `EnrichmentReadyCard` renders ("Enrichment ready — apply now"); clicking Apply calls `acceptEnrichmentResult(result.id, ['name','brand','description','barcode'])`, invalidates the product query, success toast; phase `timeout` renders the muted "We'll keep working — check your review queue" line.
- [ ] **Step 4: Implement card + wire into ProductDetailPage and ProductForm edit mode.** Gate the hook's `enabled` on `hasPermission('enrichment.view')` (the refresh route requires it — polling without it is 5×403); gate the Apply button on `enrichment.review` via `usePermissions` (the accept endpoint requires it). Tokens + `t()` keys throughout.
- [ ] **Step 5: Run → PASS. Commit** `feat(web): 45s fast-path enrichment polling + apply-now card`

### Task 14: Types, i18n completeness, preflight

- [ ] **Step 1:** `cd apps/api && php artisan typescript:transform` (worktree gotcha: may need `CACHE_STORE=array php artisan typescript:transform`) — commit any diff under `packages/shared/types/`.
- [ ] **Step 2:** Verify every new `t()` key exists in en + fr + ar for the touched namespaces (grep the new keys across locale files).
- [ ] **Step 3:** `./scripts/preflight.sh` from repo root — PHPStan, Pint, TS check, ESLint must pass. **PHPUnit inside preflight: if the script runs the FULL suite, skip that stage (laptop constraint) and instead run the task test files by path**; record exactly what ran.
- [ ] **Step 4:** Final commit `chore(enrichment): types regen + i18n completeness for H-A/H-B`

## Out of scope (do NOT build)

- H-C (whatever it covers) and platform-side track 1 — separate sessions/repos.
- Local persistence of FOUND `images[]` (render from URL only).
- Merch keys (`skin_suitability`/`equivalents`/`complements`/`routines`) application — "when wired" per spec; not wired now.
- Pay-with-points, review-queue UI changes beyond the `product_id` filter.
- End-to-end tests against a live platform (track 1 not deployed).

## Execution & verification protocol (for the orchestrator)

1. Worktree `../erp.enrichment-loop`, branch `feat/enrichment-ha-hb` off `origin/dev` (275bd2573). Backend env: copy `.env` from the main worktree's `apps/api`, `composer install` (NEVER symlink vendor), `pnpm install` at repo root.
2. Codex implements tasks 1→14 in order, TDD, one commit each.
3. Orchestrator adversarial code review (claims verified against code, file:line citations) before merge; fix findings; re-run affected tests by path.
4. Merge target: **local `post-demo`** (post-2026-07-02 branch policy — this is non-demo feature delivery and H-B's platform dependency is not deployed). Do NOT push to `origin/dev`.
