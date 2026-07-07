<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentIngestion;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Modules\DocumentIngestion\Domain\Enums\IngestionStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

final class IngestionUploadTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        Queue::fake();

        $this->tenant = Tenant::create([
            'name' => 'Document Ingestion Tenant',
            'slug' => 'document-ingestion-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Document Ingestion Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = $this->user('admin@test.example');
        $this->admin->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_upload_creates_ingestion_media_asset_and_attachment(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->post('/api/v1/document-ingestions', [
            'kind' => DocumentKind::SupplierInvoice->value,
            'file' => UploadedFile::fake()->image('invoice.png'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', IngestionStatus::Uploaded->value)
            ->assertJsonPath('data.kind', DocumentKind::SupplierInvoice->value);

        $ingestion = DocumentIngestion::query()->firstOrFail();
        $asset = MediaAsset::query()->findOrFail($ingestion->media_asset_id);
        $attachment = MediaAttachment::query()->where('owner_id', $ingestion->id)->firstOrFail();

        $this->assertSame(MediaAssetType::Image, $asset->type);
        $this->assertSame(MediaOwnerType::DocumentIngestion, $attachment->owner_type);
        $this->assertSame(MediaRole::SourceDocument, $attachment->role);
        $this->assertSame('ingestions', MediaOwnerType::DocumentIngestion->storageSegment());
    }

    public function test_duplicate_same_bytes_upload_returns_validation_envelope(): void
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
        $this->assertIsString($bytes);

        $first = UploadedFile::fake()->createWithContent('invoice-a.png', $bytes);
        $second = UploadedFile::fake()->createWithContent('invoice-b.png', $bytes);

        $this->actingAs($this->admin, 'sanctum')->post('/api/v1/document-ingestions', [
            'kind' => DocumentKind::SupplierInvoice->value,
            'file' => $first,
        ])->assertCreated();

        $response = $this->actingAs($this->admin, 'sanctum')->post('/api/v1/document-ingestions', [
            'kind' => DocumentKind::SupplierInvoice->value,
            'file' => $second,
        ]);

        $this->assertApiValidationErrors($response, ['file']);
    }

    public function test_upload_requires_create_permission(): void
    {
        $limited = $this->user('limited@test.example');

        $this->actingAs($limited, 'sanctum')->post('/api/v1/document-ingestions', [
            'kind' => DocumentKind::SupplierInvoice->value,
            'file' => UploadedFile::fake()->image('invoice.png'),
        ])->assertForbidden();
    }

    public function test_bad_kind_uses_validation_envelope(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->post('/api/v1/document-ingestions', [
            'kind' => 'customer_invoice',
            'file' => UploadedFile::fake()->image('invoice.png'),
        ]);

        $this->assertApiValidationErrors($response, ['kind']);
    }

    public function test_list_filters_by_status(): void
    {
        $uploaded = $this->makeIngestion(status: IngestionStatus::Uploaded);
        $failed = $this->makeIngestion(status: IngestionStatus::Failed);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/document-ingestions?status=failed');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $failed->id);

        $this->assertNotSame($uploaded->id, $response->json('data.0.id'));
    }

    public function test_detail_includes_signed_source_url(): void
    {
        $ingestion = $this->makeIngestion(status: IngestionStatus::NeedsReview);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/document-ingestions/{$ingestion->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $ingestion->id);

        $sourceUrl = $response->json('data.source_url');
        $this->assertIsString($sourceUrl);
        $this->assertStringStartsWith('/api/v1/media/', $sourceUrl);
        $this->assertStringContainsString('signature=', $sourceUrl);
    }

    public function test_reject_flips_needs_review_and_conflicts_from_committed(): void
    {
        $needsReview = $this->makeIngestion(status: IngestionStatus::NeedsReview);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$needsReview->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', IngestionStatus::Rejected->value);

        $committed = $this->makeIngestion(status: IngestionStatus::Committed);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$committed->id}/reject")
            ->assertStatus(409);
    }

    public function test_reextract_failed_ingestion_moves_back_to_extracting(): void
    {
        $failed = $this->makeIngestion(status: IngestionStatus::Failed);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$failed->id}/extract")
            ->assertAccepted()
            ->assertJsonPath('data.status', IngestionStatus::Extracting->value);
    }

    private function user(string $email): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'status' => UserStatus::Active,
        ]);
    }

    private function makeIngestion(IngestionStatus $status): DocumentIngestion
    {
        $asset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Document,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'ingestions/manual.pdf',
            'original_filename' => 'manual.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'checksum' => hash('sha256', Str::uuid()->toString()),
            'uploaded_by' => $this->admin->id,
        ]);

        /** @var DocumentIngestion $ingestion */
        $ingestion = DocumentIngestion::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'kind' => DocumentKind::SupplierInvoice,
            'status' => $status,
            'media_asset_id' => $asset->id,
            'checksum' => hash('sha256', Str::uuid()->toString()),
            'created_by' => $this->admin->id,
        ]);

        MediaAttachment::create([
            'tenant_id' => $this->tenant->id,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::DocumentIngestion,
            'owner_id' => $ingestion->id,
            'role' => MediaRole::SourceDocument,
            'sort_order' => 0,
        ]);

        return $ingestion;
    }
}
