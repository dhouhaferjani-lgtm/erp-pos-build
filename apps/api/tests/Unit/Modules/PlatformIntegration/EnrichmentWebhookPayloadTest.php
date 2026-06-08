<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\PlatformIntegration\Application\DTOs\EnrichmentWebhookPayload;
use PHPUnit\Framework\TestCase;

class EnrichmentWebhookPayloadTest extends TestCase
{
    public function test_from_webhook_parses_top_level_locale(): void
    {
        $payload = EnrichmentWebhookPayload::fromWebhook([
            'event' => 'enrichment.resolved',
            'tracking_id' => 'track-123',
            'barcode' => '3017620422003',
            'status' => 'approved',
            'enrichment_quality' => 'full',
            'has_barcode_assigned' => true,
            'vertical' => 'parapharmacy',
            'locale' => 'fr_FR',
            'timestamp' => '2026-06-04T10:00:00+00:00',
        ]);

        $this->assertSame('fr_FR', $payload->locale);
    }

    public function test_from_webhook_locale_is_null_when_absent(): void
    {
        $payload = EnrichmentWebhookPayload::fromWebhook([
            'event' => 'enrichment.resolved',
            'tracking_id' => 'track-123',
            'status' => 'approved',
            'has_barcode_assigned' => false,
            'vertical' => 'parapharmacy',
        ]);

        $this->assertNull($payload->locale);
    }
}
