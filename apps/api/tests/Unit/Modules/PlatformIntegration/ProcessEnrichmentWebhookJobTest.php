<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\PlatformIntegration\Application\DTOs\EnrichmentWebhookPayload;
use App\Modules\PlatformIntegration\Application\Jobs\ProcessEnrichmentWebhookJob;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Locale-tolerance guards for the webhook re-dispatcher.
 *
 * Both cases now supply a tenant anchor: since 2026-08-05 an anchorless payload
 * is DISCARDED rather than processed under central context (see the class
 * docblock on ProcessEnrichmentWebhookJob). The anchor is orthogonal to what
 * these two tests exist to pin, so it is just fixture setup here — the anchor
 * contract itself is covered by
 * tests/Feature/PlatformIntegration/EnrichmentWebhookTenantContextTest.php.
 */
class ProcessEnrichmentWebhookJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_handle_dispatches_event_with_locale(): void
    {
        Event::fake([EnrichmentWebhookReceived::class]);

        $tenant = $this->createTenant('enrichment-locale');

        $payload = EnrichmentWebhookPayload::fromWebhook([
            'event' => 'enrichment.resolved',
            'tracking_id' => 'track-123',
            'status' => 'approved',
            'has_barcode_assigned' => true,
            'vertical' => 'parapharmacy',
            'locale' => 'fr_FR',
            'tenant_id' => $tenant->id,
        ]);

        (new ProcessEnrichmentWebhookJob($payload))->handle();

        Event::assertDispatched(EnrichmentWebhookReceived::class, function (EnrichmentWebhookReceived $event): bool {
            return $event->trackingId === 'track-123' && $event->locale === 'fr_FR';
        });
    }

    public function test_handle_tolerates_in_flight_payload_missing_locale(): void
    {
        // Rolling-deploy guard: a ProcessEnrichmentWebhookJob enqueued before
        // the `locale` property existed deserializes into an
        // EnrichmentWebhookPayload whose typed `$locale` property is
        // UNINITIALIZED (PHP unserialize does not apply constructor defaults —
        // which is exactly why `$tenantId` is a DECLARED property with a
        // class-level default rather than a promoted one).
        // handle() must not throw "must not be accessed before initialization".
        Event::fake([EnrichmentWebhookReceived::class]);

        $tenant = $this->createTenant('enrichment-locale-missing');

        $reflection = new ReflectionClass(EnrichmentWebhookPayload::class);
        /** @var EnrichmentWebhookPayload $payload */
        $payload = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'event' => 'enrichment.resolved',
            'trackingId' => 'track-456',
            'barcode' => null,
            'status' => 'approved',
            'enrichmentQuality' => 'full',
            'hasBarcodeAssigned' => true,
            'vertical' => 'parapharmacy',
            'timestamp' => '2026-06-04T10:00:00+00:00',
            'tenantId' => $tenant->id,
        ] as $prop => $value) {
            (new ReflectionProperty(EnrichmentWebhookPayload::class, $prop))->setValue($payload, $value);
        }
        // $locale deliberately left uninitialized.

        (new ProcessEnrichmentWebhookJob($payload))->handle();

        Event::assertDispatched(EnrichmentWebhookReceived::class, function (EnrichmentWebhookReceived $event): bool {
            return $event->trackingId === 'track-456' && $event->locale === null;
        });
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => str_replace('-', ' ', ucfirst($slug)),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }
}
