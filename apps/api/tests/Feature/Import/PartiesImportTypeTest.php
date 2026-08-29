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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use ZipArchive;

final class PartiesImportTypeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        Storage::fake('local');
    }

    public function test_parties_import_type_exposes_columns_and_rules(): void
    {
        $type = ImportType::from('parties');

        $this->assertSame(['name', 'type'], $type->getRequiredColumns());
        $this->assertContains('opening_balance', $type->getOptionalColumns());
        $this->assertContains('opening_balance_customer', $type->getOptionalColumns());
        $this->assertContains('opening_balance_supplier', $type->getOptionalColumns());

        $rules = $type->getValidationRules();
        $this->assertSame(['required', 'in:customer,supplier,both'], $rules['type']);
        $this->assertSame(['nullable', 'string', 'max:50'], $rules['code']);
        $this->assertContains('regex:/^-?\d+(\.\d{1,3})?$/', $rules['opening_balance']);
    }

    public function test_parties_validation_rejects_a_code_longer_than_the_partner_column(): void
    {
        $validator = Validator::make(
            [
                'name' => 'Boundary Partner',
                'type' => 'customer',
                'code' => str_repeat('A', 51),
            ],
            ImportType::Parties->getValidationRules(),
        );

        try {
            $validator->validate();
            $this->fail('A 51-character party code must fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('code', $exception->errors());
            $this->assertSame(
                'The code field must not be greater than 50 characters.',
                $exception->errors()['code'][0],
            );
        }
    }

    public function test_parties_template_contains_opening_balance_columns(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/migration-wizard/template/parties');

        $response->assertOk();
        $this->assertStringContainsString('opening_balance', (string) $response->getContent());
    }

    public function test_parties_csv_upload_creates_validated_job(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'parties.csv',
            "name,type,opening_balance\nAcme Corp,customer,100.000\nParts Supplier,supplier,-50"
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'parties',
            ]);

        $response->assertCreated();

        $this->assertSame(ImportStatus::Validated->value, $response->json('data.status'));
        $this->assertSame(2, $response->json('data.total_rows'));
        $this->assertSame(0, $response->json('data.failed_rows'));
    }

    public function test_parties_extra_validation_rejects_ambiguous_both_opening_balance(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'parties.csv',
            "name,type,opening_balance\nBoth Partner,both,100.000"
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'parties',
            ]);

        $response->assertCreated();
        $this->assertSame(1, $response->json('data.failed_rows'));

        $jobId = $response->json('data.id');
        $job = ImportJob::whereKey($jobId)->firstOrFail();
        $row = $job->rows()->firstOrFail();

        $this->assertFalse($row->is_valid);
        $this->assertSame([
            "For partners of type 'both', use opening_balance_customer / opening_balance_supplier instead of opening_balance.",
        ], $row->errors['opening_balance'] ?? null);
    }

    public function test_product_images_dispatches_and_purges_the_import_jobs_relative_storage_key(): void
    {
        Bus::fake([ProcessProductImageImport::class]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $this->productImagesZip(),
                'type' => ImportType::ProductImages->value,
            ]);

        $response->assertAccepted();

        $job = ImportJob::query()->whereKey($response->json('data.id'))->firstOrFail();
        $queuedJob = null;

        Bus::assertDispatched(
            ProcessProductImageImport::class,
            function (ProcessProductImageImport $command) use (&$queuedJob, $job): bool {
                $queuedJob = $command;

                return $command->importJobId === $job->id;
            },
        );

        $this->assertInstanceOf(ProcessProductImageImport::class, $queuedJob);
        $this->assertSame($job->file_path, $queuedJob->zipPath);
        $this->assertFalse(str_starts_with($queuedJob->zipPath, '/'), 'The queued ZIP path must be disk-relative.');
        Storage::disk('local')->assertExists($job->file_path);

        $queuedJob->handle(
            $this->app->make(ProductImageImportService::class),
            $this->app->make(ImportService::class),
        );

        Storage::disk('local')->assertMissing($job->file_path);
    }

    public function test_legacy_product_images_job_fails_before_importing_and_purges_persisted_relative_key(): void
    {
        Bus::fake([ProcessProductImageImport::class]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $this->productImagesZip(),
                'type' => ImportType::ProductImages->value,
            ]);

        $response->assertAccepted();

        $job = ImportJob::query()->whereKey($response->json('data.id'))->firstOrFail();
        $legacyJob = $this->legacyProductImagesJob(
            $job->id,
            Storage::disk('local')->path($job->file_path),
            $this->tenant->id,
        );

        $this->assertNull($legacyJob->companyId);
        Storage::disk('local')->assertExists($job->file_path);

        $legacyJob->handle(
            $this->app->make(ProductImageImportService::class),
            $this->app->make(ImportService::class),
        );

        $job->refresh();
        $this->assertSame(ImportStatus::Failed, $job->status);
        $this->assertNull($job->started_at);
        $this->assertStringStartsWith('company_context_missing:', (string) $job->error_message);
        Storage::disk('local')->assertMissing($job->file_path);
    }

    private function legacyProductImagesJob(string $importJobId, string $zipPath, string $tenantId): ProcessProductImageImport
    {
        $properties = [
            'importJobId' => $importJobId,
            'zipPath' => $zipPath,
            'tenantId' => $tenantId,
        ];
        $serializedProperties = '';

        foreach ($properties as $name => $value) {
            $serializedProperties .= sprintf(
                's:%d:"%s";s:%d:"%s";',
                strlen($name),
                $name,
                strlen($value),
                $value,
            );
        }

        $class = ProcessProductImageImport::class;
        $payload = sprintf(
            'O:%d:"%s":%d:{%s}',
            strlen($class),
            $class,
            count($properties),
            $serializedProperties,
        );
        $legacyJob = unserialize($payload, ['allowed_classes' => [$class]]);

        $this->assertInstanceOf(ProcessProductImageImport::class, $legacyJob);

        return $legacyJob;
    }

    private function productImagesZip(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'product-images-');
        if ($path === false) {
            $this->fail('Unable to create a temporary ZIP path.');
        }

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE));
        $zip->addFromString('NO-MATCH.jpg', 'not-an-image');
        $zip->close();

        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return new UploadedFile($path, 'product-images.zip', 'application/zip', null, true);
    }
}
