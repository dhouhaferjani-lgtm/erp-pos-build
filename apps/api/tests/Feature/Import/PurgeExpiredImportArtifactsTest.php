<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Application\Jobs\ProcessProductImageImport;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportService;
use App\Modules\Product\Application\Services\ProductImageImportService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Application\Services\UnitsProvisioningService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\CompositeExpectation;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use ZipArchive;

final class PurgeExpiredImportArtifactsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_ninety_one_day_source_is_purged_and_stamped_while_eighty_nine_day_source_survives(): void
    {
        $tenant = $this->createTenant('purge-boundary');
        $expired = $this->createJob($tenant, 'expired.csv', now()->subDays(91));
        $live = $this->createJob($tenant, 'live.csv', now()->subDays(89));
        Storage::disk('local')->put($expired->file_path, 'expired bytes');
        Storage::disk('local')->put($live->file_path, 'live bytes');

        $this->assertSame(0, Artisan::call('imports:purge-expired'));

        Storage::disk('local')->assertMissing($expired->file_path);
        Storage::disk('local')->assertExists($live->file_path);
        $this->assertNotNull($expired->refresh()->source_purged_at);
        $this->assertNull($live->refresh()->source_purged_at);
        $this->assertDatabaseHas('import_jobs', ['id' => $expired->id]);
        $this->assertDatabaseHas('import_jobs', ['id' => $live->id]);
    }

    public function test_source_download_returns_bytes_until_purge_then_returns_typed_gone(): void
    {
        [$tenant, $user] = $this->createAuthorizedContext('purge-download');
        $live = $this->createJob($tenant, 'live-source.csv', now()->subDays(89));
        $expired = $this->createJob($tenant, 'expired-source.csv', now()->subDays(91));
        Storage::disk('local')->put($live->file_path, 'live source bytes');
        Storage::disk('local')->put($expired->file_path, 'expired source bytes');

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/imports/{$live->id}/source-file")
            ->assertOk()
            ->assertStreamedContent('live source bytes');

        Artisan::call('imports:purge-expired');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/imports/{$expired->id}/source-file")
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'source_purged');
    }

    public function test_product_images_zip_is_stamped_when_immediately_purged(): void
    {
        [$tenant, $user] = $this->createAuthorizedContext('purge-product-images');
        $company = Company::query()->where('tenant_id', $tenant->id)->firstOrFail();
        Queue::fake([ProcessProductImageImport::class]);

        $zipFile = tempnam(sys_get_temp_dir(), 'purge-product-images-');
        self::assertIsString($zipFile);
        $zip = new ZipArchive;
        self::assertTrue($zip->open($zipFile, ZipArchive::OVERWRITE));
        $zip->addFromString('README.txt', 'production dispatch proof');
        $zip->close();
        $zipBytes = file_get_contents($zipFile);
        self::assertIsString($zipBytes);
        unlink($zipFile);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id)
            ->postJson('/api/v1/imports', [
                'file' => UploadedFile::fake()->createWithContent('images.zip', $zipBytes),
                'type' => ImportType::ProductImages->value,
            ])
            ->assertAccepted();

        $jobId = $response->json('data.id');
        self::assertIsString($jobId);
        $job = ImportJob::query()->whereKey($jobId)->firstOrFail();
        $queued = null;
        Queue::assertPushed(
            ProcessProductImageImport::class,
            function (ProcessProductImageImport $dispatched) use (&$queued, $job): bool {
                $queued = $dispatched;

                return $dispatched->zipPath === $job->file_path;
            },
        );
        self::assertInstanceOf(ProcessProductImageImport::class, $queued);
        self::assertFalse(str_starts_with($queued->zipPath, DIRECTORY_SEPARATOR));

        $storageRoot = Storage::disk('local')->path('');
        $freshDiskBefore = Storage::build(['driver' => 'local', 'root' => $storageRoot]);
        self::assertTrue($freshDiskBefore->exists($job->file_path));

        $imageService = \Mockery::mock(ProductImageImportService::class);
        self::assertInstanceOf(ProductImageImportService::class, $imageService);
        $expectation = $imageService->shouldReceive('processZipImport');
        if (! $expectation instanceof CompositeExpectation) {
            self::fail('Mockery must return a composite expectation.');
        }
        $expectation->__call('once', []);
        $expectation->andReturn([]);

        $queued->handle(
            $imageService,
            $this->app->make(ImportService::class),
        );

        $freshDiskAfter = Storage::build(['driver' => 'local', 'root' => $storageRoot]);
        self::assertFalse($freshDiskAfter->exists($job->file_path));
        $completed = $job->refresh();
        $this->assertSame(ImportStatus::Completed, $completed->status);
        $this->assertNotNull($completed->claimed_at);
        $this->assertNotNull($completed->worker_started_at);
        $this->assertNotNull($completed->completed_at);
        $this->assertNotNull($completed->source_purged_at);
    }

    public function test_missing_file_and_second_run_are_idempotent_and_rows_stay_tenant_scoped(): void
    {
        $tenant = $this->createTenant('purge-idempotent');
        $missing = $this->createJob($tenant, 'already-missing.csv', now()->subDays(91));
        $orphanTenantId = (string) Str::uuid();
        $orphan = ImportJob::create([
            'tenant_id' => $orphanTenantId,
            'user_id' => (string) Str::uuid(),
            'type' => ImportType::Products,
            'status' => ImportStatus::Completed,
            'original_filename' => 'other-tenant.csv',
            'file_path' => "imports/{$orphanTenantId}/other-tenant.csv",
            'total_rows' => 0,
            'completed_at' => now()->subDays(91),
        ]);
        Storage::disk('local')->put($orphan->file_path, 'other tenant bytes');

        Artisan::call('imports:purge-expired');
        $firstStamp = $missing->refresh()->source_purged_at?->toDateTimeString();
        $this->assertNotNull($firstStamp);
        $this->assertNull($orphan->refresh()->source_purged_at);
        Storage::disk('local')->assertExists($orphan->file_path);

        $this->travel(5)->minutes();
        Artisan::call('imports:purge-expired');

        $this->assertSame($firstStamp, $missing->refresh()->source_purged_at->toDateTimeString());
        $this->assertDatabaseHas('import_jobs', ['id' => $missing->id]);
    }

    public function test_source_file_requires_imports_manage_and_hides_cross_tenant_jobs(): void
    {
        [$tenantA, $adminA] = $this->createAuthorizedContext('source-deny-a');
        $companyA = Company::query()->where('tenant_id', $tenantA->id)->firstOrFail();
        $ownJob = $this->createJob($tenantA, 'own-source.csv', now()->subDays(1));
        Storage::disk('local')->put($ownJob->file_path, 'own bytes');

        $unprivileged = User::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Source Viewer',
            'email' => 'source-viewer-'.Str::lower(Str::random(8)).'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $unprivileged->id,
            'company_id' => $companyA->id,
            'role' => 'viewer',
        ]);

        $this->actingAs($unprivileged, 'sanctum')
            ->getJson("/api/v1/imports/{$ownJob->id}/source-file")
            ->assertForbidden();

        $tenantB = $this->createTenant('source-deny-b');
        $otherJob = $this->createJob($tenantB, 'other-source.csv', now()->subDays(1));
        Storage::disk('local')->put($otherJob->file_path, 'other bytes');

        app(CompanyContext::class)->setCompanyId($companyA->id);
        $this->actingAs($adminA, 'sanctum')
            ->getJson("/api/v1/imports/{$otherJob->id}/source-file")
            ->assertNotFound();
    }

    public function test_sweep_purges_every_candidate_across_more_than_two_chunks(): void
    {
        $tenant = $this->createTenant('purge-keyset-pages');
        $now = now();
        $rows = [];

        for ($index = 0; $index < 2001; $index++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'user_id' => (string) Str::uuid(),
                'type' => ImportType::Products->value,
                'status' => ImportStatus::Completed->value,
                'original_filename' => "purge-{$index}.csv",
                'file_path' => "imports/{$tenant->id}/purge-{$index}.csv",
                'total_rows' => 0,
                'processed_rows' => 0,
                'successful_rows' => 0,
                'skipped_rows' => 0,
                'failed_rows' => 0,
                'completed_at' => $now->copy()->subDays(91),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('import_jobs')->insert($chunk);
        }

        $this->assertSame(0, Artisan::call('imports:purge-expired'));
        $this->assertSame(
            2001,
            ImportJob::query()
                ->where('tenant_id', $tenant->id)
                ->whereNotNull('source_purged_at')
                ->count(),
        );
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => str_replace('-', ' ', $slug),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    public function test_expired_sweep_also_removes_correction_row_exports(): void
    {
        $tenant = $this->createTenant('purge-rows');
        $expired = $this->createJob($tenant, 'expired.csv', now()->subDays(91));
        $live = $this->createJob($tenant, 'live.csv', now()->subDays(89));
        Storage::disk('local')->put($expired->file_path, 'expired bytes');
        Storage::disk('local')->put($live->file_path, 'live bytes');
        // Downloads delete their own artefact; these stand in for ones orphaned by
        // a connection that dropped mid-stream, which must not outlive retention.
        // Artefact names carry a per-request token (gate r2 M3-R), so the sweep is
        // over the job-id PREFIX — an exact-name sweep would strand every orphan.
        $expiredCsv = 'imports/rows/'.$expired->id.'.'.Str::uuid()->toString().'.csv';
        $expiredXlsx = 'imports/rows/'.$expired->id.'.'.Str::uuid()->toString().'.xlsx';
        $expiredSecond = 'imports/rows/'.$expired->id.'.'.Str::uuid()->toString().'.csv';
        $liveCsv = 'imports/rows/'.$live->id.'.'.Str::uuid()->toString().'.csv';
        Storage::disk('local')->put($expiredCsv, 'expired rows');
        Storage::disk('local')->put($expiredXlsx, 'expired rows');
        Storage::disk('local')->put($expiredSecond, 'expired rows');
        Storage::disk('local')->put($liveCsv, 'live rows');

        $this->assertSame(0, Artisan::call('imports:purge-expired'));

        Storage::disk('local')->assertMissing($expiredCsv);
        Storage::disk('local')->assertMissing($expiredXlsx);
        Storage::disk('local')->assertMissing($expiredSecond);
        Storage::disk('local')->assertExists($liveCsv);
    }

    private function createJob(Tenant $tenant, string $filename, \DateTimeInterface $completedAt): ImportJob
    {
        return ImportJob::create([
            'tenant_id' => $tenant->id,
            'user_id' => (string) Str::uuid(),
            'type' => ImportType::Products,
            'status' => ImportStatus::Completed,
            'original_filename' => $filename,
            'file_path' => "imports/{$tenant->id}/{$filename}",
            'total_rows' => 0,
            'completed_at' => $completedAt,
        ]);
    }

    /**
     * @return array{Tenant, User}
     */
    private function createAuthorizedContext(string $slug): array
    {
        $tenant = $this->createTenant($slug);
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Purge Company',
            'legal_name' => 'Purge Company LLC',
            'tax_id' => 'PURGE-'.Str::upper(Str::random(8)),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Purge User',
            'email' => 'purge-'.Str::lower(Str::random(8)).'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(UnitsProvisioningService::class)->provisionForCompany($company);

        return [$tenant, $user];
    }
}
