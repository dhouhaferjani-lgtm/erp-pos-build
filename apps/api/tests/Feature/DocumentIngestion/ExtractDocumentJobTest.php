<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentIngestion;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
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
            new FakeExtractionClient(ExtractionResultData::from($this->fixture()))
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
        $this->assertSame('erp_ml', $fresh->provider);
        $this->assertSame([], $fresh->confidence_summary['reconciliation']['flags']);
        $this->assertSame([], $fresh->suggestions['supplier_candidates']);
        $this->assertSame([], $fresh->suggestions['receipt_line_candidates']);
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
    private function makeIngestion(): array
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
            'kind' => DocumentKind::SupplierInvoice,
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
    private function fixture(): array
    {
        $json = file_get_contents(base_path('tests/Fixtures/document_ingestion/extraction_invoice_fr.json'));
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
    ) {}

    public function extract(
        string $fileContents,
        string $mimeType,
        DocumentKind $kind,
        ExtractionHints $hints,
    ): ExtractionResultData {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        if ($this->result === null) {
            throw new ExtractionFailedException('missing fake result');
        }

        return $this->result;
    }
}
