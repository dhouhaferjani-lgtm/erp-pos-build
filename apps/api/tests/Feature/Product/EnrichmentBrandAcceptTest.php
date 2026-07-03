<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Application\Services\EnrichmentReviewService;
use App\Modules\Product\Domain\Brand;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\BrandSource;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Enums\EnrichmentStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class EnrichmentBrandAcceptTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private EnrichmentReviewService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-enrichment-brand',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Reviewer',
            'email' => 'reviewer-brand@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->service = app(EnrichmentReviewService::class);
    }

    /**
     * Build a minimal EnrichmentResult for a product with the given brand name.
     */
    private function makeEnrichmentResult(Product $product, ?string $brand = null): EnrichmentResult
    {
        return EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => (string) Str::uuid(),
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => new EnrichedProductData(
                name: $product->name.' Enriched',
                brand: $brand,
                description: null,
                classification: [],
                ingredients: [],
                images: [],
                confidence_score: 90,
                enrichment_tier: 'high',
                field_confidence: null,
                enrichment_sources: null,
                assigned_barcode: null,
                assigned_barcode_type: null,
            ),
            'enrichment_quality' => 'full',
        ]);
    }

    public function test_accepting_brand_field_creates_brand_and_links_to_product(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Avène Cleanser',
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => (string) Str::uuid(),
        ]);

        $enrichmentResult = $this->makeEnrichmentResult($product, 'Avène');

        $this->service->accept($enrichmentResult, ['brand'], $this->user->id);

        // Brand should be created with the normalized slug
        $this->assertDatabaseHas('brands', [
            'tenant_id' => $this->tenant->id,
            'slug' => 'avene',
            'name' => 'Avène',
            'is_active' => true,
        ]);

        $product->refresh();

        // Product should be linked to the created brand
        $this->assertNotNull($product->brand_id);
        $this->assertSame(BrandSource::Enriched, $product->brand_source);

        $brand = Brand::where('tenant_id', $this->tenant->id)->where('slug', 'avene')->first();
        $this->assertNotNull($brand);
        $this->assertSame($brand->id, $product->brand_id);

        // Enrichment tracking should be cleared
        $this->assertNull($product->enrichment_status);
        $this->assertNull($product->platform_submission_id);

        // EnrichmentResult should be marked accepted
        $enrichmentResult->refresh();
        $this->assertSame(EnrichmentReviewStatus::Accepted, $enrichmentResult->status);
        $this->assertNotNull($enrichmentResult->reviewed_at);
        $this->assertSame($this->user->id, $enrichmentResult->reviewed_by);
    }

    public function test_accepting_same_brand_for_second_product_reuses_existing_brand_row(): void
    {
        $productA = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Avène Cleanser',
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => (string) Str::uuid(),
        ]);

        $productB = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Avène Sunscreen',
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => (string) Str::uuid(),
        ]);

        $enrichmentA = $this->makeEnrichmentResult($productA, 'Avène');
        $enrichmentB = $this->makeEnrichmentResult($productB, 'Avène');

        $this->service->accept($enrichmentA, ['brand'], $this->user->id);
        $this->service->accept($enrichmentB, ['brand'], $this->user->id);

        // Exactly one Brand row with this slug should exist
        $this->assertSame(
            1,
            Brand::where('tenant_id', $this->tenant->id)->where('slug', 'avene')->count(),
        );

        $productA->refresh();
        $productB->refresh();

        // Both products must point to the same brand row
        $this->assertNotNull($productA->brand_id);
        $this->assertNotNull($productB->brand_id);
        $this->assertSame($productA->brand_id, $productB->brand_id);
        $this->assertSame(BrandSource::Enriched, $productA->brand_source);
        $this->assertSame(BrandSource::Enriched, $productB->brand_source);
    }
}
