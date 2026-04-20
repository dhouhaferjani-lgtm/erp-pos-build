<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\PlatformIntegration\Application\DTOs\ProductSubmissionData;
use App\Modules\PlatformIntegration\Application\DTOs\SubmissionResultData;
use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use App\Shared\DTOs\SubmissionStatusDTO;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
            new PlatformHttpClient,
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
        $this->assertIsArray($result);
        $this->assertSame('trk-sub-001', $result['tracking_id']);
        $this->assertSame('enriched', $result['status']);
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
