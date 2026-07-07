<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentIngestion;

use App\Modules\Company\Domain\Company;
use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Modules\DocumentIngestion\Domain\Enums\IngestionStatus;
use App\Modules\DocumentIngestion\Domain\Exceptions\InvalidIngestionTransition;
use App\Modules\Identity\Domain\User;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IngestionStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_machine_allows_only_declared_transitions_and_persists_status(): void
    {
        $ingestion = $this->makeIngestion();

        $ingestion->transitionTo(IngestionStatus::Extracting);
        $this->assertSame(IngestionStatus::Extracting, $ingestion->refresh()->status);

        $ingestion->transitionTo(IngestionStatus::Extracting);
        $this->assertSame(IngestionStatus::Extracting, $ingestion->refresh()->status);

        $ingestion->transitionTo(IngestionStatus::NeedsReview);
        $this->assertSame(IngestionStatus::NeedsReview, $ingestion->refresh()->status);

        $ingestion->transitionTo(IngestionStatus::Committing);
        $this->assertSame(IngestionStatus::Committing, $ingestion->refresh()->status);

        $ingestion->transitionTo(IngestionStatus::Committed);
        $this->assertSame(IngestionStatus::Committed, $ingestion->refresh()->status);
    }

    public function test_uploaded_cannot_transition_directly_to_committed(): void
    {
        $ingestion = $this->makeIngestion();

        $this->expectException(InvalidIngestionTransition::class);

        $ingestion->transitionTo(IngestionStatus::Committed);
    }

    public function test_failed_ingestion_can_be_retried(): void
    {
        $ingestion = $this->makeIngestion();

        $ingestion->transitionTo(IngestionStatus::Extracting);
        $ingestion->transitionTo(IngestionStatus::Failed);
        $ingestion->transitionTo(IngestionStatus::Extracting);

        $this->assertSame(IngestionStatus::Extracting, $ingestion->refresh()->status);
    }

    public function test_partial_unique_index_ignores_rejected_and_failed_rows_only(): void
    {
        $base = $this->makeIngestion(checksum: str_repeat('a', 64));
        $base->transitionTo(IngestionStatus::Extracting);
        $base->transitionTo(IngestionStatus::NeedsReview);
        $base->transitionTo(IngestionStatus::Rejected);

        $this->makeIngestion(company: $base->company, checksum: $base->checksum);

        $blocking = $this->makeIngestion(company: $base->company, checksum: str_repeat('b', 64));
        $blocking->transitionTo(IngestionStatus::Extracting);
        $blocking->transitionTo(IngestionStatus::NeedsReview);

        $this->expectException(QueryException::class);

        $this->makeIngestion(company: $base->company, checksum: $blocking->checksum);
    }

    private function makeIngestion(?Company $company = null, ?string $checksum = null): DocumentIngestion
    {
        $tenant = $company?->tenant ?? Tenant::factory()->create();
        $company ??= Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $asset = MediaAsset::create([
            'tenant_id' => $tenant->id,
            'type' => MediaAssetType::Document,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'ingestions/test.pdf',
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
            'checksum' => hash('sha256', Str::uuid()->toString()),
            'uploaded_by' => $user->id,
        ]);

        return DocumentIngestion::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'kind' => DocumentKind::SupplierInvoice,
            'status' => IngestionStatus::Uploaded,
            'media_asset_id' => $asset->id,
            'checksum' => $checksum ?? hash('sha256', Str::uuid()->toString()),
            'created_by' => $user->id,
        ]);
    }
}
