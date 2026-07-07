<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentIngestion;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData;
use App\Modules\DocumentIngestion\Application\Jobs\ExtractDocumentJob;
use App\Modules\DocumentIngestion\Application\Services\ExtractionReconciler;
use App\Modules\DocumentIngestion\Application\Services\MatchSuggestionService;
use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Modules\DocumentIngestion\Domain\Enums\IngestionStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\ExtractionClientInterface;
use App\Shared\Contracts\ExtractionFailedException;
use App\Shared\Contracts\ExtractionHints;
use App\Shared\Contracts\ExtractionResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExtractDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_is_dispatched_on_ingestion_queue(): void
    {
        Queue::fake();

        ExtractDocumentJob::dispatch((string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid());

        Queue::assertPushedOn('ingestion', ExtractDocumentJob::class);
    }

    public function test_happy_path_persists_extraction_and_moves_to_needs_review(): void
    {
        Storage::fake('s3');
        $context = $this->makeIngestion();

        $this->app->instance(
            ExtractionClientInterface::class,
            new FakeExtractionClient(
                ExtractionResultData::from($this->fixture()),
                provider: 'claude',
                model: 'claude-haiku',
            )
        );

        (new ExtractDocumentJob($context['tenant']->id, $context['company']->id, $context['ingestion']->id))
            ->handle(
                $this->app->make(ExtractionClientInterface::class),
                $this->app->make(ExtractionReconciler::class),
                $this->app->make(MatchSuggestionService::class),
            );

        $fresh = $context['ingestion']->refresh();

        $this->assertSame(IngestionStatus::NeedsReview, $fresh->status);
        $this->assertSame($this->fixture(), $fresh->extraction);
        $this->assertSame('claude', $fresh->provider);
        $this->assertSame('claude-haiku', $fresh->provider_model);
        $confidenceSummary = $fresh->confidence_summary;
        $suggestions = $fresh->suggestions;
        $this->assertIsArray($confidenceSummary);
        $this->assertIsArray($suggestions);
        $reconciliation = $confidenceSummary['reconciliation'];
        $this->assertIsArray($reconciliation);
        $this->assertSame([], $reconciliation['flags']);
        $this->assertSame([], $suggestions['supplier_candidates']);
        $this->assertSame([], $suggestions['receipt_line_candidates']);
    }

    public function test_delivery_note_extraction_uses_company_currency_without_company_context(): void
    {
        Storage::fake('s3');
        $context = $this->makeIngestion(DocumentKind::SupplierDeliveryNote);
        app(CompanyContext::class)->clear();

        $this->app->instance(
            ExtractionClientInterface::class,
            new FakeExtractionClient(ExtractionResultData::from($this->fixture('extraction_bl_fr.json')))
        );

        (new ExtractDocumentJob($context['tenant']->id, $context['company']->id, $context['ingestion']->id))
            ->handle(
                $this->app->make(ExtractionClientInterface::class),
                $this->app->make(ExtractionReconciler::class),
                $this->app->make(MatchSuggestionService::class),
            );

        $fresh = $context['ingestion']->refresh();

        $this->assertSame(IngestionStatus::NeedsReview, $fresh->status);
        $confidenceSummary = $fresh->confidence_summary;
        $this->assertIsArray($confidenceSummary);
        $reconciliation = $confidenceSummary['reconciliation'];
        $this->assertIsArray($reconciliation);
        $this->assertSame([], $reconciliation['flags']);
        $this->assertNull($fresh->error);
    }

    public function test_unparseable_extracted_numbers_are_flagged_without_failing_the_document(): void
    {
        Storage::fake('s3');
        $context = $this->makeIngestion(DocumentKind::SupplierDeliveryNote);
        $payload = $this->fixture('extraction_bl_fr.json');

        $payload['lines'][0]['unit_price'] = ['value' => 'N/A', 'confidence' => 0.82];
        $payload['lines'][0]['line_total'] = ['value' => '10.000', 'confidence' => 0.82];
        $payload['lines'][] = $payload['lines'][0];
        $payload['lines'][1]['quantity']['value'] = '1.0000';
        $payload['lines'][1]['unit_price'] = ['value' => '1 234,56', 'confidence' => 0.88];
        $payload['lines'][1]['line_total'] = ['value' => '1234.560', 'confidence' => 0.88];

        $this->app->instance(
            ExtractionClientInterface::class,
            new FakeExtractionClient(ExtractionResultData::from($payload))
        );

        (new ExtractDocumentJob($context['tenant']->id, $context['company']->id, $context['ingestion']->id))
            ->handle(
                $this->app->make(ExtractionClientInterface::class),
                $this->app->make(ExtractionReconciler::class),
                $this->app->make(MatchSuggestionService::class),
            );

        $fresh = $context['ingestion']->refresh();
        $confidenceSummary = $fresh->confidence_summary;
        $this->assertIsArray($confidenceSummary);
        $reconciliation = $confidenceSummary['reconciliation'];
        $this->assertIsArray($reconciliation);
        $flags = $reconciliation['flags'];
        $this->assertIsArray($flags);

        $this->assertSame(IngestionStatus::NeedsReview, $fresh->status);
        $this->assertContains('field_unparseable:lines.0.unit_price', $flags);
        $this->assertNotContains('line_2_total_mismatch', $flags);
        $this->assertNull($fresh->error);
    }

    public function test_client_failure_marks_ingestion_failed_with_structured_error_and_rethrows(): void
    {
        Storage::fake('s3');
        $context = $this->makeIngestion();

        $this->app->instance(
            ExtractionClientInterface::class,
            new FakeExtractionClient(null, new ExtractionFailedException('upstream unavailable', 'UPSTREAM_503'))
        );

        try {
            (new ExtractDocumentJob($context['tenant']->id, $context['company']->id, $context['ingestion']->id))
                ->handle(
                    $this->app->make(ExtractionClientInterface::class),
                    $this->app->make(ExtractionReconciler::class),
                    $this->app->make(MatchSuggestionService::class),
                );
            $this->fail('Expected extraction failure to be rethrown.');
        } catch (ExtractionFailedException) {
            $fresh = $context['ingestion']->refresh();

            $this->assertSame(IngestionStatus::Failed, $fresh->status);
            $this->assertSame([
                'code' => 'UPSTREAM_503',
                'message' => 'upstream unavailable',
            ], $fresh->error);
        }
    }

    /**
     * @return array{tenant: Tenant, company: Company, ingestion: DocumentIngestion}
     */
    private function makeIngestion(DocumentKind $kind = DocumentKind::SupplierInvoice): array
    {
        $tenant = Tenant::create([
            'name' => 'Extraction Tenant',
            'slug' => 'extraction-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Extraction Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Extractor',
            'email' => 'extractor-'.Str::random(8).'@test.example',
            'password' => bcrypt('secret'),
            'status' => UserStatus::Active,
        ]);

        $path = 'ingestions/'.$tenant->id.'/source/original.pdf';
        Storage::disk('s3')->put($path, '%PDF-1.4 test');

        $asset = MediaAsset::create([
            'tenant_id' => $tenant->id,
            'type' => MediaAssetType::Document,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 13,
            'checksum' => hash('sha256', 'source'),
            'uploaded_by' => $user->id,
        ]);

        $ingestion = DocumentIngestion::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'kind' => $kind,
            'status' => IngestionStatus::Uploaded,
            'media_asset_id' => $asset->id,
            'checksum' => hash('sha256', 'source'),
            'created_by' => $user->id,
        ]);

        return ['tenant' => $tenant, 'company' => $company, 'ingestion' => $ingestion];
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name = 'extraction_invoice_fr.json'): array
    {
        $json = file_get_contents(base_path("tests/Fixtures/document_ingestion/{$name}"));
        $this->assertIsString($json);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

final class FakeExtractionClient implements ExtractionClientInterface
{
    public function __construct(
        private readonly ?ExtractionResultData $result,
        private readonly ?ExtractionFailedException $exception = null,
        private readonly string $provider = 'erp_ml',
        private readonly ?string $model = null,
    ) {}

    public function extract(
        string $fileContents,
        string $mimeType,
        DocumentKind $kind,
        ExtractionHints $hints,
    ): ExtractionResponse {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        if ($this->result === null) {
            throw new ExtractionFailedException('missing fake result');
        }

        return new ExtractionResponse($this->result, $this->provider, $this->model);
    }
}
