<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Application\Jobs\SendEnrichmentFeedbackJob;
use App\Modules\Product\Application\Services\EnrichmentReviewService;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\EnrichmentResultOrigin;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Enums\EnrichmentFeedbackAction;
use App\Shared\Enums\EnrichmentFeedbackReason;
use App\Shared\Enums\EnrichmentStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
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

        // Bind CompanyContext — required since api.platform-integration
        // cluster made tenant headers mandatory on PlatformHttpClient,
        // which EnrichmentReviewService transitively calls via
        // ProductSubmissionService::checkStatus().
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->service = app(EnrichmentReviewService::class);
    }

    public function test_fetch_and_store_creates_enrichment_result(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Product',
        ]);

        $trackingId = (string) Str::uuid();

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
        $this->assertSame(1, $result->version);
        $this->assertSame(EnrichmentResultOrigin::Initial, $result->origin);
        $this->assertSame('full', $result->enrichment_quality);
        $this->assertSame('3017620422003', $result->assigned_barcode);
        $this->assertSame('Enriched Name', $result->enriched_data->name);
        $this->assertSame('Enriched Brand', $result->enriched_data->brand);
        $this->assertSame('Enriched Description', $result->enriched_data->description);
        $this->assertSame(85, $result->enriched_data->confidence_score);

        $this->assertDatabaseHas('enrichment_results', [
            'tracking_id' => $trackingId,
            'product_id' => $product->id,
            'version' => 1,
            'origin' => 'initial',
        ]);
    }

    public function test_fetch_and_store_updates_pending_result_in_place(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Product',
        ]);

        $trackingId = (string) Str::uuid();
        $existing = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => $trackingId,
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $this->enrichedData(name: 'Old Name'),
            'enrichment_quality' => 'partial',
            'assigned_barcode' => null,
        ]);

        Http::fake([
            'platform.test/*' => Http::response([
                'tracking_id' => $trackingId,
                'status' => 'enriched',
                'enrichment_quality' => 'full',
                'assigned_barcode' => '3017620422003',
                'enriched_data' => [
                    'name' => 'Updated Name',
                    'brand' => 'Updated Brand',
                    'confidence_score' => 91,
                ],
            ]),
        ]);

        $result = $this->service->fetchAndStore($trackingId, $product);

        $this->assertNotNull($result);
        $this->assertSame($existing->id, $result->id);
        $this->assertSame(1, $result->version);
        $this->assertSame(EnrichmentResultOrigin::Initial, $result->origin);
        $this->assertSame(EnrichmentReviewStatus::PendingReview, $result->status);
        $this->assertSame('Updated Name', $result->enriched_data->name);
        $this->assertSame('full', $result->enrichment_quality);
        $this->assertSame('3017620422003', $result->assigned_barcode);
        $this->assertSame(1, EnrichmentResult::where('tracking_id', $trackingId)->count());
    }

    public function test_fetch_and_store_keeps_accepted_result_when_payload_matches_except_locale(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Product',
        ]);

        $trackingId = (string) Str::uuid();
        $accepted = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => $trackingId,
            'status' => EnrichmentReviewStatus::Accepted,
            'enriched_data' => $this->enrichedData(name: 'Enriched Name', locale: 'en_US'),
            'enrichment_quality' => 'full',
            'assigned_barcode' => '3017620422003',
            'reviewed_at' => now(),
            'reviewed_by' => $this->user->id,
            'accepted_fields' => ['name' => true],
        ]);

        Http::fake([
            'platform.test/*' => Http::response([
                'tracking_id' => $trackingId,
                'status' => 'approved',
                'enrichment_quality' => 'full',
                'assigned_barcode' => '3017620422003',
                'locale' => 'fr_FR',
                'enriched_data' => [
                    'name' => 'Enriched Name',
                    'brand' => 'Enriched Brand',
                    'description' => 'Enriched Description',
                    'classification' => [],
                    'ingredients' => [],
                    'images' => [],
                    'confidence_score' => 85,
                    'enrichment_tier' => 'high',
                    'assigned_barcode' => '3017620422003',
                    'assigned_barcode_type' => 'EAN-13',
                ],
            ]),
        ]);

        $result = $this->service->fetchAndStore($trackingId, $product, 'ar_TN');

        $this->assertNotNull($result);
        $this->assertSame($accepted->id, $result->id);
        $this->assertSame(EnrichmentReviewStatus::Accepted, $result->status);
        $this->assertSame(1, $result->version);
        $this->assertSame('en_US', $result->enriched_data->locale);
        $this->assertSame(1, EnrichmentResult::where('tracking_id', $trackingId)->count());
    }

    public function test_fetch_and_store_creates_curated_update_for_changed_accepted_result(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Product',
        ]);

        $trackingId = (string) Str::uuid();
        $accepted = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => $trackingId,
            'status' => EnrichmentReviewStatus::Accepted,
            'enriched_data' => $this->enrichedData(name: 'Accepted Name'),
            'enrichment_quality' => 'full',
            'assigned_barcode' => '3017620422003',
            'reviewed_at' => now(),
            'reviewed_by' => $this->user->id,
            'accepted_fields' => ['name' => true],
        ]);
        $accepted->refresh();
        $acceptedAttributesBefore = $accepted->getAttributes();
        ksort($acceptedAttributesBefore);

        Http::fake([
            'platform.test/*' => Http::response([
                'tracking_id' => $trackingId,
                'status' => 'approved',
                'enrichment_quality' => 'full',
                'assigned_barcode' => '3017620422003',
                'enriched_data' => [
                    'name' => 'Curated Name',
                    'brand' => 'Enriched Brand',
                    'description' => 'Enriched Description',
                    'confidence_score' => 85,
                    'enrichment_tier' => 'high',
                    'assigned_barcode' => '3017620422003',
                    'assigned_barcode_type' => 'EAN-13',
                ],
            ]),
        ]);

        $result = $this->service->fetchAndStore($trackingId, $product);

        $this->assertNotNull($result);
        $this->assertNotSame($accepted->id, $result->id);
        $this->assertSame(2, $result->version);
        $this->assertSame(EnrichmentResultOrigin::CuratedUpdate, $result->origin);
        $this->assertSame(EnrichmentReviewStatus::PendingReview, $result->status);
        $this->assertSame('Curated Name', $result->enriched_data->name);
        $this->assertNull($result->reviewed_at);
        $this->assertNull($result->reviewed_by);
        $this->assertNull($result->accepted_fields);
        $this->assertSame(2, EnrichmentResult::where('tracking_id', $trackingId)->count());

        $accepted->refresh();
        $acceptedAttributesAfter = $accepted->getAttributes();
        ksort($acceptedAttributesAfter);
        $this->assertSame($acceptedAttributesBefore, $acceptedAttributesAfter);
    }

    public function test_fetch_and_store_creates_curated_update_for_changed_rejected_result(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Product',
        ]);

        $trackingId = (string) Str::uuid();
        EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => $trackingId,
            'status' => EnrichmentReviewStatus::Rejected,
            'enriched_data' => $this->enrichedData(name: 'Rejected Name'),
            'enrichment_quality' => 'partial',
            'reviewed_at' => now(),
            'reviewed_by' => $this->user->id,
            'rejection_reason' => EnrichmentFeedbackReason::BadData->value,
            'rejection_notes' => 'Bad description',
        ]);

        Http::fake([
            'platform.test/*' => Http::response([
                'tracking_id' => $trackingId,
                'status' => 'approved',
                'enrichment_quality' => 'full',
                'enriched_data' => [
                    'name' => 'Fixed Name',
                    'brand' => 'Enriched Brand',
                    'description' => 'Fixed Description',
                    'confidence_score' => 85,
                    'enrichment_tier' => 'high',
                ],
            ]),
        ]);

        $result = $this->service->fetchAndStore($trackingId, $product);

        $this->assertNotNull($result);
        $this->assertSame(2, $result->version);
        $this->assertSame(EnrichmentResultOrigin::CuratedUpdate, $result->origin);
        $this->assertSame(EnrichmentReviewStatus::PendingReview, $result->status);
        $this->assertSame('Fixed Name', $result->enriched_data->name);
        $this->assertSame(2, EnrichmentResult::where('tracking_id', $trackingId)->count());
    }

    public function test_fetch_and_store_persists_locale_from_lookup_status(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Product',
        ]);

        $trackingId = (string) Str::uuid();

        Http::fake([
            'platform.test/*' => Http::response([
                'tracking_id' => $trackingId,
                'status' => 'approved',
                'enrichment_quality' => 'full',
                'locale' => 'fr_FR',
                'enriched_data' => [
                    'name' => 'Doliprane 1000mg',
                    'confidence_score' => 90,
                ],
            ]),
        ]);

        $result = $this->service->fetchAndStore($trackingId, $product);

        $this->assertNotNull($result);
        $this->assertSame('fr_FR', $result->enriched_data->locale);
    }

    public function test_fetch_and_store_persists_brand_mapping_fields(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Product',
        ]);

        $trackingId = (string) Str::uuid();
        $canonical = (string) Str::uuid();

        Http::fake([
            'platform.test/*' => Http::response([
                'tracking_id' => $trackingId,
                'status' => 'enriched',
                'enrichment_quality' => 'full',
                'enriched_data' => [
                    'name' => 'Enriched Name',
                    'brand' => 'La Roche-Posay',
                    'description' => 'Enriched Description',
                    'classification' => [],
                    'ingredients' => [],
                    'images' => [],
                    'confidence_score' => 85,
                    'enrichment_tier' => 'high',
                    'assigned_barcode' => '3017620422003',
                    'assigned_barcode_type' => 'EAN-13',
                    'canonical_brand_id' => $canonical,
                    'canonical_brand_slug' => 'la-roche-posay',
                    'external_brand_id' => 'erp-brand-42',
                ],
            ]),
        ]);

        $result = $this->service->fetchAndStore($trackingId, $product);

        $this->assertNotNull($result);
        $this->assertSame($canonical, $result->enriched_data->canonical_brand_id);
        $this->assertSame('la-roche-posay', $result->enriched_data->canonical_brand_slug);
        $this->assertSame('erp-brand-42', $result->enriched_data->external_brand_id);
    }

    public function test_payload_differing_only_in_mapping_fields_is_a_no_op_for_accepted_result(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Product',
        ]);

        $trackingId = (string) Str::uuid();
        $canonical = (string) Str::uuid();
        $accepted = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => $trackingId,
            'status' => EnrichmentReviewStatus::Accepted,
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
                assigned_barcode: '3017620422003',
                assigned_barcode_type: 'EAN-13',
                external_brand_id: null,
            ),
            'enrichment_quality' => 'full',
            'assigned_barcode' => '3017620422003',
            'reviewed_at' => now(),
            'reviewed_by' => $this->user->id,
            'accepted_fields' => ['name' => true],
        ]);

        Http::fake([
            'platform.test/*' => Http::response([
                'tracking_id' => $trackingId,
                'status' => 'approved',
                'enrichment_quality' => 'full',
                'assigned_barcode' => '3017620422003',
                'enriched_data' => [
                    'name' => 'Enriched Name',
                    'brand' => 'Enriched Brand',
                    'description' => 'Enriched Description',
                    'classification' => [],
                    'ingredients' => [],
                    'images' => [],
                    'confidence_score' => 85,
                    'enrichment_tier' => 'high',
                    'assigned_barcode' => '3017620422003',
                    'assigned_barcode_type' => 'EAN-13',
                    'canonical_brand_id' => $canonical,
                    'canonical_brand_slug' => 'enriched-brand',
                    'external_brand_id' => 'erp-brand-42',
                ],
            ]),
        ]);

        $result = $this->service->fetchAndStore($trackingId, $product);

        $this->assertNotNull($result);
        $this->assertSame($accepted->id, $result->id);
        $this->assertSame(1, EnrichmentResult::where('tracking_id', $trackingId)->count());
    }

    public function test_legacy_enriched_data_without_mapping_keys_hydrates(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Product',
        ]);

        $result = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => (string) Str::uuid(),
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $this->enrichedData(name: 'Legacy Name'),
            'enrichment_quality' => 'full',
        ]);

        $rehydrated = EnrichmentResult::findOrFail($result->id);

        $this->assertNull($rehydrated->enriched_data->canonical_brand_id);
        $this->assertNull($rehydrated->enriched_data->canonical_brand_slug);
        $this->assertNull($rehydrated->enriched_data->external_brand_id);
    }

    public function test_accept_merges_fields_into_product(): void
    {
        Queue::fake();

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Name',
            'description' => 'Original Description',
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => (string) Str::uuid(),
        ]);

        $enrichmentResult = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => (string) Str::uuid(),
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

        Queue::assertPushed(SendEnrichmentFeedbackJob::class, function (SendEnrichmentFeedbackJob $job) use ($enrichmentResult): bool {
            return $job->trackingId === $enrichmentResult->tracking_id
                && $job->action === EnrichmentFeedbackAction::Confirmed->value
                && $job->reason === null
                && $job->notes === null
                && $job->companyId === $this->company->id;
        });
    }

    public function test_accept_does_not_propagate_feedback_dispatch_failure_after_local_state_commits(): void
    {
        app()->instance(Dispatcher::class, new ThrowingFeedbackDispatcher);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Name',
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => (string) Str::uuid(),
        ]);

        $enrichmentResult = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => (string) Str::uuid(),
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $this->enrichedData(name: 'Accepted Name'),
            'enrichment_quality' => 'full',
        ]);

        $this->service->accept($enrichmentResult, ['name'], $this->user->id);

        $product->refresh();
        $this->assertSame('Accepted Name', $product->name);
        $this->assertNull($product->enrichment_status);
        $this->assertNull($product->platform_submission_id);

        $enrichmentResult->refresh();
        $this->assertSame(EnrichmentReviewStatus::Accepted, $enrichmentResult->status);
        $this->assertSame(['name' => true], $enrichmentResult->accepted_fields);
    }

    public function test_accept_completes_when_inline_feedback_job_fails_after_platform_503(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Name',
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => (string) Str::uuid(),
        ]);

        $enrichmentResult = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => (string) Str::uuid(),
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $this->enrichedData(name: 'Accepted Name'),
            'enrichment_quality' => 'full',
        ]);

        Http::fake([
            'platform.test/*' => Http::response(['message' => 'unavailable'], 503),
        ]);

        $this->service->accept($enrichmentResult, ['name'], $this->user->id);

        $product->refresh();
        $this->assertSame('Accepted Name', $product->name);
        $this->assertNull($product->enrichment_status);
        $this->assertNull($product->platform_submission_id);

        $enrichmentResult->refresh();
        $this->assertSame(EnrichmentReviewStatus::Accepted, $enrichmentResult->status);
        $this->assertSame(['name' => true], $enrichmentResult->accepted_fields);
    }

    public function test_accept_does_not_overwrite_existing_product_fields_with_null_enriched_values(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Name',
            'description' => 'Original Description',
            'barcode' => '3017620422003',
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => (string) Str::uuid(),
        ]);

        $enrichmentResult = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => (string) Str::uuid(),
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => new EnrichedProductData(
                name: 'Enriched Name',
                brand: null,
                description: null,
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

        $this->service->accept($enrichmentResult, ['name', 'brand', 'description', 'barcode'], $this->user->id);

        $product->refresh();
        $this->assertSame('Enriched Name', $product->name);
        $this->assertSame('Original Description', $product->description);
        $this->assertSame('3017620422003', $product->barcode);
    }

    public function test_reject_updates_statuses(): void
    {
        Queue::fake();

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product To Reject',
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => (string) Str::uuid(),
        ]);

        $enrichmentResult = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => (string) Str::uuid(),
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

        $this->service->reject(
            $enrichmentResult,
            $this->user->id,
            EnrichmentFeedbackReason::BadData,
            'Data quality too low',
        );

        $enrichmentResult->refresh();
        $this->assertSame(EnrichmentReviewStatus::Rejected, $enrichmentResult->status);
        $this->assertSame(EnrichmentFeedbackReason::BadData->value, $enrichmentResult->rejection_reason);
        $this->assertSame('Data quality too low', $enrichmentResult->rejection_notes);
        $this->assertNotNull($enrichmentResult->reviewed_at);
        $this->assertSame($this->user->id, $enrichmentResult->reviewed_by);

        $product->refresh();
        $this->assertSame(EnrichmentStatus::Rejected, $product->enrichment_status);
        $this->assertNull($product->platform_submission_id);

        Queue::assertPushed(SendEnrichmentFeedbackJob::class, function (SendEnrichmentFeedbackJob $job) use ($enrichmentResult): bool {
            return $job->trackingId === $enrichmentResult->tracking_id
                && $job->action === EnrichmentFeedbackAction::Rejected->value
                && $job->reason === EnrichmentFeedbackReason::BadData->value
                && $job->notes === 'Data quality too low'
                && $job->companyId === $this->company->id;
        });
    }

    public function test_reject_does_not_propagate_feedback_dispatch_failure_after_local_state_commits(): void
    {
        app()->instance(Dispatcher::class, new ThrowingFeedbackDispatcher);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product To Reject',
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => (string) Str::uuid(),
        ]);

        $enrichmentResult = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => (string) Str::uuid(),
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $this->enrichedData(name: 'Rejected Name'),
            'enrichment_quality' => 'partial',
        ]);

        $this->service->reject(
            $enrichmentResult,
            $this->user->id,
            EnrichmentFeedbackReason::BadData,
            'Data quality too low',
        );

        $enrichmentResult->refresh();
        $this->assertSame(EnrichmentReviewStatus::Rejected, $enrichmentResult->status);
        $this->assertSame(EnrichmentFeedbackReason::BadData->value, $enrichmentResult->rejection_reason);
        $this->assertSame('Data quality too low', $enrichmentResult->rejection_notes);

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

        $fullTrackingId = (string) Str::uuid();
        $partialTrackingId = (string) Str::uuid();
        $secondFullTrackingId = (string) Str::uuid();

        EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => $fullTrackingId,
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $enrichedData,
            'enrichment_quality' => 'full',
        ]);

        EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => $partialTrackingId,
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $enrichedData,
            'enrichment_quality' => 'partial',
        ]);

        EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => $secondFullTrackingId,
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

        $trackingIds = array_map(
            static fn (EnrichmentResult $result): string => $result->tracking_id,
            $results->items(),
        );
        $this->assertContains($fullTrackingId, $trackingIds);
        $this->assertContains($secondFullTrackingId, $trackingIds);
        $this->assertNotContains($partialTrackingId, $trackingIds);
    }

    private function enrichedData(string $name, ?string $locale = null): EnrichedProductData
    {
        return new EnrichedProductData(
            name: $name,
            brand: 'Enriched Brand',
            description: 'Enriched Description',
            classification: [],
            ingredients: [],
            images: [],
            confidence_score: 85,
            enrichment_tier: 'high',
            field_confidence: null,
            enrichment_sources: null,
            assigned_barcode: '3017620422003',
            assigned_barcode_type: 'EAN-13',
            locale: $locale,
        );
    }
}

final class ThrowingFeedbackDispatcher implements Dispatcher
{
    public function dispatch($command): never
    {
        throw new RuntimeException('Queue unavailable');
    }

    public function dispatchSync($command, $handler = null): never
    {
        throw new RuntimeException('Queue unavailable');
    }

    public function dispatchNow($command, $handler = null): never
    {
        throw new RuntimeException('Queue unavailable');
    }

    public function hasCommandHandler($command): bool
    {
        return false;
    }

    public function getCommandHandler($command): null
    {
        return null;
    }

    /**
     * @param  array<int, mixed>  $pipes
     */
    public function pipeThrough(array $pipes): self
    {
        return $this;
    }

    /**
     * @param  array<class-string, class-string>  $map
     */
    public function map(array $map): self
    {
        return $this;
    }
}
