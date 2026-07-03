<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Application\Services\CatalogEnrichmentService;
use App\Modules\Product\Domain\Brand;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\BrandSource;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\Ingredient;
use App\Modules\Product\Domain\IngredientTranslation;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\DTOs\CatalogProductDTO;
use App\Shared\Enums\EnrichmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogEnrichmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private CatalogEnrichmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'fr']);
        $this->makeCompanyContext(Vertical::Parapharmacy, 'catalog-enrichment-parapharmacy');
        $this->service = $this->app->make(CatalogEnrichmentService::class);
    }

    public function test_apply_creates_accepted_enrichment_result_and_completes_status(): void
    {
        $product = $this->makeProduct([
            'name' => 'Original Cream',
            'barcode' => '3017620422003',
            'platform_product_id' => 'platform-product-001',
            'enrichment_status' => EnrichmentStatus::Pending,
            'brand_id' => null,
            'brand_source' => null,
            'description' => null, // factory fakes one 70% of the time; fill-if-empty needs none
        ]);

        $catalog = $this->makeCatalogProduct();

        $this->service->applyCatalogHit($product, $catalog);

        $result = EnrichmentResult::query()->where('product_id', $product->id)->sole();
        $this->assertNull($result->tracking_id);
        $this->assertSame(EnrichmentReviewStatus::Accepted, $result->status);
        $this->assertNull($result->reviewed_by);
        $this->assertNotNull($result->reviewed_at);
        $this->assertSame('catalog', $result->enrichment_quality);
        $this->assertSame([
            'description' => true,
            'brand' => true,
            'ingredients' => true,
        ], $result->accepted_fields);
        $this->assertSame('Catalog Cream', $result->enriched_data->name);
        $this->assertSame('La Roche-Posay', $result->enriched_data->brand);
        $this->assertSame([['name' => 'Aqua', 'position' => 1], ['name' => 'Glycerin', 'position' => 2]], $result->enriched_data->ingredients);
        $this->assertSame(96, $result->enriched_data->confidence_score);
        $this->assertSame('catalog', $result->enriched_data->enrichment_tier);

        $product->refresh();
        $this->assertSame(EnrichmentStatus::Completed, $product->enrichment_status);
        // The user's own name/barcode are NEVER overwritten by the catalog apply —
        // the form already carried the suggestion; edits made before saving win.
        $this->assertSame('Original Cream', $product->name);
        // Description fills only when the product has none.
        $this->assertSame('Hydrating care', $product->description);
        $this->assertSame('3017620422003', $product->barcode);
        $this->assertSame(BrandSource::Enriched, $product->brand_source);

        $brand = Brand::query()->where('tenant_id', $this->tenant->id)->where('slug', 'la-roche-posay')->sole();
        $this->assertSame($brand->id, $product->brand_id);

        $metadata = ParapharmacyProductMetadata::query()->where('product_id', $product->id)->sole();
        $this->assertSame(ParapharmacyCategory::Cosmetic, $metadata->category);
        $this->assertSame(2, Ingredient::query()->whereIn('slug', ['aqua', 'glycerin'])->count());
        $this->assertSame(2, DB::table('product_ingredient')->where('product_id', $product->id)->count());
        $this->assertSame(0, EnrichmentResult::query()->where('product_id', $product->id)->where('status', EnrichmentReviewStatus::PendingReview)->count());
    }

    public function test_apply_does_not_overwrite_user_brand(): void
    {
        $brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'User Brand',
            'slug' => 'user-brand',
            'is_active' => true,
        ]);

        $product = $this->makeProduct([
            'brand_id' => $brand->id,
            'brand_source' => BrandSource::User,
            'platform_product_id' => 'platform-product-001',
        ]);

        $this->service->applyCatalogHit($product, $this->makeCatalogProduct());

        $product->refresh();
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame(BrandSource::User, $product->brand_source);
    }

    public function test_apply_skips_ingredients_for_non_parapharmacy_vertical(): void
    {
        $this->makeCompanyContext(Vertical::Mechanic, 'catalog-enrichment-mechanic');
        $this->service = $this->app->make(CatalogEnrichmentService::class);

        $product = $this->makeProduct([
            'platform_product_id' => 'platform-product-001',
            'brand_id' => null,
        ]);

        $this->service->applyCatalogHit($product, $this->makeCatalogProduct());

        $product->refresh();
        $this->assertSame(EnrichmentStatus::Completed, $product->enrichment_status);
        $this->assertNotNull($product->brand_id);
        $this->assertSame(0, ParapharmacyProductMetadata::query()->where('product_id', $product->id)->count());
        $this->assertSame(0, Ingredient::query()->count());
        $this->assertSame(0, DB::table('product_ingredient')->where('product_id', $product->id)->count());
    }

    public function test_apply_maps_unknown_classification_category_to_other(): void
    {
        $product = $this->makeProduct(['platform_product_id' => 'platform-product-001']);

        $this->service->applyCatalogHit($product, $this->makeCatalogProduct(classificationCategory: 'unknown-category'));

        $metadata = ParapharmacyProductMetadata::query()->where('product_id', $product->id)->sole();
        $this->assertSame(ParapharmacyCategory::Other, $metadata->category);
    }

    public function test_apply_is_idempotent_for_existing_ingredients(): void
    {
        $ingredient = Ingredient::create([
            'slug' => 'aqua',
            'is_allergen' => false,
            'regulatory_status' => null,
        ]);
        IngredientTranslation::create([
            'ingredient_id' => $ingredient->id,
            'locale' => 'fr',
            'name' => 'Aqua',
            'description' => null,
        ]);
        $product = $this->makeProduct(['platform_product_id' => 'platform-product-001']);
        $catalog = $this->makeCatalogProduct(ingredients: [['name' => 'Aqua', 'position' => 1]]);

        $this->service->applyCatalogHit($product, $catalog);
        $this->service->applyCatalogHit($product->refresh(), $catalog);

        $this->assertSame(1, Ingredient::query()->where('slug', 'aqua')->count());
        $this->assertSame(1, DB::table('product_ingredient')->where('product_id', $product->id)->where('ingredient_id', $ingredient->id)->count());
        // Queue delivery is at-least-once: a re-delivered job must not mint a
        // second accepted result row for the same catalog hit.
        $this->assertSame(1, EnrichmentResult::query()->where('product_id', $product->id)->count());
    }

    public function test_apply_preserves_user_edited_description(): void
    {
        $product = $this->makeProduct([
            'platform_product_id' => 'platform-product-001',
            'description' => 'My own shop notes',
        ]);

        $this->service->applyCatalogHit($product, $this->makeCatalogProduct());

        $product->refresh();
        $this->assertSame('My own shop notes', $product->description);

        $result = EnrichmentResult::query()->where('product_id', $product->id)->sole();
        $this->assertArrayNotHasKey('description', $result->accepted_fields);
    }

    /**
     * @param  array<string, object|string|null>  $overrides
     */
    private function makeProduct(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Local Product',
            'barcode' => '3017620422003',
        ], $overrides));
    }

    /**
     * @param  list<array{name: string, position: int}>|null  $ingredients
     */
    private function makeCatalogProduct(
        ?string $classificationCategory = 'cosmetic',
        ?array $ingredients = null,
    ): CatalogProductDTO {
        return new CatalogProductDTO(
            platformProductId: 'platform-product-001',
            barcode: '3017620422003',
            name: 'Catalog Cream',
            brand: 'La Roche-Posay',
            description: 'Hydrating care',
            classification: ['category' => $classificationCategory],
            ingredients: $ingredients ?? [['name' => 'Aqua', 'position' => 1], ['name' => 'Glycerin', 'position' => 2]],
            images: [['url' => 'https://example.test/front.jpg', 'thumbnail' => null, 'type' => 'front']],
            confidenceScore: 96,
            enrichmentTier: 'catalog',
        );
    }

    private function makeCompanyContext(Vertical $vertical, string $slug): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Catalog Enrichment '.$vertical->value,
            'slug' => $slug.'-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => $vertical,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Catalog Enrichment Company',
            'legal_name' => 'Catalog Enrichment Company LLC',
            'tax_id' => 'TAX-'.Str::upper(Str::random(8)),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }
}
