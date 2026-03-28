<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\PlatformIntegration;

use App\Modules\PlatformIntegration\Application\Jobs\ProcessEnrichmentWebhookJob;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class EnrichmentWebhookControllerTest extends TestCase
{
    private string $secret = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.platform.webhook_secret' => $this->secret]);
    }

    public function test_valid_webhook_dispatches_job(): void
    {
        Bus::fake([ProcessEnrichmentWebhookJob::class]);

        $body = json_encode([
            'event' => 'enrichment.completed',
            'tracking_id' => 'trk-abc-123',
            'barcode' => '3017620422003',
            'status' => 'enriched',
            'enrichment_quality' => 'full',
            'has_barcode_assigned' => true,
            'vertical' => 'automotive',
            'timestamp' => '2026-03-27T10:00:00Z',
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secret);

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/syneriva',
            server: [
                'HTTP_X_SYNERIVA_SIGNATURE' => $signature,
                'HTTP_X_SYNERIVA_TIMESTAMP' => $timestamp,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body,
        );

        $response->assertOk();
        $response->assertJson(['received' => true]);

        Bus::assertDispatched(ProcessEnrichmentWebhookJob::class, function (ProcessEnrichmentWebhookJob $job) {
            return $job->payload->trackingId === 'trk-abc-123'
                && $job->payload->status === 'enriched'
                && $job->payload->vertical === 'automotive';
        });
    }

    public function test_invalid_signature_returns_403(): void
    {
        Bus::fake([ProcessEnrichmentWebhookJob::class]);

        $body = json_encode([
            'event' => 'enrichment.completed',
            'tracking_id' => 'trk-abc-123',
            'status' => 'enriched',
            'vertical' => 'automotive',
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) time();

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/syneriva',
            server: [
                'HTTP_X_SYNERIVA_SIGNATURE' => 'sha256=invalidsignature',
                'HTTP_X_SYNERIVA_TIMESTAMP' => $timestamp,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body,
        );

        $response->assertStatus(403);

        Bus::assertNotDispatched(ProcessEnrichmentWebhookJob::class);
    }

    public function test_batch_webhook_dispatches_multiple_jobs(): void
    {
        Bus::fake([ProcessEnrichmentWebhookJob::class]);

        $body = json_encode([
            'event' => 'enrichment.batch_resolved',
            'items' => [
                [
                    'tracking_id' => 'trk-001',
                    'barcode' => '3017620422003',
                    'status' => 'enriched',
                    'enrichment_quality' => 'full',
                    'has_barcode_assigned' => true,
                    'vertical' => 'automotive',
                    'timestamp' => '2026-03-27T10:00:00Z',
                ],
                [
                    'tracking_id' => 'trk-002',
                    'barcode' => '5449000000996',
                    'status' => 'enriched',
                    'enrichment_quality' => 'partial',
                    'has_barcode_assigned' => true,
                    'vertical' => 'automotive',
                    'timestamp' => '2026-03-27T10:00:01Z',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secret);

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/syneriva',
            server: [
                'HTTP_X_SYNERIVA_SIGNATURE' => $signature,
                'HTTP_X_SYNERIVA_TIMESTAMP' => $timestamp,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body,
        );

        $response->assertOk();
        $response->assertJson(['received' => true]);

        Bus::assertDispatched(ProcessEnrichmentWebhookJob::class, 2);

        Bus::assertDispatched(ProcessEnrichmentWebhookJob::class, function (ProcessEnrichmentWebhookJob $job) {
            return $job->payload->trackingId === 'trk-001';
        });

        Bus::assertDispatched(ProcessEnrichmentWebhookJob::class, function (ProcessEnrichmentWebhookJob $job) {
            return $job->payload->trackingId === 'trk-002';
        });
    }
}
