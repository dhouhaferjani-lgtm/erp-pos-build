<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\PlatformIntegration\Application\DTOs\EnrichmentWebhookPayload;
use App\Modules\PlatformIntegration\Application\Jobs\ProcessEnrichmentWebhookJob;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use Illuminate\Support\Facades\Event;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

class ProcessEnrichmentWebhookJobTest extends TestCase
{
    public function test_handle_dispatches_event_with_locale(): void
    {
        Event::fake([EnrichmentWebhookReceived::class]);

        $payload = EnrichmentWebhookPayload::fromWebhook([
            'event' => 'enrichment.resolved',
            'tracking_id' => 'track-123',
            'status' => 'approved',
            'has_barcode_assigned' => true,
            'vertical' => 'parapharmacy',
            'locale' => 'fr_FR',
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
        // UNINITIALIZED (PHP unserialize does not apply constructor defaults).
        // handle() must not throw "must not be accessed before initialization".
        Event::fake([EnrichmentWebhookReceived::class]);

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
        ] as $prop => $value) {
            (new ReflectionProperty(EnrichmentWebhookPayload::class, $prop))->setValue($payload, $value);
        }
        // $locale deliberately left uninitialized.

        (new ProcessEnrichmentWebhookJob($payload))->handle();

        Event::assertDispatched(EnrichmentWebhookReceived::class, function (EnrichmentWebhookReceived $event): bool {
            return $event->trackingId === 'track-456' && $event->locale === null;
        });
    }
}
