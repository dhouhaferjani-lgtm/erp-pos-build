<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Application\Services\EnrichmentReviewService;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Enums\EnrichmentStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EnrichmentReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private EnrichmentReviewService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-enrichment',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Reviewer',
            'email' => 'reviewer@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-key']);

        $this->service = app(EnrichmentReviewService::class);
    }

    public function test_fetch_and_store_creates_enrichment_result(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Product',
        ]);

        $trackingId = 'trk-test-001';

        Http::fake([
            'platform.test/*' => Http::response([
                'tracking_id' => $trackingId,
                'status' => 'enriched',
                'enrichment_quality' => 'full',
                'enriched_data' => [
                    'name' => 'Enriched Name',
                    'brand' => 'Enriched Brand',
                    'description' => 'Enriched Description',
                    'classification' => ['category' => 'automotive'],
                    'ingredients' => [],
                    'images' => [],
                    'confidence_score' => 85,
                    'enrichment_tier' => 'high',
                    'assigned_barcode' => '3017620422003',
                    'assigned_barcode_type' => 'EAN-13',
                ],
            ]),
        ]);

        $result = $this->service->fetchAndStore($trackingId, $product);

        $this->assertNotNull($result);
        $this->assertInstanceOf(EnrichmentResult::class, $result);
        $this->assertSame($trackingId, $result->tracking_id);
        $this->assertSame($product->id, $result->product_id);
        $this->assertSame($this->tenant->id, $result->tenant_id);
        $this->assertSame($this->company->id, $result->company_id);
        $this->assertSame(EnrichmentReviewStatus::PendingReview, $result->status);
        $this->assertSame('full', $result->enrichment_quality);
        $this->assertSame('3017620422003', $result->assigned_barcode);
        $this->assertSame('Enriched Name', $result->enriched_data->name);
        $this->assertSame('Enriched Brand', $result->enriched_data->brand);
        $this->assertSame('Enriched Description', $result->enriched_data->description);
        $this->assertSame(85, $result->enriched_data->confidence_score);

        $this->assertDatabaseHas('enrichment_results', [
            'tracking_id' => $trackingId,
            'product_id' => $product->id,
        ]);
    }

    public function test_accept_merges_fields_into_product(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Name',
            'description' => 'Original Description',
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => 'sub-123',
        ]);

        $enrichmentResult = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => 'trk-accept-001',
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => new EnrichedProductData(
                name: 'Enriched Name',
                brand: 'Enriched Brand',
                description: 'Enriched Description',
                classification: [],
                ingredients: [],
                images: [],
                confidence_score: 85,
                enrichment_tier: 'high',
                field_confidence: null,
                enrichment_sources: null,
                assigned_barcode: null,
                assigned_barcode_type: null,
            ),
            'enrichment_quality' => 'full',
        ]);

        $this->service->accept($enrichmentResult, ['name', 'description'], $this->user->id);

        $product->refresh();
        $this->assertSame('Enriched Name', $product->name);
        $this->assertSame('Enriched Description', $product->description);
        $this->assertNull($product->enrichment_status);
        $this->assertNull($product->platform_submission_id);

        $enrichmentResult->refresh();
        $this->assertSame(EnrichmentReviewStatus::Accepted, $enrichmentResult->status);
        $this->assertNotNull($enrichmentResult->reviewed_at);
        $this->assertSame($this->user->id, $enrichmentResult->reviewed_by);
        $this->assertSame(['name' => true, 'description' => true], $enrichmentResult->accepted_fields);
    }

    public function test_reject_updates_statuses(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product To Reject',
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => 'sub-456',
        ]);

        $enrichmentResult = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => 'trk-reject-001',
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => new EnrichedProductData(
                name: 'Enriched Name',
                brand: 'Enriched Brand',
                description: 'Enriched Description',
                classification: [],
                ingredients: [],
                images: [],
                confidence_score: 50,
                enrichment_tier: 'low',
                field_confidence: null,
                enrichment_sources: null,
                assigned_barcode: null,
                assigned_barcode_type: null,
            ),
            'enrichment_quality' => 'partial',
        ]);

        $this->service->reject($enrichmentResult, $this->user->id, 'Data quality too low');

        $enrichmentResult->refresh();
        $this->assertSame(EnrichmentReviewStatus::Rejected, $enrichmentResult->status);
        $this->assertSame('Data quality too low', $enrichmentResult->rejection_reason);
        $this->assertNotNull($enrichmentResult->reviewed_at);
        $this->assertSame($this->user->id, $enrichmentResult->reviewed_by);

        $product->refresh();
        $this->assertSame(EnrichmentStatus::Rejected, $product->enrichment_status);
        $this->assertNull($product->platform_submission_id);
    }

    public function test_list_for_review_filters_by_quality(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $enrichedData = new EnrichedProductData(
            name: 'Name',
            brand: null,
            description: null,
            classification: [],
            ingredients: [],
            images: [],
            confidence_score: 50,
            enrichment_tier: null,
            field_confidence: null,
            enrichment_sources: null,
            assigned_barcode: null,
            assigned_barcode_type: null,
        );

        EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => 'trk-quality-full',
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $enrichedData,
            'enrichment_quality' => 'full',
        ]);

        EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => 'trk-quality-partial',
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $enrichedData,
            'enrichment_quality' => 'partial',
        ]);

        EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => 'trk-quality-full-2',
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $enrichedData,
            'enrichment_quality' => 'full',
        ]);

        $results = $this->service->listForReview(
            $this->tenant->id,
            $this->company->id,
            null,
            'full',
        );

        $this->assertSame(2, $results->total());

        $trackingIds = collect($results->items())->pluck('tracking_id')->toArray();
        $this->assertContains('trk-quality-full', $trackingIds);
        $this->assertContains('trk-quality-full-2', $trackingIds);
        $this->assertNotContains('trk-quality-partial', $trackingIds);
    }
}
