<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformIntegration;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\PlatformIntegration\Application\DTOs\EnrichmentWebhookPayload;
use App\Modules\PlatformIntegration\Application\Jobs\ProcessEnrichmentWebhookJob;
use App\Modules\Product\Application\Listeners\ProcessEnrichmentEventListener;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\LegacyMockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Tenant context for the unauthenticated Synerivia enrichment webhook.
 *
 * `POST api/v1/webhooks/syneriva` runs `['api', VerifySynerivaWebhookSignature]`
 * — no `auth:sanctum`, no session, no signed link — so `ResolveTenancy` resolves
 * nothing and the request stays on the CENTRAL connection. Under
 * database-per-tenant that means `QueueTenancyBootstrapper` stamps no tenant on
 * the dispatched payload and the worker runs central too, where the listener's
 * `Product::where('platform_submission_id', …)->sole()` raises a 42P01
 * `QueryException` that its `catch (ModelNotFoundException)` does not catch.
 *
 * The old annotation claimed tenant resolution "chains through
 * platform_submission_id → Product → company_id". That chain's FIRST hop is
 * itself the tenant-table read that fails, so it cannot resolve anything. The
 * anchor has to travel with the payload.
 */
final class EnrichmentWebhookTenantContextTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_the_payload_dto_reads_the_tenant_anchor_from_the_webhook_body(): void
    {
        $tenantId = (string) Str::uuid();

        $payload = EnrichmentWebhookPayload::fromWebhook([
            'event' => 'enrichment.resolved',
            'tracking_id' => 'trk-1',
            'status' => 'completed',
            'vertical' => 'parapharmacy',
            'tenant_id' => $tenantId,
        ]);

        $this->assertSame($tenantId, $payload->tenantId);
    }

    /**
     * Tolerant by design: the platform does not echo `tenant_id` back yet (see
     * docs/superpowers/tickets/2026-08-05-enrichment-webhook-platform-contract.md),
     * so an absent key must map to null rather than blow up the webhook.
     */
    public function test_a_webhook_body_without_a_tenant_anchor_maps_to_null(): void
    {
        $payload = EnrichmentWebhookPayload::fromWebhook([
            'event' => 'enrichment.resolved',
            'tracking_id' => 'trk-2',
            'status' => 'completed',
            'vertical' => 'parapharmacy',
        ]);

        $this->assertNull($payload->tenantId);
    }

    public function test_the_controller_forwards_the_tenant_anchor_onto_the_queued_job(): void
    {
        Bus::fake([ProcessEnrichmentWebhookJob::class]);

        $tenantId = (string) Str::uuid();

        $this->postWebhook([
            'event' => 'enrichment.resolved',
            'tracking_id' => 'trk-3',
            'status' => 'completed',
            'vertical' => 'parapharmacy',
            'tenant_id' => $tenantId,
        ])->assertOk();

        Bus::assertDispatched(
            ProcessEnrichmentWebhookJob::class,
            static fn (ProcessEnrichmentWebhookJob $job): bool => $job->tenantId === $tenantId
                && $job->payload->tenantId === $tenantId,
        );
    }

    public function test_the_job_binds_the_anchored_tenant_before_the_listener_runs(): void
    {
        $tenant = $this->createTenant('enrichment-webhook-bind');

        $seen = [];
        Event::listen(EnrichmentWebhookReceived::class, function (EnrichmentWebhookReceived $event) use (&$seen): void {
            $seen[] = [
                'tracking_id' => $event->trackingId,
                'tenancy_initialized' => tenancy()->initialized,
                'bound_tenant_id' => tenant('id'),
            ];
        });

        (new ProcessEnrichmentWebhookJob($this->payload('trk-bind', $tenant->id)))->handle();

        $this->assertSame(
            [[
                'tracking_id' => 'trk-bind',
                'tenancy_initialized' => true,
                'bound_tenant_id' => $tenant->id,
            ]],
            $seen,
            'The listener resolves a Product, so it must run inside the anchored tenant — not the worker\'s central context.',
        );
        $this->assertFalse(tenancy()->initialized, 'The job must revert the worker to central context when it finishes.');
    }

    public function test_the_job_fails_loud_when_the_anchored_tenant_is_gone(): void
    {
        $missingTenantId = (string) Str::uuid();

        Event::fake([EnrichmentWebhookReceived::class]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($missingTenantId, '/').'/');

        try {
            (new ProcessEnrichmentWebhookJob($this->payload('trk-gone', $missingTenantId)))->handle();
        } finally {
            Event::assertNotDispatched(EnrichmentWebhookReceived::class);
        }
    }

    /**
     * No anchor => DISCARD, never process under central. Safe because the
     * webhook is an optimisation: `enrichment:check-pending` re-polls every
     * product still Pending/Enriching every 15 minutes, inside the owning
     * tenant's own database, and dispatches the same event.
     */
    public function test_a_payload_without_an_anchor_is_discarded_with_a_warning(): void
    {
        Event::fake([EnrichmentWebhookReceived::class]);

        $logSpy = Log::spy();

        (new ProcessEnrichmentWebhookJob($this->payload('trk-anchorless', null)))->handle();

        Event::assertNotDispatched(EnrichmentWebhookReceived::class);
        $this->assertFalse(tenancy()->initialized);

        $this->assertInstanceOf(LegacyMockInterface::class, $logSpy);
        $logSpy->shouldHaveReceived('warning', [
            Mockery::type('string'),
            Mockery::on(static fn (array $context): bool => ($context['tracking_id'] ?? null) === 'trk-anchorless'),
        ]);
    }

    /**
     * The catch gap the audit flagged: `Product::where(…)->sole()` on the
     * CENTRAL connection raises a `QueryException` (42P01), which the
     * listener's `catch (ModelNotFoundException)` does not catch — so the job
     * died naming a missing relation instead of the real fault.
     *
     * The fix is a fail-closed guard, NOT a widened catch: a query fault is an
     * infra fault that must fail loud and be retried, never be reinterpreted as
     * "unknown tracking id".
     */
    public function test_the_listener_refuses_to_run_with_no_tenant_bound_under_db_per_tenant(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $this->assertFalse(tenancy()->initialized);

        $listener = app(ProcessEnrichmentEventListener::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no tenant is bound/');

        $listener->handle(new EnrichmentWebhookReceived(
            trackingId: 'trk-unbound',
            status: 'completed',
            enrichmentQuality: 'high',
            hasBarcodeAssigned: false,
            vertical: 'parapharmacy',
        ));
    }

    /**
     * A queue payload serialized BEFORE the anchor existed carries no
     * `tenantId` key at all, and `SerializesModels::__unserialize()` skips
     * absent keys. A promoted `readonly string` would stay uninitialized and
     * fatal on first read, making those `failed_jobs` rows permanently
     * un-retryable — hence the declared, defaulted property.
     *
     * `__serialize()` omits default-valued properties, so resetting the anchor
     * to its default reproduces a legacy payload byte-for-byte.
     */
    public function test_a_legacy_payload_without_the_anchor_restores_as_null_instead_of_fatalling(): void
    {
        $job = new ProcessEnrichmentWebhookJob($this->payload('trk-legacy', null));
        $job->tenantId = null;

        $serialized = serialize($job);
        $this->assertMatchesRegularExpression(
            '/^O:\d+:"[^"]*ProcessEnrichmentWebhookJob":1:\{s:7:"payload";/',
            $serialized,
            'The reproduction is only faithful if the JOB\'s serialized property set carries `payload` and nothing else — i.e. no `tenantId` key at all.',
        );

        $restored = unserialize($serialized);
        $this->assertInstanceOf(ProcessEnrichmentWebhookJob::class, $restored);
        $this->assertNull($restored->tenantId, 'An absent key must restore as null, NOT as an uninitialized typed property.');

        // Same guarantee one level down: a DTO serialized before the property
        // existed carries no key either, and must restore to the declared
        // default rather than leaving a typed property uninitialized.
        $legacyDto = unserialize(sprintf(
            'O:%d:"%s":0:{}',
            strlen(EnrichmentWebhookPayload::class),
            EnrichmentWebhookPayload::class,
        ));
        $this->assertInstanceOf(EnrichmentWebhookPayload::class, $legacyDto);
        $this->assertNull($legacyDto->tenantId);

        Event::fake([EnrichmentWebhookReceived::class]);
        $restored->handle();
        Event::assertNotDispatched(EnrichmentWebhookReceived::class);
    }

    private function payload(string $trackingId, ?string $tenantId): EnrichmentWebhookPayload
    {
        return new EnrichmentWebhookPayload(
            event: 'enrichment.resolved',
            trackingId: $trackingId,
            barcode: null,
            status: 'completed',
            enrichmentQuality: 'high',
            hasBarcodeAssigned: false,
            vertical: 'parapharmacy',
            timestamp: now()->toIso8601String(),
            locale: null,
            tenantId: $tenantId,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function postWebhook(array $body): TestResponse
    {
        config(['services.platform.webhook_secret' => 'test-secret']);

        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        return $this->call(
            'POST',
            '/api/v1/webhooks/syneriva',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SYNERIVA_TIMESTAMP' => $timestamp,
                'HTTP_X_SYNERIVA_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$payload, 'test-secret'),
            ],
            $payload,
        );
    }

    private function createTenant(string $slug): Tenant
    {
        $tenant = Tenant::create([
            'name' => str_replace('-', ' ', ucfirst($slug)),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Enrichment Company',
            'legal_name' => 'Enrichment Company LLC',
            'tax_id' => 'TAX-ENR-'.Str::upper(Str::random(6)),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        return $tenant;
    }
}
