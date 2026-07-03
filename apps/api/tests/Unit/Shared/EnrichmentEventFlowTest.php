<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Application\Listeners\ProcessEnrichmentEventListener;
use App\Modules\Product\Application\Services\EnrichmentReviewService;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\EnrichmentResultOrigin;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\PlatformSubmissionInterface;
use App\Shared\DTOs\SubmissionStatusDTO;
use App\Shared\Enums\EnrichmentStatus;
use App\Shared\Events\EnrichmentResultReadyEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class EnrichmentEventFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-event-flow',
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
    }

    public function test_listener_dispatches_enrichment_result_ready_event_on_completion(): void
    {
        Event::fake([EnrichmentResultReadyEvent::class]);

        // platform_submission_id / tracking_id are uuid columns on PostgreSQL;
        // the same value correlates the product, webhook and result rows.
        $trackingId = (string) Str::uuid();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'enrichment_status' => EnrichmentStatus::Enriching,
            'platform_submission_id' => $trackingId,
        ]);
        // Mock the PlatformSubmissionInterface to return enrichment data
        $mockSubmission = $this->createMock(PlatformSubmissionInterface::class);
        $mockSubmission->method('checkStatus')->willReturn(new SubmissionStatusDTO(
            trackingId: $trackingId,
            status: 'enriched',
            enrichmentQuality: 'full',
            enrichedData: [
                'name' => 'Enriched Name',
                'confidence_score' => 90,
            ],
            assignedBarcode: '3017620422003',
            vertical: 'automotive',
        ));

        $reviewService = new EnrichmentReviewService($mockSubmission);
        $listener = new ProcessEnrichmentEventListener($reviewService, app(CompanyContext::class));

        $webhookEvent = new EnrichmentWebhookReceived(
            $trackingId,
            'enriched',
            'full',
            true,
            'automotive',
        );

        $listener->handle($webhookEvent);

        $this->assertFalse(app(CompanyContext::class)->hasCompany());

        // Product status should be updated
        $product->refresh();
        $this->assertSame(EnrichmentStatus::Completed, $product->enrichment_status);

        // Enrichment result should be stored
        $this->assertDatabaseHas('enrichment_results', [
            'tracking_id' => $trackingId,
            'product_id' => $product->id,
        ]);

        // EnrichmentResultReadyEvent should have been dispatched (not direct User query)
        Event::assertDispatched(EnrichmentResultReadyEvent::class, function (EnrichmentResultReadyEvent $event) use ($product): bool {
            return $event->companyId === $this->company->id
                && $event->productId === $product->id
                && $event->productName === 'Test Product'
                && $event->enrichmentQuality === 'full'
                && $event->assignedBarcode === '3017620422003';
        });
    }

    public function test_approved_webhook_stores_result_and_dispatches_notification(): void
    {
        // Regression guard for the launch-blocking H2 contract: the platform
        // fires enrichment.resolved with status "approved" on operator
        // approval. "approved" MUST map to a terminal ERP status that fetches
        // and persists the enriched data AND notifies the user. If the mapping
        // ever regresses (approved no longer terminal), enriched data would
        // silently never land — this test fails loud.
        Event::fake([EnrichmentResultReadyEvent::class]);

        $trackingId = (string) Str::uuid();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Approved Product',
            'enrichment_status' => EnrichmentStatus::Enriching,
            'platform_submission_id' => $trackingId,
        ]);

        // The lookup-status response deliberately omits locale, so the only
        // surviving source is the webhook's top-level locale field. This
        // pins the contract that the webhook locale is threaded through to
        // persistence (not silently dropped in favour of lookup-status).
        $mockSubmission = $this->createMock(PlatformSubmissionInterface::class);
        $mockSubmission->method('checkStatus')->willReturn(new SubmissionStatusDTO(
            trackingId: $trackingId,
            status: 'approved',
            enrichmentQuality: 'full',
            enrichedData: [
                'name' => 'Doliprane 1000mg',
                'confidence_score' => 95,
            ],
            assignedBarcode: '3400930000000',
            vertical: 'parapharmacy',
            locale: null,
        ));

        $reviewService = new EnrichmentReviewService($mockSubmission);
        $listener = new ProcessEnrichmentEventListener($reviewService, app(CompanyContext::class));

        $listener->handle(new EnrichmentWebhookReceived(
            $trackingId,
            'approved',
            'full',
            true,
            'parapharmacy',
            'fr_FR',
        ));

        // Approved maps to a terminal status → product marked Completed
        $product->refresh();
        $this->assertSame(EnrichmentStatus::Completed, $product->enrichment_status);

        // Enriched data must actually land in enrichment_results
        $this->assertDatabaseHas('enrichment_results', [
            'tracking_id' => $trackingId,
            'product_id' => $product->id,
        ]);

        // The webhook locale must be persisted (lookup-status omitted it)
        $stored = EnrichmentResult::where('tracking_id', $trackingId)->sole();
        $this->assertSame('fr_FR', $stored->enriched_data->locale);

        // The user must be notified
        Event::assertDispatched(EnrichmentResultReadyEvent::class, function (EnrichmentResultReadyEvent $event) use ($product): bool {
            return $event->companyId === $this->company->id
                && $event->productId === $product->id
                && $event->productName === 'Approved Product'
                && $event->enrichmentQuality === 'full'
                && $event->assignedBarcode === '3400930000000';
        });
    }

    public function test_not_enrichable_webhook_resolves_without_crash_or_notification(): void
    {
        // H2: the platform now sends a terminal enrichment.resolved webhook
        // with status "not_enrichable". The ERP must mark the submission
        // resolved-without-data and must NOT crash or notify the user.
        Event::fake([EnrichmentResultReadyEvent::class]);

        $trackingId = (string) Str::uuid();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Not Enrichable Product',
            'enrichment_status' => EnrichmentStatus::Enriching,
            'platform_submission_id' => $trackingId,
        ]);

        // A not-enrichable resolution has no enriched data to fetch, so the
        // platform lookup-status must NOT be hit and no review-queue row may
        // be created — otherwise an operator sees a phantom pending_review
        // item for a permanently un-enrichable product.
        $mockSubmission = $this->createMock(PlatformSubmissionInterface::class);
        $mockSubmission->expects($this->never())->method('checkStatus');

        $reviewService = new EnrichmentReviewService($mockSubmission);
        $listener = new ProcessEnrichmentEventListener($reviewService, app(CompanyContext::class));

        $listener->handle(new EnrichmentWebhookReceived(
            $trackingId,
            'not_enrichable',
            null,
            false,
            'parapharmacy',
            'fr_FR',
        ));

        // Submission is marked resolved-without-data (terminal, non-Completed)
        $product->refresh();
        $this->assertSame(EnrichmentStatus::NotEnrichable, $product->enrichment_status);

        // No phantom review-queue row
        $this->assertDatabaseMissing('enrichment_results', [
            'tracking_id' => $trackingId,
        ]);

        // No user notification for a not-enrichable resolution
        Event::assertNotDispatched(EnrichmentResultReadyEvent::class);
    }

    public function test_listener_does_not_dispatch_event_for_failed_enrichments(): void
    {
        Event::fake([EnrichmentResultReadyEvent::class]);

        $trackingId = (string) Str::uuid();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Failed Product',
            'enrichment_status' => EnrichmentStatus::Enriching,
            'platform_submission_id' => $trackingId,
        ]);

        // A failed enrichment carries no reviewable data: lookup-status must
        // not be hit and no review-queue row may be created.
        $mockSubmission = $this->createMock(PlatformSubmissionInterface::class);
        $mockSubmission->expects($this->never())->method('checkStatus');

        $reviewService = new EnrichmentReviewService($mockSubmission);
        $listener = new ProcessEnrichmentEventListener($reviewService, app(CompanyContext::class));

        $webhookEvent = new EnrichmentWebhookReceived(
            $trackingId,
            'failed',
            null,
            false,
            'automotive',
        );

        $listener->handle($webhookEvent);

        $product->refresh();
        $this->assertSame(EnrichmentStatus::Failed, $product->enrichment_status);

        // No phantom review-queue row
        $this->assertDatabaseMissing('enrichment_results', [
            'tracking_id' => $trackingId,
        ]);

        // Failed enrichment should NOT trigger notification event
        Event::assertNotDispatched(EnrichmentResultReadyEvent::class);
    }

    public function test_listener_does_not_dispatch_event_for_non_terminal_status(): void
    {
        Event::fake([EnrichmentResultReadyEvent::class]);

        $trackingId = (string) Str::uuid();
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'In Progress Product',
            'enrichment_status' => EnrichmentStatus::Pending,
            'platform_submission_id' => $trackingId,
        ]);

        $mockSubmission = $this->createMock(PlatformSubmissionInterface::class);
        // checkStatus should not be called for non-terminal status
        $mockSubmission->expects($this->never())->method('checkStatus');

        $reviewService = new EnrichmentReviewService($mockSubmission);
        $listener = new ProcessEnrichmentEventListener($reviewService, app(CompanyContext::class));

        $webhookEvent = new EnrichmentWebhookReceived(
            $trackingId,
            'enriching', // Non-terminal
            null,
            false,
            'automotive',
        );

        $listener->handle($webhookEvent);

        Event::assertNotDispatched(EnrichmentResultReadyEvent::class);
    }

    public function test_listener_falls_back_to_existing_result_after_accept_and_creates_curated_update(): void
    {
        Event::fake([EnrichmentResultReadyEvent::class]);

        $trackingId = (string) Str::uuid();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Accepted Product',
            'enrichment_status' => null,
            'platform_submission_id' => null,
        ]);

        EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => $trackingId,
            'status' => EnrichmentReviewStatus::Accepted,
            'enriched_data' => $this->enrichedData('Accepted Name'),
            'enrichment_quality' => 'full',
            'assigned_barcode' => '3017620422003',
            'reviewed_at' => now(),
        ]);

        $mockSubmission = $this->createMock(PlatformSubmissionInterface::class);
        $mockSubmission->method('checkStatus')->willReturn(new SubmissionStatusDTO(
            trackingId: $trackingId,
            status: 'approved',
            enrichmentQuality: 'full',
            enrichedData: [
                'name' => 'Curated Name',
                'brand' => 'Enriched Brand',
                'description' => 'Enriched Description',
                'confidence_score' => 90,
            ],
            assignedBarcode: '3017620422003',
            vertical: 'automotive',
        ));

        $listener = new ProcessEnrichmentEventListener(
            new EnrichmentReviewService($mockSubmission),
            app(CompanyContext::class),
        );

        $listener->handle(new EnrichmentWebhookReceived(
            $trackingId,
            'approved',
            'full',
            true,
            'automotive',
            'fr_FR',
        ));

        $product->refresh();
        $this->assertNull($product->enrichment_status);
        $this->assertNull($product->platform_submission_id);

        $curated = EnrichmentResult::where('tracking_id', $trackingId)
            ->where('version', 2)
            ->sole();

        $this->assertSame(EnrichmentReviewStatus::PendingReview, $curated->status);
        $this->assertSame(EnrichmentResultOrigin::CuratedUpdate, $curated->origin);
        $this->assertSame('Curated Name', $curated->enriched_data->name);

        Event::assertDispatched(EnrichmentResultReadyEvent::class, function (EnrichmentResultReadyEvent $event) use ($curated): bool {
            return $event->enrichmentResultId === $curated->id
                && $event->companyId === $this->company->id;
        });
    }

    public function test_listener_does_not_dispatch_ready_event_when_pending_result_is_updated_in_place(): void
    {
        Event::fake([EnrichmentResultReadyEvent::class]);

        $trackingId = (string) Str::uuid();
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Pending Product',
            'enrichment_status' => EnrichmentStatus::Enriching,
            'platform_submission_id' => $trackingId,
        ]);

        EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => Product::where('platform_submission_id', $trackingId)->sole()->id,
            'tracking_id' => $trackingId,
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $this->enrichedData('Old Pending Name'),
            'enrichment_quality' => 'partial',
        ]);

        $mockSubmission = $this->createMock(PlatformSubmissionInterface::class);
        $mockSubmission->method('checkStatus')->willReturn(new SubmissionStatusDTO(
            trackingId: $trackingId,
            status: 'enriched',
            enrichmentQuality: 'full',
            enrichedData: [
                'name' => 'Updated Pending Name',
                'confidence_score' => 90,
            ],
            assignedBarcode: null,
            vertical: 'automotive',
        ));

        $listener = new ProcessEnrichmentEventListener(
            new EnrichmentReviewService($mockSubmission),
            app(CompanyContext::class),
        );

        $listener->handle(new EnrichmentWebhookReceived(
            $trackingId,
            'enriched',
            'full',
            true,
            'automotive',
        ));

        Event::assertNotDispatched(EnrichmentResultReadyEvent::class);
    }

    public function test_listener_clears_company_context_when_fetch_and_store_throws(): void
    {
        Event::fake([EnrichmentResultReadyEvent::class]);

        $trackingId = (string) Str::uuid();
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Throwing Product',
            'enrichment_status' => EnrichmentStatus::Enriching,
            'platform_submission_id' => $trackingId,
        ]);

        $mockSubmission = $this->createMock(PlatformSubmissionInterface::class);
        $mockSubmission->method('checkStatus')->willThrowException(new RuntimeException('Platform lookup failed'));

        $listener = new ProcessEnrichmentEventListener(
            new EnrichmentReviewService($mockSubmission),
            app(CompanyContext::class),
        );

        try {
            $listener->handle(new EnrichmentWebhookReceived(
                $trackingId,
                'enriched',
                'full',
                true,
                'automotive',
            ));
            $this->fail('Expected fetch-and-store failure to bubble.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Platform lookup failed', $exception->getMessage());
        }

        $this->assertFalse(app(CompanyContext::class)->hasCompany());
        Event::assertNotDispatched(EnrichmentResultReadyEvent::class);
    }

    public function test_real_http_webhook_flow_creates_curated_update_after_tenant_accepts_initial_result(): void
    {
        Queue::fake();
        Event::fake([EnrichmentResultReadyEvent::class]);

        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-key']);

        $trackingId = (string) Str::uuid();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Webhook Product',
            'enrichment_status' => EnrichmentStatus::Enriching,
            'platform_submission_id' => $trackingId,
        ]);
        $reviewer = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Webhook Reviewer',
            'email' => 'webhook-reviewer@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        Http::fake([
            'platform.test/*' => Http::sequence()
                ->push([
                    'tracking_id' => $trackingId,
                    'status' => 'enriched',
                    'enrichment_quality' => 'full',
                    'assigned_barcode' => '3017620422003',
                    'enriched_data' => [
                        'name' => 'Initial Enriched Name',
                        'brand' => 'Initial Brand',
                        'confidence_score' => 88,
                    ],
                ])
                ->push([
                    'tracking_id' => $trackingId,
                    'status' => 'approved',
                    'enrichment_quality' => 'full',
                    'assigned_barcode' => '3017620422003',
                    'enriched_data' => [
                        'name' => 'Curated Enriched Name',
                        'brand' => 'Initial Brand',
                        'confidence_score' => 92,
                    ],
                ]),
        ]);

        $listener = app(ProcessEnrichmentEventListener::class);

        $listener->handle(new EnrichmentWebhookReceived(
            $trackingId,
            'enriched',
            'full',
            true,
            'automotive',
            'fr_FR',
        ));

        $initial = EnrichmentResult::where('tracking_id', $trackingId)->sole();

        app(EnrichmentReviewService::class)->accept($initial, ['name'], $reviewer->id);

        $listener->handle(new EnrichmentWebhookReceived(
            $trackingId,
            'approved',
            'full',
            true,
            'automotive',
            'fr_FR',
        ));

        $initial->refresh();
        $this->assertSame(EnrichmentReviewStatus::Accepted, $initial->status);
        $this->assertSame('Initial Enriched Name', $initial->enriched_data->name);

        $curated = EnrichmentResult::where('tracking_id', $trackingId)
            ->where('version', 2)
            ->sole();

        $this->assertSame(EnrichmentReviewStatus::PendingReview, $curated->status);
        $this->assertSame(EnrichmentResultOrigin::CuratedUpdate, $curated->origin);
        $this->assertSame('Curated Enriched Name', $curated->enriched_data->name);

        $product->refresh();
        $this->assertNull($product->enrichment_status);
        $this->assertNull($product->platform_submission_id);
    }

    private function enrichedData(string $name): EnrichedProductData
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
        );
    }
}
