<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Application\Listeners\ProcessEnrichmentEventListener;
use App\Modules\Product\Application\Services\EnrichmentReviewService;
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

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'enrichment_status' => EnrichmentStatus::Enriching,
            'platform_submission_id' => 'trk-flow-001',
        ]);

        // Mock the PlatformSubmissionInterface to return enrichment data
        $mockSubmission = $this->createMock(PlatformSubmissionInterface::class);
        $mockSubmission->method('checkStatus')->willReturn(new SubmissionStatusDTO(
            trackingId: 'trk-flow-001',
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
        $listener = new ProcessEnrichmentEventListener($reviewService);

        $webhookEvent = new EnrichmentWebhookReceived(
            'trk-flow-001',
            'enriched',
            'full',
            true,
            'automotive',
        );

        $listener->handle($webhookEvent);

        // Product status should be updated
        $product->refresh();
        $this->assertSame(EnrichmentStatus::Completed, $product->enrichment_status);

        // Enrichment result should be stored
        $this->assertDatabaseHas('enrichment_results', [
            'tracking_id' => 'trk-flow-001',
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

    public function test_listener_does_not_dispatch_event_for_failed_enrichments(): void
    {
        Event::fake([EnrichmentResultReadyEvent::class]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Failed Product',
            'enrichment_status' => EnrichmentStatus::Enriching,
            'platform_submission_id' => 'trk-fail-001',
        ]);

        $mockSubmission = $this->createMock(PlatformSubmissionInterface::class);
        $mockSubmission->method('checkStatus')->willReturn(new SubmissionStatusDTO(
            trackingId: 'trk-fail-001',
            status: 'failed',
            enrichmentQuality: null,
            enrichedData: [],
            assignedBarcode: null,
            vertical: 'automotive',
        ));

        $reviewService = new EnrichmentReviewService($mockSubmission);
        $listener = new ProcessEnrichmentEventListener($reviewService);

        $webhookEvent = new EnrichmentWebhookReceived(
            'trk-fail-001',
            'failed',
            null,
            false,
            'automotive',
        );

        $listener->handle($webhookEvent);

        $product->refresh();
        $this->assertSame(EnrichmentStatus::Failed, $product->enrichment_status);

        // Failed enrichment should NOT trigger notification event
        Event::assertNotDispatched(EnrichmentResultReadyEvent::class);
    }

    public function test_listener_does_not_dispatch_event_for_non_terminal_status(): void
    {
        Event::fake([EnrichmentResultReadyEvent::class]);

        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'In Progress Product',
            'enrichment_status' => EnrichmentStatus::Pending,
            'platform_submission_id' => 'trk-progress-001',
        ]);

        $mockSubmission = $this->createMock(PlatformSubmissionInterface::class);
        // checkStatus should not be called for non-terminal status
        $mockSubmission->expects($this->never())->method('checkStatus');

        $reviewService = new EnrichmentReviewService($mockSubmission);
        $listener = new ProcessEnrichmentEventListener($reviewService);

        $webhookEvent = new EnrichmentWebhookReceived(
            'trk-progress-001',
            'enriching', // Non-terminal
            null,
            false,
            'automotive',
        );

        $listener->handle($webhookEvent);

        Event::assertNotDispatched(EnrichmentResultReadyEvent::class);
    }
}
