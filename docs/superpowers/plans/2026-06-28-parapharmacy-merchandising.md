# Parapharmacy Merchandising + First-Class Brand — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship first-class cross-vertical Brand + parapharmacy merchandising (skin type, suitability, equivalents, complements, routines) full-stack with seeded demo data, gated correctly, working in the IziPOS POS.

**Architecture:** Laravel 12 hexagonal, db-per-tenant (tenant migrations). Brand is a universal `Product`-module reference entity; the parapharmacy merchandising relations are anchored on `ParapharmacyProductMetadata` so they ride the existing vertical-gated metadata load. Customer skin fields need an explicit vertical guard on the POS sync path. POS device = offline SQLite (v58/v59 migrations + cursor resets). POS UI reuses the redesign branch atoms (rebase-gated).

**Tech Stack:** PHP 8.2 strict, PHPUnit, PHPStan L8, Pint; React 19 / Vite / Vitest (apps/pos); Spatie typescript-transformer.

**Spec:** `docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-design.md` (read it). Reviews: `docs/superpowers/audits/2026-06-28-parapharmacy-merchandising-design-*.md`.

## Global Constraints

- **Worktree:** `feat/parapharmacy-merchandising` (`../erp.parapharm`), off `origin/dev`. Never edit/commit on shared `dev`; never push to `dev`. Coordinate device migration version numbers (v58/v59) with the loyalty session before pushing.
- **TDD always:** failing test → minimal impl → green → refactor → commit. **NEVER run the full PHPUnit suite** (crashes the laptop) — run by path only: `CACHE_STORE=array ./vendor/bin/phpunit tests/path/SomeTest.php` (or `--filter test_name`). POS: `pnpm test <path>` (Vitest).
- **Tests are PHPUnit class-based** (PHP 8.4; `extends Tests\TestCase`, `public function test_*()`, `$this->assert*`, `RefreshDatabase`) — **the project does NOT use Pest.** The plan's `it()/expect()` snippets are illustrative pseudocode; translate each to PHPUnit by matching neighboring tests in the same dir.
- **Strict typing:** no `mixed` (PHP) / no `any` (TS). Constructor injection only — never `app()`. Enums for all type columns.
- **Tenant scope:** every tenant table carries `tenant_id`; top-level entities mirror `products` (`tenant_id` + `unique(tenant_id, …)`). Migrations go in `apps/api/database/migrations/tenant/`.
- **Backend test DB = SQLite `:memory:`** (`phpunit.xml`: `DB_CONNECTION=sqlite`, `TENANCY_DB_PER_TENANT=false`). Use the **portable Schema builder** for all migrations. **Guard any Postgres-specific raw DDL** — `DB::statement('ALTER TABLE … ADD CONSTRAINT …')`, gin indexes — with `if (DB::getDriverName() === 'pgsql')`, and enforce the same invariant at the **application layer** (model guard) so it is testable on SQLite. `$table->json(...)` is portable (TEXT on sqlite) — fine.
- **DTOs:** `#[TypeScript]`; run `php artisan typescript:transform` (needs `CACHE_STORE=array`) after DTO/enum changes → `packages/shared/types/generated.d.ts`.
- **i18n + tokens (POS):** all strings via `t()`; design tokens only (ESLint color guard = ERROR on touched apps/pos files).
- **Quality gate per task:** `./vendor/bin/phpstan` (L8, 0 new errors) + `./vendor/bin/pint` on touched files; POS: `pnpm lint`/`pnpm typecheck` on touched files.
- **Worktree env caution:** if `php artisan`/tests appear to run stale code, verify `apps/api/vendor` is real (not a symlink to the main repo); run `composer install` / `pnpm install` in the worktree if absent.

---

## File Structure

**Backend (`apps/api`):**
- `app/Shared/Domain/Enums/SkinType.php` (new) — canonical skin-type enum.
- `app/Modules/Product/Domain/Enums/EquivalenceType.php`, `BrandSource.php` (new).
- `app/Modules/Product/Domain/Brand.php`, `ProductSkinSuitability.php`, `Routine.php` (new models).
- `app/Modules/Product/Domain/ParapharmacyProductMetadata.php` (modify — add 4 relations).
- `app/Modules/Product/Domain/Product.php` (modify — `brand()` + `brand_id`/`brand_source` fillable/casts).
- `app/Modules/Product/Application/DTOs/BrandData.php` (new); `ParapharmacyProductMetadataData.php`, `ProductData.php` (modify).
- `app/Modules/Product/Application/Services/EnrichmentReviewService.php` (modify — brand upsert).
- `app/Modules/Partner/Domain/Partner.php` (modify — skin fields).
- `app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php` + `Resources/PosCustomerMirrorResource.php` (modify — vertical guard).
- `app/Enums/ModuleName.php` + `config/verticals.php` (modify — `Merchandising`).
- `database/migrations/tenant/*` (new migrations); `database/seeders/ParapharmacySeeder.php` (modify).
- `app/Modules/SmartPrompts/Domain/Enums/SkinType.php` (delete); `Application/DTOs/RecommendationRequestData.php` + `Presentation/Controllers/SmartPromptsController.php` (modify).

**POS (`apps/pos/src`):**
- `lib/db/migrations.ts` (modify — v58/v59); `lib/db/__tests__/migrations.v58.test.ts`, `migrations.v59.test.ts` (new).
- `lib/db/repositories/productRepository.ts`, `lib/db/repositories/customerRepository.ts` (modify).
- `types/product.ts` (modify — `POSProduct`); `lib/customer/customerTypes.ts` (modify — `CustomerMirrorRow`).
- `lib/sync/syncService.ts` (modify — wire `pullCustomers`, `SyncResult`).
- UI (rebase-gated): `components/molecules/ProductCard/ProductCard.tsx`, `components/organisms/ProductGrid/ProductGrid.tsx`, Filtres drawer, skin-advice bar, detail tabs, `/customers` page.

---

# PHASE A — Backend data model & gating (UNBLOCKED — build now)

### Task 1: Canonical `SkinType` enum + migrate SmartPrompts

**Files:**
- Create: `apps/api/app/Shared/Domain/Enums/SkinType.php`
- Modify: `apps/api/app/Modules/SmartPrompts/Application/DTOs/RecommendationRequestData.php`, `apps/api/app/Modules/SmartPrompts/Presentation/Controllers/SmartPromptsController.php`
- Delete: `apps/api/app/Modules/SmartPrompts/Domain/Enums/SkinType.php`
- Test: `apps/api/tests/Unit/Shared/Enums/SkinTypeTest.php`, `apps/api/tests/Feature/SmartPrompts/SkinTypeValidationTest.php` (or the existing SmartPrompts test path)

**Interfaces:**
- Produces: `App\Shared\Domain\Enums\SkinType` (string-backed: `Normal=normal, Oily=oily, Dry=dry, Combination=combination, Sensitive=sensitive`; method `label(): string`). Referenced by Partner, Product suitability, SmartPrompts.

- [ ] **Step 1: Write failing enum test**
```php
// tests/Unit/Shared/Enums/SkinTypeTest.php
use App\Shared\Domain\Enums\SkinType;
it('has the five canonical values', function () {
    expect(array_map(fn ($c) => $c->value, SkinType::cases()))
        ->toBe(['normal', 'oily', 'dry', 'combination', 'sensitive']);
    expect(SkinType::Dry->label())->toBe('Peau sèche');
});
```
- [ ] **Step 2: Run — FAIL** (`Class "App\Shared\Domain\Enums\SkinType" not found`)
Run: `cd apps/api && ./vendor/bin/pest tests/Unit/Shared/Enums/SkinTypeTest.php`
- [ ] **Step 3: Create the enum**
```php
<?php
declare(strict_types=1);
namespace App\Shared\Domain\Enums;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum SkinType: string
{
    case Normal = 'normal';
    case Oily = 'oily';
    case Dry = 'dry';
    case Combination = 'combination';
    case Sensitive = 'sensitive';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Peau normale',
            self::Oily => 'Peau grasse',
            self::Dry => 'Peau sèche',
            self::Combination => 'Peau mixte',
            self::Sensitive => 'Peau sensible',
        };
    }
}
```
- [ ] **Step 4: Run — PASS**
- [ ] **Step 5: Migrate SmartPrompts consumers** — in `RecommendationRequestData.php` and `SmartPromptsController.php` replace `use App\Modules\SmartPrompts\Domain\Enums\SkinType;` with `use App\Shared\Domain\Enums\SkinType;`. In `SmartPromptsController` replace the hardcoded validation `'skin_type' => ['nullable', 'string', 'in:normal,oily,dry,combination,sensitive']` with `'skin_type' => ['nullable', Illuminate\Validation\Rule::enum(SkinType::class)]`. Delete `app/Modules/SmartPrompts/Domain/Enums/SkinType.php`.
- [ ] **Step 6: Write the SmartPrompts validation test**
```php
// asserts every shared value is accepted and an invalid one is rejected
it('accepts all shared skin types and rejects invalid', function () {
    foreach (SkinType::cases() as $c) {
        // post to the recommendation endpoint with skin_type=$c->value → 200/valid
    }
    // skin_type='zzz' → 422
});
```
- [ ] **Step 7: Run SmartPrompts tests by path — PASS**; `./vendor/bin/phpstan` + `pint`.
- [ ] **Step 8: `CACHE_STORE=array php artisan typescript:transform`** (verify `App.Shared.Domain.Enums.SkinType` appears in generated types).
- [ ] **Step 9: Commit** `feat(merch): canonical Shared SkinType enum + migrate SmartPrompts`

---

### Task 2: `EquivalenceType` + `BrandSource` enums

**Files:** Create `apps/api/app/Modules/Product/Domain/Enums/EquivalenceType.php`, `BrandSource.php`; Test `tests/Unit/Product/Enums/EquivalenceTypeTest.php`

- [ ] **Step 1: Failing test**
```php
use App\Modules\Product\Domain\Enums\EquivalenceType;
use App\Modules\Product\Domain\Enums\BrandSource;
it('defines equivalence + brand-source values', function () {
    expect(EquivalenceType::Generic->value)->toBe('generic');
    expect(array_map(fn ($c) => $c->value, BrandSource::cases()))->toBe(['user', 'enriched']);
});
```
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement** (both `#[TypeScript]`, string-backed)
```php
#[TypeScript] enum EquivalenceType: string { case Generic='generic'; case Therapeutic='therapeutic'; case BrandAlt='brand_alt'; }
#[TypeScript] enum BrandSource: string { case User='user'; case Enriched='enriched'; }
```
- [ ] **Step 4: Run — PASS**; phpstan/pint; `typescript:transform`.
- [ ] **Step 5: Commit** `feat(merch): EquivalenceType + BrandSource enums`

---

### Task 3: `Brand` entity (table + model + `BrandData` DTO)

**Files:**
- Create: migration `tenant/<ts>_create_brands_table.php`, `app/Modules/Product/Domain/Brand.php`, `app/Modules/Product/Application/DTOs/BrandData.php`
- Test: `tests/Feature/Product/BrandTest.php`

**Interfaces:**
- Produces: `Brand` (tenant-scoped; fillable `tenant_id,name,slug,canonical_brand_id,logo_media_id,website_url,country_of_origin,description,is_active`); `BrandData::fromModel(Brand): self` with `id,name,slug,country_of_origin,website_url,is_active`. Static `Brand::slugFor(string $name): string` (deterministic normalized slug, used by seeder + enrichment).

- [ ] **Step 1: Failing test**
```php
use App\Modules\Product\Domain\Brand;
it('creates a tenant-scoped brand with unique slug per tenant', function () {
    $tenantId = (string) Str::uuid();
    $b = Brand::create(['tenant_id' => $tenantId, 'name' => 'La Roche-Posay', 'slug' => Brand::slugFor('La Roche-Posay'), 'is_active' => true]);
    expect($b->slug)->toBe('la-roche-posay');
    Brand::create(['tenant_id' => $tenantId, 'name' => 'X', 'slug' => 'la-roche-posay', 'is_active' => true]); // duplicate → unique violation
})->throws(Illuminate\Database\QueryException::class);
```
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Migration**
```php
Schema::create('brands', function (Blueprint $t) {
    $t->uuid('id')->primary();
    $t->uuid('tenant_id')->index();
    $t->string('name');
    $t->string('slug');
    $t->uuid('canonical_brand_id')->nullable();
    $t->uuid('logo_media_id')->nullable();
    $t->string('website_url')->nullable();
    $t->string('country_of_origin', 2)->nullable();
    $t->text('description')->nullable();
    $t->boolean('is_active')->default(true);
    $t->timestamps();
    $t->unique(['tenant_id', 'slug']);
    $t->index(['tenant_id', 'name']);
});
```
- [ ] **Step 4: Model** — `Brand` with `use HasUuids;` (codebase convention for uuid PKs — not a manual `booted()`), `$keyType='string'`, `$incrementing=false`, `$fillable` per interface, casts `is_active=bool`. Add `public static function slugFor(string $name): string { return Str::slug($name); }` and `public function products(): HasMany`.
- [ ] **Step 5: Run — PASS**
- [ ] **Step 6: `BrandData` DTO** (`#[TypeScript]`, `final readonly`, `fromModel`).
- [ ] **Step 7: phpstan/pint; `typescript:transform`; Commit** `feat(brand): brands table + Brand model + BrandData DTO`

---

### Task 4: `products.brand_id` + `brand_source` + `Product::brand()` + eager-load all read paths

**Files:**
- Create: migration `tenant/<ts>_add_brand_to_products.php`
- Modify: `Product.php` (fillable/casts + `brand()`), `ProductController.php` (eager-load `brand` + set `brand_source` on store/update), `ProductData.php` (`?BrandData $brand` + `fromModel`), `Presentation/Requests/CreateProductRequest.php` + `UpdateProductRequest.php` (validate `brand_id`)
- Test: `tests/Feature/Product/ProductBrandTest.php`

**Interfaces:**
- Consumes: `Brand` (Task 3), `BrandData` (Task 3), `BrandSource` (Task 2).
- Produces: `Product::brand(): BelongsTo`; `Product.brand_id` (nullable), `Product.brand_source` (nullable `BrandSource`); `ProductData->brand: ?BrandData`.

- [ ] **Step 1: Failing test** — (a) `GET /api/v1/products/{id}` with `brand_id` set → `data.brand.name` present; no brand → `data.brand` null; deleting the brand nulls `brand_id` (`nullOnDelete`); **(b) `POST /api/v1/products` with `brand_id` of a tenant brand persists it and sets `brand_source='user'`; `PATCH` changing `brand_id` keeps `brand_source='user'`** (C-2 — exercise the request path, not just a model-created product).
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Migration**
```php
Schema::table('products', function (Blueprint $t) {
    $t->uuid('brand_id')->nullable();
    $t->string('brand_source')->nullable(); // BrandSource
    $t->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();
    $t->index(['tenant_id', 'brand_id']);
});
```
- [ ] **Step 4: Model** — add `brand_id`, `brand_source` to `$fillable`; cast `brand_source => BrandSource::class`; `public function brand(): BelongsTo { return $this->belongsTo(Brand::class); }`.
- [ ] **Step 5: Controller eager-load + DTO** — add `'brand'` to the **base** `$with` in `index()` (line ~72) and to the base load in `show()`/`store()`/`update()` (universal, NOT vertical-gated). `ProductData::fromModel`: `brand: $product->relationLoaded('brand') && $product->brand ? BrandData::fromModel($product->brand) : null`.
- [ ] **Step 5b: Requests + provenance (C-2)** — in `CreateProductRequest` and `UpdateProductRequest` add `'brand_id' => ['nullable', 'uuid', Rule::exists('brands', 'id')->where('tenant_id', $this->user()->tenant_id)]` (scope the existence to the current tenant). In `ProductController::store`/`update`, when the validated payload includes `brand_id`, set `$data['brand_source'] = BrandSource::User->value` for a non-null assignment (and decide: clearing `brand_id` to null also nulls `brand_source`). Do NOT let `brand_source` be client-supplied.
- [ ] **Step 6: Run — PASS**; phpstan/pint; `typescript:transform`.
- [ ] **Step 7: Commit** `feat(brand): products.brand_id + brand_source + universal eager-load`

---

### Task 5: Enrichment-accept brand upsert (fix the silent `'brand' => null` drop)

**Files:** Modify `app/Modules/Product/Application/Services/EnrichmentReviewService.php`; Test `tests/Feature/Product/EnrichmentBrandAcceptTest.php`

**Interfaces:**
- Consumes: `Brand::slugFor` (Task 3), `BrandSource::Enriched` (Task 2).
- Produces: on accept with an accepted `brand` field, a `Brand` is `firstOrCreate`d on `(tenant_id, slug)` in a transaction and `product.brand_id` + `brand_source='enriched'` are set.

- [ ] **Step 1: Failing test** — given an `EnrichmentResult` with `enriched_data.brand='Avène'` and `brand` in `accepted_fields`, accepting it creates a `Brand(slug='avene')` and sets `product.brand_id` to it + `brand_source='enriched'`; accepting a second product with the same brand reuses the same `Brand` row (no duplicate).
- [ ] **Step 2: Run — FAIL** (today brand is dropped)
- [ ] **Step 3: Implement** — replace the `'brand' => null` skip. In the accept path, wrap in `DB::transaction`:
```php
if (in_array('brand', $acceptedFields, true) && filled($enriched->brand)) {
    $brand = Brand::firstOrCreate(
        ['tenant_id' => $product->tenant_id, 'slug' => Brand::slugFor($enriched->brand)],
        ['name' => $enriched->brand, 'is_active' => true],
    );
    $product->brand_id = $brand->id;
    $product->brand_source = BrandSource::Enriched;
}
```
Retry once on a unique-violation (concurrent accept) by re-running `firstOrCreate`. **Wrap ALL accept-side mutations in ONE `DB::transaction` — the brand upsert, the `$product->update(...)`, the enrichment-tracking clear, and the `accepted_fields` write — not just the brand block** (so a mid-accept failure rolls back atomically).
- [ ] **Step 4: Run — PASS**; phpstan/pint.
- [ ] **Step 5: Commit** `fix(brand): enrichment-accept upserts + links Brand (was silently dropped)`

---

### Task 6: `partners.skin_type` + `skin_advice_note`

**Files:** Create migration `tenant/<ts>_add_skin_type_to_partners.php`; Modify `Partner.php`; Test `tests/Feature/Partner/PartnerSkinTypeTest.php`

**Interfaces:** Produces `Partner.skin_type: ?SkinType`, `Partner.skin_advice_note: ?string`.

- [ ] **Step 1: Failing test** — set `skin_type='dry'` on a Partner, reload, assert it casts to `SkinType::Dry`.
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Migration** — `$t->string('skin_type')->nullable(); $t->text('skin_advice_note')->nullable();`
- [ ] **Step 4: Model** — add both to `$fillable`; cast `skin_type => SkinType::class`.
- [ ] **Step 5: Run — PASS**; phpstan/pint; Commit `feat(merch): partner skin_type + skin_advice_note`

---

### Task 7: `product_skin_suitability` + model + `ParapharmacyProductMetadata::skinSuitabilities()`

**Files:** Create migration + `app/Modules/Product/Domain/ProductSkinSuitability.php`; Modify `ParapharmacyProductMetadata.php`; Test `tests/Feature/Product/ProductSkinSuitabilityTest.php`

**Interfaces:** Produces `ParapharmacyProductMetadata::skinSuitabilities(): HasMany`; `ProductSkinSuitability` (`product_id`, `skin_type: SkinType`).

- [ ] **Step 1: Failing test** — attach 2 suitabilities to a product's metadata, assert `$metadata->skinSuitabilities` returns 2 rows cast to `SkinType`.
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Migration**
```php
Schema::create('product_skin_suitability', function (Blueprint $t) {
    $t->uuid('id')->primary();
    $t->uuid('tenant_id')->index();
    $t->uuid('product_id');
    $t->string('skin_type'); // SkinType
    $t->timestamps();
    $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
    $t->unique(['product_id', 'skin_type']);
});
```
- [ ] **Step 4: Model + relation** — `ProductSkinSuitability` (cast `skin_type => SkinType::class`). On `ParapharmacyProductMetadata` (anchored on `product_id`, per the relation-home rule):
```php
public function skinSuitabilities(): HasMany
{
    return $this->hasMany(ProductSkinSuitability::class, 'product_id', 'product_id');
}
```
- [ ] **Step 5: Run — PASS**; phpstan/pint; Commit `feat(merch): product_skin_suitability + metadata relation`

---

### Task 8: `routines` + `product_routine` + `Routine` + `ParapharmacyProductMetadata::routines()`

**Files:** Create 2 migrations + `Routine.php`; Modify `ParapharmacyProductMetadata.php`; Test `tests/Feature/Product/RoutineTest.php`

**Interfaces:** Produces `ParapharmacyProductMetadata::routines(): BelongsToMany` withPivot(`step_order`,`step_label`) ordered.

- [ ] **Step 1: Failing test** — create a routine with 3 ordered products, assert `$metadata->routines->first()->pivot->step_order` and ordering.
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Migrations**
```php
Schema::create('routines', function (Blueprint $t) {
    $t->uuid('id')->primary(); $t->uuid('tenant_id')->index();
    $t->string('name'); $t->text('description')->nullable(); $t->string('period')->nullable();
    $t->boolean('is_active')->default(true); $t->timestamps();
});
Schema::create('product_routine', function (Blueprint $t) {
    $t->uuid('id')->primary();
    $t->uuid('routine_id'); $t->uuid('product_id');
    $t->integer('step_order'); $t->string('step_label'); $t->timestamps();
    $t->foreign('routine_id')->references('id')->on('routines')->cascadeOnDelete();
    $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
    $t->unique(['routine_id', 'product_id']);
});
```
- [ ] **Step 4: Relation** (anchored on `product_id`, per `ingredients()`):
```php
public function routines(): BelongsToMany
{
    return $this->belongsToMany(Routine::class, 'product_routine', 'product_id', 'routine_id', 'product_id')
        ->withPivot(['step_order', 'step_label'])->orderByPivot('step_order');
}
```
- [ ] **Step 5: Run — PASS**; phpstan/pint; Commit `feat(merch): routines + product_routine + metadata relation`

---

### Task 9: `product_equivalents` + `product_complements` (+ CHECK + relations)

**Files:** Create 2 migrations + 2 pivot models `app/Modules/Product/Domain/ProductEquivalent.php`, `ProductComplement.php`; Modify `ParapharmacyProductMetadata.php`; Test `tests/Feature/Product/ProductRelationsTest.php`

**Interfaces:** Produces `ParapharmacyProductMetadata::equivalentProducts(): BelongsToMany` (pivot `equivalence_type`), `complementProducts(): BelongsToMany` (pivot `reason`).

- [ ] **Step 1: Failing test** — link A↔B as `generic` equivalents (both rows); assert `$aMeta->equivalentProducts` contains B with `pivot->equivalence_type='generic'`; assert creating a self-equivalence (`product_id == equivalent_product_id`) **throws via the model guard** (portable — runs on the SQLite test DB).
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Migrations + portable self-reference guard.** Schema is portable; the CHECK is **pgsql-only, driver-guarded**, and the model guard enforces the same invariant on SQLite (global constraint):
```php
Schema::create('product_equivalents', function (Blueprint $t) {
    $t->uuid('id')->primary(); $t->uuid('tenant_id')->index();
    $t->uuid('product_id'); $t->uuid('equivalent_product_id');
    $t->string('equivalence_type'); $t->text('notes')->nullable(); $t->timestamps();
    $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
    $t->foreign('equivalent_product_id')->references('id')->on('products')->cascadeOnDelete();
    $t->unique(['product_id', 'equivalent_product_id']);
});
if (DB::getDriverName() === 'pgsql') {
    DB::statement('ALTER TABLE product_equivalents ADD CONSTRAINT chk_equiv_not_self CHECK (product_id <> equivalent_product_id)');
}
// product_complements: same shape with complement_product_id + reason (nullable); pgsql-guarded chk_compl_not_self
```
Add pivot models `ProductEquivalent` (table `product_equivalents`, `use HasUuids`) / `ProductComplement` with a `creating` guard:
```php
protected static function booted(): void
{
    static::creating(function (self $m) {
        if ($m->product_id === $m->equivalent_product_id) { // complement: complement_product_id
            throw new \InvalidArgumentException('A product cannot be its own equivalent/complement.');
        }
    });
}
```
(The seeder writes pivots via these models — or via `DB::table()` PLUS a guard in the seeder — so the invariant holds even though CHECK is absent on SQLite.)
- [ ] **Step 4: Relations** (anchored on `product_id`):
```php
public function equivalentProducts(): BelongsToMany
{
    return $this->belongsToMany(Product::class, 'product_equivalents', 'product_id', 'equivalent_product_id', 'product_id')
        ->withPivot(['equivalence_type', 'notes']);
}
public function complementProducts(): BelongsToMany
{
    return $this->belongsToMany(Product::class, 'product_complements', 'product_id', 'complement_product_id', 'product_id')
        ->withPivot(['reason']);
}
```
- [ ] **Step 5: Run — PASS**; phpstan/pint; Commit `feat(merch): product_equivalents + complements (+ self-ref CHECK)`

---

### Task 10: Extend `ParapharmacyProductMetadataData` + `ProductController` gated eager-load + `typescript:transform`

**Files:** Modify `ParapharmacyProductMetadataData.php`, `ProductController.php`; Test `tests/Feature/Product/ProductMerchPayloadTest.php`

**Interfaces:** Produces DTO fields `suitable_skin_types: SkinType[]`, `equivalent_product_ids: string[]`, `complement_product_ids: string[]`, `routine_refs: array{routine_id:string,step_order:int,step_label:string}[]`.

- [ ] **Step 1: Failing test** — for a parapharmacy tenant, `GET /products/{id}` returns `data.parapharmacy_metadata.suitable_skin_types` + `equivalent_product_ids` etc.; for a **non-parapharmacy** tenant the whole `parapharmacy_metadata` (and thus these arrays) is absent.
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: DTO** — add the four properties; in `fromModel(ParapharmacyProductMetadata $m)` map: `suitable_skin_types: $m->skinSuitabilities->pluck('skin_type')->all()`, `equivalent_product_ids: $m->equivalentProducts->pluck('id')->all()`, `complement_product_ids: $m->complementProducts->pluck('id')->all()`, `routine_refs: $m->routines->map(fn ($r) => ['routine_id' => $r->id, 'step_order' => $r->pivot->step_order, 'step_label' => $r->pivot->step_label])->all()`.
- [ ] **Step 4: Controller** — extend the **parapharmacy-only** `$with` lists (index/show/store/update — the existing `if vertical === Parapharmacy` blocks) with `parapharmacyMetadata.skinSuitabilities`, `parapharmacyMetadata.routines`, `parapharmacyMetadata.equivalentProducts`, `parapharmacyMetadata.complementProducts`. **Do NOT add these to the base `$with`.**
- [ ] **Step 5: Run — PASS**; phpstan/pint; `typescript:transform`.
- [ ] **Step 6: Commit** `feat(merch): merchandising arrays on parapharmacy metadata payload (vertical-gated)`

---

### Task 11: Customer skin fields on sync + **vertical guard** (D1-C1)

**Files:** Modify `PosCustomerSyncController.php`, `PosCustomerMirrorResource.php`; Test `tests/Feature/POS/CustomerSyncSkinGatingTest.php`

**Interfaces:** Consumes `CompanyContext::requireCompany()` (eager-loads `tenant`). Produces `/pos/customers/sync` rows with `skin_type`/`skin_advice_note` **only for parapharmacy tenants**.

- [ ] **Step 1: Failing test (the leak guard)**
```php
it('includes skin fields for parapharmacy and excludes them otherwise', function () {
    // parapharmacy tenant + customer with skin_type set → response row HAS 'skin_type'
    // non-parapharmacy tenant + customer with skin columns populated → response row has NO 'skin_type' key
});
```
- [ ] **Step 2: Run — FAIL** (resource is flat/unconditional today)
- [ ] **Step 3: Controller** — resolve the flag from the already-injected `$this->companyContext` (it eager-loads `tenant`) and pass it through the **resource constructor** (the controller maps rows by calling `->toArray($request)` manually, so `->additional()`/`mergeWhen` resolution is bypassed — the constructor is the PRIMARY path, I-1):
```php
$isParapharmacy = $this->companyContext->requireCompany()->tenant->vertical === Vertical::Parapharmacy;
// in the row map closure (NOT a static closure — it captures $isParapharmacy):
(new PosCustomerMirrorResource($customer, $isParapharmacy))->toArray($request);
```
- [ ] **Step 4: Resource** — add `public function __construct($resource, private bool $isParapharmacy = false) { parent::__construct($resource); }`. In `toArray`, build `$payload = [ ...existing keys... ];` then plain PHP:
```php
if ($this->isParapharmacy) {
    $payload['skin_type'] = $this->skin_type instanceof SkinType ? $this->skin_type->value : $this->skin_type;
    $payload['skin_advice_note'] = $this->skin_advice_note;
}
return $payload;
```
Non-parapharmacy callers get the keys **entirely absent** (not null).
- [ ] **Step 5: Run — PASS**; phpstan/pint; Commit `feat(merch): customer skin fields on sync + parapharmacy vertical guard`

---

### Task 12: `Merchandising` module key (enum + verticals config + projection test)

**Files:** Modify `app/Enums/ModuleName.php`, `config/verticals.php`; Test `tests/Feature/Company/CompanyConfigMerchandisingTest.php` (+ existing `ModuleNameTest`)

**Interfaces:** Produces `ModuleName::Merchandising`; `parapharmacy.default_modules` includes `'Merchandising'`; `/company/config` `all_enabled_modules` includes it for parapharmacy.

- [ ] **Step 1: Failing test** — `GET /company/config` for a parapharmacy tenant includes `Merchandising` in `all_enabled_modules`; `ModuleNameTest` (enum==config union) stays green.
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement** — add `case Merchandising = 'Merchandising';` to `ModuleName` **and** `'Merchandising'` to `parapharmacy.default_modules` in `config/verticals.php` (**same commit** — `ModuleNameTest` enforces equality).
- [ ] **Step 4: Run — PASS** (both the new test and `ModuleNameTest`); phpstan/pint; `typescript:transform`.
- [ ] **Step 5: Commit** `feat(merch): Merchandising module key (bundled in parapharmacy)`

---

# PHASE B — Seeder (UNBLOCKED)

> All in `database/seeders/ParapharmacySeeder.php`. Add methods, call them from `run()` after `seedProducts`/`seedPartners`. Use `DB::table()->insert()` batches (pattern ~line 717). Every row carries `tenant_id`. Verify counts after each via `PARAPHARMACY_SEEDER_SCALE=1 php artisan db:seed --class=ParapharmacySeeder`.

### Task 13: `seedBrands()` + assign `products.brand_id`
- [ ] **Step 1: Failing test** `tests/Feature/Seeders/ParapharmacyBrandSeedTest.php` — after seeding, `brands` count ≥ 15 and ≥ 80% of cosmetic products have a non-null `brand_id`.
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement** `seedBrands(Company $company): Collection` — insert ~20 real brands (Avène, La Roche-Posay, Bioderma, Vichy, CeraVe, Nuxe, Mustela, Caudalie, Uriage, Ducray, A-Derma, Klorane, SVR, Embryolisse, …) with `Brand::slugFor($name)`; map products → brand_id by category heuristic; set `brand_source='user'`. Call from `run()`.
- [ ] **Step 4: Run seeder + test — PASS**; phpstan/pint; Commit `feat(seed): parapharmacy brands + product brand assignment`

### Task 14: `seedProductSkinSuitability()`
- [ ] **Step 1: Failing test** — ≥1 suitability row per cosmetic/visage product after seeding.
- [ ] **Step 2: Run — FAIL** → **Step 3:** map each cosmetic product to 1–3 `SkinType` values by heuristic; batch-insert into `product_skin_suitability`. → **Step 4:** seed+test PASS; Commit `feat(seed): product skin suitability`

### Task 15: `seedProductEquivalents()` (both directions) + `seedProductComplements()`
- [ ] **Step 1: Failing test** — for each seeded equivalent pair, BOTH `(A,B)` and `(B,A)` rows exist with identical `equivalence_type` (symmetry); complements exist as cross-category bundles.
- [ ] **Step 2: Run — FAIL** → **Step 3:** within category+form link a few `generic`/`brand_alt` equivalents, inserting both directions with the same type; complements as cleanser→moisturiser→SPF chains. → **Step 4:** seed+test PASS; Commit `feat(seed): product equivalents (both directions) + complements`

### Task 16: `seedRoutines()` + membership
- [ ] **Step 1: Failing test** — ≥3 routines, each with 3–4 ordered steps (`step_order` contiguous from 1).
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement** `seedRoutines(Company $company, Collection $products): void` — insert ≥3 `routines` rows (e.g. "Routine visage — peau sèche", "…peau grasse", "…peau sensible"); for each, batch-insert 3–4 `product_routine` rows picking products by category (cleanser→serum→moisturiser→SPF) with `step_order` 1..n and French `step_label` ("Nettoyage"/"Hydratation"/"Protection"). Call from `run()`.
- [ ] **Step 4: Run seeder + test — PASS**; phpstan/pint; Commit `feat(seed): routines + membership`

### Task 17: `seedCustomerSkinTypes()`
- [ ] **Step 1: Failing test** — ≥80% of individual (non-company) demo partners have a non-null `skin_type`.
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement** `seedCustomerSkinTypes(Tenant $tenant): void` — query the seeded individual partners; assign each a `SkinType` (round-robin or weighted over the 5 cases) and ~30% a short French `skin_advice_note`; persist via `DB::table('partners')->where(...)->update(...)` batches. Call from `run()` after `seedPartners`.
- [ ] **Step 4: Run seeder + test — PASS**; phpstan/pint; Commit `feat(seed): customer skin types`

---

# PHASE C — POS offline sync (UNBLOCKED; coordinate v58/v59 with loyalty)

### Task 18: Device migration v58 (products) + cursor reset + test
**Files:** Modify `apps/pos/src/lib/db/migrations.ts`; Test `apps/pos/src/lib/db/__tests__/migrations.v58.test.ts`

- [ ] **Step 1: Failing test** (mirror `migrations.v57.test.ts`) — (a) seed `sync_metadata['products_last_sync']`, apply migrations through v58, assert columns `brand_id`/`brand_name`/`parapharmacy_metadata` exist AND `products_last_sync` is gone; **(b) idempotency: pre-create one target column (e.g. `brand_id`) before running v58, then run it and assert it still completes with all 3 columns + cursor deleted** (I-3).
- [ ] **Step 2: Run — FAIL** (`cd apps/pos && pnpm test src/lib/db/__tests__/migrations.v58.test.ts`)
- [ ] **Step 3: Add migration via a `run` handler with idempotent ALTER guards** (NOT a bare `sql` string — the migration system supports `run` + already has `isDuplicateColumnError()`, `migrations.ts:8`). Confirm v58 is free vs the loyalty session first:
```ts
{ version: 58, name: 'add_brand_and_parapharmacy_metadata_to_products', run: async (db) => {
  for (const col of ['brand_id', 'brand_name', 'parapharmacy_metadata']) {
    try { await db.execute(`ALTER TABLE products ADD COLUMN ${col} TEXT`); }
    catch (e) { if (!isDuplicateColumnError(e)) throw e; }
  }
  await db.execute(`DELETE FROM sync_metadata WHERE key = 'products_last_sync'`);
} },
```
(Match the exact `run`/`db.execute` signature the existing migrations use.)
- [ ] **Step 4: Run — PASS**; `pnpm lint`/`typecheck`; Commit `feat(pos): v58 product brand + parapharmacy_metadata + cursor reset (idempotent)`

### Task 19: Device migration v59 (customers) + cursor reset + test
- [ ] **Step 1: Failing test** `migrations.v59.test.ts` — seed the customer cursor `sync_metadata['customers.updated_since']` (the key `pullCustomers` persists — confirmed in `customerSyncService.ts`), apply v59, assert columns `skin_type`/`skin_advice_note` exist AND the cursor key is gone; plus the same pre-created-column idempotency assertion as v58.
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3:** add v59 as a `run` handler with the same `isDuplicateColumnError` guard loop over `['skin_type', 'skin_advice_note']`, then `DELETE FROM sync_metadata WHERE key = 'customers.updated_since'`. Confirm v59 is free vs the loyalty session.
- [ ] **Step 4: PASS**; lint/typecheck; Commit `feat(pos): v59 customer skin fields + cursor reset (idempotent)`

### Task 20: `productRepository` — columns + flatten + upsert rewrite
**Files:** Modify `apps/pos/src/lib/db/repositories/productRepository.ts`, `apps/pos/src/types/product.ts`; Test `productRepository.test.ts`

- [ ] **Step 1: Failing test** — `upsertProducts` then `getProducts` round-trips `brand_id`, `brand_name`, and a `parapharmacy_metadata` object; AND a product whose API payload carries nested `brand:{id,name}` (not flat) persists `brand_id`/`brand_name` after upsert. (The second assertion is the C-1 guard — flattening must happen on the WRITE path.)
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement** — extend `ProductRow` (+3 cols); `POSProduct` (`brand_id?`,`brand_name?`,`parapharmacy_metadata?: ParapharmacyMeta`); define `ParapharmacyMeta` in `types/product.ts`. **C-1 — flatten on the WRITE path, NOT in `rowToProduct`** (`rowToProduct` only runs on SQLite reads — `productRepository.ts:25`; the live `/products` pull sends API rows straight to `upsertProducts` — `syncService.ts:590`). At the top of `upsertProducts` (or in `pullProductsCore` before calling it), accept `POSProduct & { brand?: { id: string; name: string } | null }` and derive `const brand_id = p.brand_id ?? p.brand?.id ?? null; const brand_name = p.brand_name ?? p.brand?.name ?? null;`. `rowToProduct` only `JSON.parse`s `parapharmacy_metadata`. **Rewrite `upsertProducts`** end-to-end: INSERT column list (+3), value template `($n…$n+3, datetime('now'), datetime('now'))`, `ON CONFLICT … SET` (+3), `PARAMS_PER_ROW 15→18`.
- [ ] **Step 4: Run — PASS**; lint/typecheck; Commit `feat(pos): productRepository brand + parapharmacy_metadata round-trip`

### Task 21: `customerRepository` + `CustomerMirrorRow` skin fields
- [ ] **Step 1: Failing test** — `upsertCustomer` round-trips `skin_type`/`skin_advice_note`.
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement** — extend `CustomerMirrorRow` (`customerTypes.ts`) with `skin_type: string | null`, `skin_advice_note: string | null`; in `upsertCustomer` add both to the INSERT column list, the `$n` value placeholders (bump the customer param count by 2), the params array, and the `ON CONFLICT … DO UPDATE SET` list (mirror the exact pattern Task 20 uses for products — count the existing placeholders first).
- [ ] **Step 4: Run — PASS**; lint/typecheck; Commit `feat(pos): customer skin fields round-trip`

### Task 22: Wire `pullCustomers` into `runFullSync` + `SyncResult` + failure policy
**Files:** Modify `apps/pos/src/lib/sync/syncService.ts`; Test `syncService.customers.test.ts`

**Interfaces:** Consumes `pullCustomers(db, tenantId, companyId)` (existing). Produces `SyncResult.customersPulled: number` + `customersFailed?: boolean`.

- [ ] **Step 1: Failing test** — `runFullSync` calls `pullCustomers` with `tenantId`/`companyId` from `authStore`; result has `customersPulled`; when `pullCustomers` throws, the tick continues (sells), sets a degraded/`customersFailed` signal, and does NOT advance the customer cursor.
- [ ] **Step 2: Run — FAIL** (orphan today)
- [ ] **Step 3: Implement** — add `customersPulled: number` (+ `customersFailed?: boolean`) to `SyncResult` and the returned object. In `runFullSync`, read IDs with a **strict guard** (I-2 — `authStore` exposes `user.tenantId` and store-level `companyId: string | null`):
```ts
const tenantId = auth.user?.tenantId; const companyId = auth.companyId;
if (!tenantId || !companyId) {
  customersFailed = true; errors.push('Customer pull skipped: missing tenant/company context');
} else {
  try { customersPulled = await pullCustomers(db, tenantId, companyId); }
  catch (e) { customersFailed = true; errors.push(`Customer pull failed: ${sanitize(e)}`); }
}
```
Pushing into `errors` makes `computeDegraded()` (`syncService.ts:201`) return true (it counts `errors.length`); if you prefer an explicit signal, extend `computeDegraded` to take `customersFailed`. Cursor advancement stays inside `pullCustomers`' success path (already correct — never advances on throw).
- [ ] **Step 4: Run — PASS**; lint/typecheck; Commit `feat(pos): wire pullCustomers into runFullSync + SyncResult contract`

---

# PHASE D — POS UI (REBASE-GATED on `feat/pos-caisse-redesign`)

### Task 23: Rebase + prerequisites verification gate
- [ ] **Step 1:** `git fetch origin dev`; confirm `feat/pos-caisse-redesign` has merged to `origin/dev` (or rebase this branch onto it). If unmerged, STOP and escalate (Phase D is blocked).
- [ ] **Step 2:** Verify on the rebased tree: atoms `ProductThumb`/`StockBadge`/`Pill`/`Tabs` exported from `@/components/ui`; tokens `stock-*`/`accent`/`rounded-tile`/`rounded-card`; `settingsStore.density`; `/customers` route + `NavRail`. For any absent, coordinate with the redesign owner — do NOT create them here.
- [ ] **Step 3:** Add the `ezTap` keyframe to `index.css` (named `ezTap`, `var(--accent-ring)`) — confirm not already present.
- [ ] **Step 4: Commit** (if ezTap added) `chore(pos): ezTap keyframe for add-to-cart pulse`

### Task 24: `ProductCard` restyle (in place — `components/molecules/ProductCard/ProductCard.tsx`)
- [ ] **Step 1: Test** — restyle preserves data-testids (`in-cart-badge`, `view-details-button`, `price-row`, `stock-row`, `incoming-badge`), the three-path `locationStock` logic, modifiers, activation-block, keyboard, memo (existing tests stay green); add a test asserting in-cart accent treatment renders.
- [ ] **Step 2: Implement** — `ProductThumb` top; info-eye top-left (works out-of-stock); brand caps above name (universal); price `font-mono`; `StockBadge` (map `isOutOfStock`/`isLowStock`→`out`/`low`/`ok`); in-cart = accent outline + accent-tint bg + 3px top accent bar + ✓ qty badge; `ezTap` pulse on add; compact = no thumb; out-of-stock dimmed + fiche openable.
- [ ] **Step 3: Run existing + new tests — PASS**; lint (color guard)/typecheck; Commit `feat(pos): ProductCard restyle (brand caps, StockBadge, in-cart accent)`

### Task 25: `ProductGrid` restyle + `displayMode`/`density` reconcile (`components/organisms/ProductGrid/ProductGrid.tsx`)
- [ ] **Step 1: Test** — grid reads `displayMode` from `settingsStore` (not `localStorage`); columns respond to `density` via `getColumns(displayMode, density, width)`.
- [ ] **Step 2: Implement** — replace `getStoredDisplayMode()` local state with `useSettingsStore` `displayMode` + `density`; extend `getColumns` + `CARD_MIN_H_*` (visual+comfortable=5/visual+dense=6/compact+comfortable=4/compact+dense=5); toolbar: search · Filtres (badge) · Top ventes · view toggle (`SegmentedControl`); category `Pill` row + active filter-chip row + count.
- [ ] **Step 3: PASS**; lint/typecheck; Commit `feat(pos): ProductGrid restyle + settingsStore displayMode/density reconcile`

> **Tasks 26–29 are post-rebase.** Confirm the exact atom/token/route targets at Task 23 before starting; the file paths below are the current (pre-rebase) anchors and may shift after the redesign rebase — re-verify each.

### Task 26: Filtres drawer (module-gated)
**Files:** new drawer component (e.g. `components/organisms/FiltresDrawer/`); **filter state owner = `HomePage`** (lifts state above `ProductGrid` so the grid + skin-advice bar both read it); facet source = `useProductStore` (in-memory products).
- [ ] **Step 1: Test** — drawer renders brand/category/routine/skin-type multi-selects derived from the loaded product set; selecting filters narrows the grid; chips (`Pill`) removable; live result count; the Filtres toolbar button + drawer are hidden when `!hasModule(config, 'Merchandising')`.
- [ ] **Step 2: Implement** — `HomePage` owns `filters` state + passes to `ProductGrid`; drawer reads facet options from `productStore` (`brand_name`, `category`, routines, `suitable_skin_types`); gate the toolbar entry on `hasModule`.
- [ ] **Step 3: PASS**; lint (color guard)/typecheck; Commit `feat(pos): Filtres drawer (module-gated)`

### Task 27: Skin-advice bar (module-gated)
**Files:** `components/organisms/ProductGrid/ProductGrid.tsx` header; reads the **selected-customer** from the active transaction/customer store; writes a skin-type filter into the `HomePage` filter state (Task 26).
- [ ] **Step 1: Test** — skin-type `Pill`s filter the grid by `suitable_skin_types`; the bar defaults its active pill from the selected customer's `skin_type`; hidden when `!hasModule('Merchandising')`.
- [ ] **Step 2: Implement** — render in the grid header; default from selected-customer `skin_type`; toggle updates the shared filter state.
- [ ] **Step 3: PASS**; lint/typecheck; Commit `feat(pos): skin-advice bar (module-gated)`

### Task 28: Product-detail Équivalents/Compléments/Routine tabs (module-gated)
**Files:** the detail drawer reached via `components/organisms/ProductDetailDrawer/index.ts` → currently re-exports the legacy `components/pos/ProductDetailDrawer.tsx` (confirm post-rebase). **Local lookup helper:** add `productStore.getByIds(ids: string[]): POSProduct[]` (resolve `equivalent_product_ids`/`complement_product_ids`/`routine_refs[].routine_id`→products in-memory — no network).
- [ ] **Step 1: Test** — the three new tabs (`Tabs` w/ counts) resolve the ID arrays via `getByIds` → `ProductThumb` rows; tapping a row adds it to the cart; tabs hidden when `!hasModule('Merchandising')`.
- [ ] **Step 2: Implement** — read the arrays from the product's `parapharmacy_metadata`; resolve via `getByIds`; render `ProductThumb` rows with the existing add-to-cart action.
- [ ] **Step 3: PASS**; lint/typecheck; Commit `feat(pos): detail merchandising tabs (module-gated)`

### Task 29: `/customers` page build-out + skin capture (in place)
**Files:** the `/customers` route + page placeholder added by the redesign session (verify in `AppShell` route tree at Task 23 — **do NOT create a parallel page**). Customer create/update path = the existing customer API/offline write used by the current add-customer flow; skin fields sync via Task 11 (server) + Task 19/21 (device).
- [ ] **Step 1: Test** — `/customers` lists synced customers; add/edit form captures `skin_type` (`SkinType` options) + `skin_advice_note`; the detail view shows them and feeds the skin-advice-bar default (Task 27).
- [ ] **Step 2: Implement** — build out the placeholder page; add the skin fields to the customer form + detail; persist via the existing customer write path.
- [ ] **Step 3: PASS**; lint/typecheck; Commit `feat(pos): build out /customers with skin-type capture`

---

# PHASE E — Docs

### Task 30: REALIGNMENT-LOG enrichment field-mapping
- [ ] **Step 1:** Append to `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`: the published change (brand now a first-class field; `products.brand_id`/`brand_source`) + the enrichment field-mapping contract (which `accepted_fields` keys map to brand / skin-suitability / equivalents / routines for the later platform push).
- [ ] **Step 2: Commit** `docs(realign): brand first-class + enrichment field-mapping contract`

---

## Notes for the executor
- **Phase A → B → C are independent of the redesign branch and should land first** (they make the feature demoable via seed + API + device sync). **Phase D is blocked** on the redesign branch reaching `origin/dev`.
- Coordinate v58/v59 numbers with the loyalty session before pushing Phase C.
- After Phase A/B, run `PARAPHARMACY_SEEDER_SCALE=1 php artisan db:seed --class=ParapharmacySeeder` and spot-check the API payloads for a parapharmacy vs a non-parapharmacy tenant (the latter must NOT carry merch fields).
