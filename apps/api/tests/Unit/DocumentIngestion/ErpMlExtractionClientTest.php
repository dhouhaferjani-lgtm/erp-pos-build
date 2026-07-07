<?php

declare(strict_types=1);

namespace Tests\Unit\DocumentIngestion;

use App\Modules\DocumentIngestion\Application\Contracts\ExtractionFailedException;
use App\Modules\DocumentIngestion\Application\Contracts\ExtractionHints;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Modules\DocumentIngestion\Infrastructure\ErpMlExtractionClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ErpMlExtractionClientTest extends TestCase
{
    public function test_successful_response_returns_extraction_result_data(): void
    {
        config()->set('services.erp_ml.url', 'http://erp-ml.test');
        config()->set('services.erp_ml.service_token', 'secret-token');

        Http::fake([
            'erp-ml.test/api/v1/extract' => Http::response([
                'provider' => 'claude',
                'model' => 'claude-haiku',
                'result' => $this->fixture(),
            ], 200),
        ]);

        $response = (new ErpMlExtractionClient)->extract(
            '%PDF bytes',
            'application/pdf',
            DocumentKind::SupplierInvoice,
            new ExtractionHints(languageHint: 'fr', currencyHint: 'TND'),
        );

        $this->assertSame('supplier_invoice', $response->result->docKind);
        $this->assertCount(3, $response->result->lines);
        $this->assertSame('claude', $response->provider);
        $this->assertSame('claude-haiku', $response->model);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://erp-ml.test/api/v1/extract'
                && $request->hasHeader('X-Service-Token', 'secret-token');
        });
    }

    public function test_malformed_success_response_throws_extraction_failed_exception(): void
    {
        config()->set('services.erp_ml.url', 'http://erp-ml.test');

        Http::fake(['erp-ml.test/api/v1/extract' => Http::response(['result' => ['bad' => true]], 200)]);

        $this->expectException(ExtractionFailedException::class);

        (new ErpMlExtractionClient)->extract('bytes', 'application/pdf', DocumentKind::SupplierInvoice, new ExtractionHints);
    }

    public function test_server_error_throws_extraction_failed_exception(): void
    {
        config()->set('services.erp_ml.url', 'http://erp-ml.test');

        Http::fake(['erp-ml.test/api/v1/extract' => Http::response(['error' => 'down'], 500)]);

        $this->expectException(ExtractionFailedException::class);

        (new ErpMlExtractionClient)->extract('bytes', 'application/pdf', DocumentKind::SupplierInvoice, new ExtractionHints);
    }

    public function test_unauthorized_response_throws_extraction_failed_exception(): void
    {
        config()->set('services.erp_ml.url', 'http://erp-ml.test');

        Http::fake(['erp-ml.test/api/v1/extract' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->expectException(ExtractionFailedException::class);

        (new ErpMlExtractionClient)->extract('bytes', 'application/pdf', DocumentKind::SupplierInvoice, new ExtractionHints);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $json = file_get_contents(base_path('tests/Fixtures/document_ingestion/extraction_invoice_fr.json'));
        $this->assertIsString($json);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
