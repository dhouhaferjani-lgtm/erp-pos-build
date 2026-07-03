<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Application\DTOs\ProductSubmissionData;
use App\Modules\PlatformIntegration\Application\DTOs\SubmissionResultData;
use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use App\Shared\DTOs\SubmissionStatusDTO;
use App\Shared\Enums\BrandMappingPushResult;
use App\Shared\Enums\EnrichmentFeedbackAction;
use App\Shared\Enums\EnrichmentFeedbackReason;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProductSubmissionServiceTest extends TestCase
{
    private ProductSubmissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-key']);
        Cache::flush();

        $this->service = new ProductSubmissionService(
            new PlatformHttpClient(new ProductSubmissionTestCompanyContext),
        );
    }

    public function test_submit_sends_idempotency_key_header(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([
                'tracking_id' => 'trk-sub-001',
                'status' => 'pending',
                'status_url' => 'https://platform.test/api/v1/products/status/trk-sub-001',
            ]),
        ]);

        $submission = new ProductSubmissionData(
            barcode: '3017620422003',
            vertical: 'automotive',
            name: 'Brake Pad Set',
            brand: 'Brembo',
            category: 'brake-pads',
            description: 'High-performance brake pads',
            attributes: ['material' => 'ceramic'],
            photoIds: ['photo-001', 'photo-002'],
            autoEnrich: true,
        );

        $result = $this->service->submit($submission);

        $this->assertInstanceOf(SubmissionResultData::class, $result);
        $this->assertSame('trk-sub-001', $result->trackingId);
        $this->assertSame('pending', $result->status);

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Idempotency-Key')
                && $request->header('Idempotency-Key')[0] !== ''
                && str_contains($request->url(), '/api/v1/products/submit')
                && $request->data()['barcode'] === '3017620422003'
                && $request->data()['auto_enrich'] === true;
        });
    }

    public function test_check_status_returns_submission_status_dto(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([
                'tracking_id' => 'trk-sub-001',
                'status' => 'enriched',
                'enrichment_quality' => 'full',
                'enriched_data' => [
                    'name' => 'Brake Pad Set',
                    'assigned_barcode' => '3017620422003',
                ],
            ]),
        ]);

        $result = $this->service->checkStatus('trk-sub-001');

        $this->assertNotNull($result);
        $this->assertInstanceOf(SubmissionStatusDTO::class, $result);
        $this->assertSame('trk-sub-001', $result->trackingId);
        $this->assertSame('enriched', $result->status);
        $this->assertSame('full', $result->enrichmentQuality);
        $this->assertSame('3017620422003', $result->assignedBarcode);
        $this->assertSame('Brake Pad Set', $result->enrichedData['name']);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/api/v1/products/lookup-status/trk-sub-001')
                && $request->method() === 'GET';
        });
    }

    public function test_check_status_raw_returns_array(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([
                'tracking_id' => 'trk-sub-001',
                'status' => 'enriched',
                'enrichment_quality' => 'full',
            ]),
        ]);

        $result = $this->service->checkStatusRaw('trk-sub-001');

        $this->assertNotNull($result);
        $this->assertSame('trk-sub-001', $result['tracking_id']);
        $this->assertSame('enriched', $result['status']);
    }

    public function test_send_feedback_posts_structured_payload(): void
    {
        Http::fake([
            'platform.test/*' => Http::response(['accepted' => true]),
        ]);

        $result = $this->service->sendFeedback(
            '00000000-0000-0000-0000-000000000123',
            EnrichmentFeedbackAction::Rejected,
            EnrichmentFeedbackReason::WrongProduct,
            'Different package',
        );

        $this->assertTrue($result);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/v1/products/lookup-status/00000000-0000-0000-0000-000000000123/feedback')
                && $request->data() === [
                    'action' => 'rejected',
                    'reason' => 'wrong_product',
                    'notes' => 'Different package',
                ];
        });
    }

    public function test_send_feedback_returns_false_on_non_success_response(): void
    {
        $logger = new CapturingProductSubmissionLog;
        Log::swap($logger);

        Http::fake([
            'platform.test/*' => Http::response(['message' => 'bad request'], 422),
        ]);

        $result = $this->service->sendFeedback(
            '00000000-0000-0000-0000-000000000123',
            EnrichmentFeedbackAction::Confirmed,
            null,
            null,
        );

        $this->assertFalse($result);
        $this->assertContains('Failed to send enrichment feedback to platform', array_column($logger->warnings, 'message'));
    }

    public function test_send_feedback_returns_false_on_platform_503(): void
    {
        $logger = new CapturingProductSubmissionLog;
        Log::swap($logger);

        Http::fake([
            'platform.test/*' => Http::response(['message' => 'service unavailable'], 503),
        ]);

        $result = $this->service->sendFeedback(
            '00000000-0000-0000-0000-000000000123',
            EnrichmentFeedbackAction::Confirmed,
            null,
            null,
        );

        $this->assertFalse($result);
        $this->assertContains('Failed to send enrichment feedback to platform', array_column($logger->warnings, 'message'));
    }

    public function test_send_feedback_returns_false_on_timeout(): void
    {
        $logger = new CapturingProductSubmissionLog;
        Log::swap($logger);

        Http::fake(function (): never {
            throw new ConnectionException('Connection timed out');
        });

        $result = $this->service->sendFeedback(
            '00000000-0000-0000-0000-000000000123',
            EnrichmentFeedbackAction::Confirmed,
            null,
            null,
        );

        $this->assertFalse($result);
        $this->assertContains('Failed to send enrichment feedback to platform', array_column($logger->warnings, 'message'));
    }

    public function test_send_feedback_returns_false_when_circuit_is_open(): void
    {
        $logger = new CapturingProductSubmissionLog;
        Log::swap($logger);
        Cache::put('platform:circuit_breaker', true, 30);

        $result = $this->service->sendFeedback(
            '00000000-0000-0000-0000-000000000123',
            EnrichmentFeedbackAction::Confirmed,
            null,
            null,
        );

        $this->assertFalse($result);
        $this->assertContains('Failed to send enrichment feedback to platform', array_column($logger->warnings, 'message'));
    }

    public function test_push_brand_mapping_returns_mapped_on_success(): void
    {
        Http::fake([
            'platform.test/api/v1/brands/*/external-mapping' => Http::response([
                'data' => ['brand_id' => 'c1', 'external_brand_id' => 'b1', 'partner_id' => 'p1'],
            ], 200),
        ]);

        $result = $this->service->pushBrandMapping('c1', 'b1');

        $this->assertSame(BrandMappingPushResult::Mapped, $result);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/brands/c1/external-mapping')
            && $request['external_brand_id'] === 'b1');
    }

    public function test_push_brand_mapping_returns_conflict_on_422(): void
    {
        Http::fake([
            'platform.test/*' => Http::response(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'already mapped']], 422),
        ]);

        $this->assertSame(BrandMappingPushResult::Conflict, $this->service->pushBrandMapping('c1', 'b1'));
    }

    public function test_push_brand_mapping_returns_not_found_on_404(): void
    {
        Http::fake([
            'platform.test/*' => Http::response(['error' => ['code' => 'RESOURCE_NOT_FOUND']], 404),
        ]);

        $this->assertSame(BrandMappingPushResult::NotFound, $this->service->pushBrandMapping('c1', 'b1'));
    }

    public function test_push_brand_mapping_returns_failed_on_server_error(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([], 503),
        ]);

        $this->assertSame(BrandMappingPushResult::Failed, $this->service->pushBrandMapping('c1', 'b1'));
    }

    public function test_push_brand_mapping_returns_failed_on_exception(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('unreachable'));

        $this->assertSame(BrandMappingPushResult::Failed, $this->service->pushBrandMapping('c1', 'b1'));
    }

    public function test_request_upload_url_returns_presigned_data(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([
                'upload_url' => 'https://storage.example.com/upload/presigned',
                'photo_id' => 'photo-abc-123',
                'expires_at' => '2026-03-27T11:00:00Z',
            ]),
        ]);

        $result = $this->service->requestUploadUrl('product.jpg', 'image/jpeg', 524288);

        $this->assertNotNull($result);
        $this->assertSame('https://storage.example.com/upload/presigned', $result['upload_url']);
        $this->assertSame('photo-abc-123', $result['photo_id']);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/api/v1/products/upload-url')
                && $request->data()['filename'] === 'product.jpg'
                && $request->data()['content_type'] === 'image/jpeg'
                && $request->data()['size_bytes'] === 524288;
        });
    }

    public function test_upload_photo_puts_file_contents_to_url(): void
    {
        Http::fake([
            'storage.example.com/*' => Http::response('', 200),
        ]);

        $fileContents = 'fake-image-binary-data';

        $this->service->uploadPhoto(
            'https://storage.example.com/upload/presigned',
            $fileContents,
            'image/jpeg',
        );

        Http::assertSent(function (Request $request) use ($fileContents) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), 'storage.example.com/upload/presigned')
                && $request->body() === $fileContents;
        });
    }

    public function test_get_category_attributes_returns_attribute_list(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([
                'vertical' => 'automotive',
                'category' => 'brake-pads',
                'attributes' => [
                    ['key' => 'material', 'label' => 'Material', 'type' => 'select', 'required' => true],
                    ['key' => 'thickness_mm', 'label' => 'Thickness (mm)', 'type' => 'number', 'required' => false],
                ],
            ]),
        ]);

        $result = $this->service->getCategoryAttributes('automotive', 'brake-pads');

        $this->assertNotNull($result);
        $this->assertSame('automotive', $result['vertical']);
        $this->assertSame('brake-pads', $result['category']);
        $this->assertCount(2, $result['attributes']);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/api/v1/verticals/automotive/categories/brake-pads/attributes')
                && $request->method() === 'GET';
        });
    }
}

final class ProductSubmissionTestCompanyContext extends CompanyContext
{
    public function requireTenantId(): string
    {
        return '00000000-0000-0000-0000-000000000001';
    }

    public function requireCompanyId(): string
    {
        return '00000000-0000-0000-0000-000000000002';
    }
}

final class CapturingProductSubmissionLog
{
    /**
     * @var list<array{message: string, context: array<string, mixed>}>
     */
    public array $warnings = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->warnings[] = [
            'message' => $message,
            'context' => $context,
        ];
    }
}
