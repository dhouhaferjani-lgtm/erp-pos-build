<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Application\Listeners\ProcessEnrichmentEventListener;
use App\Modules\Product\Application\Services\EnrichmentReviewService;
use App\Modules\Product\Domain\EnrichmentResult;
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
use Illuminate\Support\Str;
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
        $listener = new ProcessEnrichmentEventListener($reviewService);

        $webhookEvent = new EnrichmentWebhookReceived(
            $trackingId,
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
        $listener = new ProcessEnrichmentEventListener($reviewService);

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
        $listener = new ProcessEnrichmentEventListener($reviewService);

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
        $listener = new ProcessEnrichmentEventListener($reviewService);

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
        $listener = new ProcessEnrichmentEventListener($reviewService);

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
}
