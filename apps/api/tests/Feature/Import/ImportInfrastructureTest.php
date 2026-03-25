<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Modules\Import\Services\ImportService;
use App\Modules\Import\Services\ValidationEngine;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ImportInfrastructureTest extends TestCase
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
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
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

    // === Import Job Tests ===

    public function test_import_job_class_exists(): void
    {
        $this->assertTrue(class_exists(ImportJob::class));
    }

    public function test_import_row_class_exists(): void
    {
        $this->assertTrue(class_exists(ImportRow::class));
    }

    public function test_import_status_enum_exists(): void
    {
        $this->assertTrue(enum_exists(ImportStatus::class));
    }

    public function test_import_status_has_required_cases(): void
    {
        $this->assertEquals('pending', ImportStatus::Pending->value);
        $this->assertEquals('validating', ImportStatus::Validating->value);
        $this->assertEquals('validated', ImportStatus::Validated->value);
        $this->assertEquals('importing', ImportStatus::Importing->value);
        $this->assertEquals('completed', ImportStatus::Completed->value);
        $this->assertEquals('failed', ImportStatus::Failed->value);
    }

    public function test_import_type_enum_exists(): void
    {
        $this->assertTrue(enum_exists(ImportType::class));
    }

    public function test_import_type_has_required_cases(): void
    {
        $this->assertEquals('partners', ImportType::Partners->value);
        $this->assertEquals('products', ImportType::Products->value);
        $this->assertEquals('stock_levels', ImportType::StockLevels->value);
        $this->assertEquals('opening_balances', ImportType::OpeningBalances->value);
    }

    public function test_import_job_has_required_properties(): void
    {
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ImportType::Partners,
            'status' => ImportStatus::Pending,
            'original_filename' => 'customers.csv',
            'file_path' => 'imports/test.csv',
            'total_rows' => 100,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
        ]);

        $this->assertNotNull($job->id);
        $this->assertEquals($this->tenant->id, $job->tenant_id);
        $this->assertEquals($this->user->id, $job->user_id);
        $this->assertEquals(ImportType::Partners, $job->type);
        $this->assertEquals(ImportStatus::Pending, $job->status);
        $this->assertEquals('customers.csv', $job->original_filename);
        $this->assertEquals(100, $job->total_rows);
    }

    public function test_import_row_has_required_properties(): void
    {
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ImportType::Partners,
            'status' => ImportStatus::Pending,
            'original_filename' => 'customers.csv',
            'file_path' => 'imports/test.csv',
            'total_rows' => 1,
        ]);

        $row = ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => ['name' => 'Acme Corp', 'email' => 'acme@example.com'],
            'is_valid' => true,
            'errors' => [],
        ]);

        $this->assertNotNull($row->id);
        $this->assertEquals($job->id, $row->import_job_id);
        $this->assertEquals(1, $row->row_number);
        $this->assertEquals(['name' => 'Acme Corp', 'email' => 'acme@example.com'], $row->data);
        $this->assertTrue($row->is_valid);
    }

    public function test_import_job_has_rows_relationship(): void
    {
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ImportType::Partners,
            'status' => ImportStatus::Pending,
            'original_filename' => 'customers.csv',
            'file_path' => 'imports/test.csv',
            'total_rows' => 2,
        ]);

        ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => ['name' => 'Customer 1'],
            'is_valid' => true,
        ]);

        ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 2,
            'data' => ['name' => 'Customer 2'],
            'is_valid' => true,
        ]);

        $this->assertCount(2, $job->rows);
    }

    // === Import Service Tests ===

    public function test_import_service_class_exists(): void
    {
        $this->assertTrue(class_exists(ImportService::class));
    }

    public function test_validation_engine_class_exists(): void
    {
        $this->assertTrue(class_exists(ValidationEngine::class));
    }

    public function test_can_create_import_job(): void
    {
        /** @var ImportService $importService */
        $importService = app(ImportService::class);

        $job = $importService->createJob(
            tenantId: $this->tenant->id,
            userId: $this->user->id,
            type: ImportType::Partners,
            filename: 'customers.csv',
            filePath: 'imports/test.csv',
            totalRows: 50
        );

        $this->assertInstanceOf(ImportJob::class, $job);
        $this->assertEquals(ImportStatus::Pending, $job->status);
        $this->assertEquals(50, $job->total_rows);
    }

    public function test_can_add_rows_to_import_job(): void
    {
        /** @var ImportService $importService */
        $importService = app(ImportService::class);

        $job = $importService->createJob(
            tenantId: $this->tenant->id,
            userId: $this->user->id,
            type: ImportType::Partners,
            filename: 'customers.csv',
            filePath: 'imports/test.csv',
            totalRows: 2
        );

        $importService->addRow($job, 1, ['name' => 'Customer 1', 'type' => 'customer']);
        $importService->addRow($job, 2, ['name' => 'Customer 2', 'type' => 'supplier']);

        $this->assertCount(2, $job->fresh()->rows);
    }

    // === Validation Engine Tests ===

    public function test_validation_engine_validates_required_fields(): void
    {
        /** @var ValidationEngine $engine */
        $engine = app(ValidationEngine::class);

        $rules = [
            'name' => ['required'],
            'email' => ['required', 'email'],
        ];

        $validData = ['name' => 'Acme Corp', 'email' => 'acme@example.com'];
        $invalidData = ['name' => '', 'email' => 'invalid'];

        $validResult = $engine->validate($validData, $rules);
        $invalidResult = $engine->validate($invalidData, $rules);

        $this->assertTrue($validResult['is_valid']);
        $this->assertEmpty($validResult['errors']);

        $this->assertFalse($invalidResult['is_valid']);
        $this->assertNotEmpty($invalidResult['errors']);
    }

    public function test_validation_engine_validates_unique_fields(): void
    {
        /** @var ValidationEngine $engine */
        $engine = app(ValidationEngine::class);

        // Create existing partner
        \App\Modules\Partner\Domain\Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Existing Partner',
            'type' => \App\Modules\Partner\Domain\Enums\PartnerType::Customer,
            'vat_number' => 'FR12345678901',
        ]);

        $rules = [
            'vat_number' => ['unique:partners,vat_number,tenant_id,'.$this->tenant->id],
        ];

        $duplicateData = ['vat_number' => 'FR12345678901'];
        $uniqueData = ['vat_number' => 'FR98765432109'];

        $duplicateResult = $engine->validate($duplicateData, $rules, $this->tenant->id);
        $uniqueResult = $engine->validate($uniqueData, $rules, $this->tenant->id);

        $this->assertFalse($duplicateResult['is_valid']);
        $this->assertTrue($uniqueResult['is_valid']);
    }

    public function test_can_validate_import_job(): void
    {
        /** @var ImportService $importService */
        $importService = app(ImportService::class);

        $job = $importService->createJob(
            tenantId: $this->tenant->id,
            userId: $this->user->id,
            type: ImportType::Partners,
            filename: 'customers.csv',
            filePath: 'imports/test.csv',
            totalRows: 2
        );

        $importService->addRow($job, 1, ['name' => 'Valid Customer', 'type' => 'customer']);
        $importService->addRow($job, 2, ['name' => '', 'type' => 'customer']); // Invalid - no name

        $importService->validateJob($job);

        $job->refresh();
        $this->assertEquals(ImportStatus::Validated, $job->status);

        $rows = $job->rows;
        $this->assertTrue($rows->where('row_number', 1)->first()->is_valid);
        $this->assertFalse($rows->where('row_number', 2)->first()->is_valid);
    }

    // === Error Reporting Tests ===

    public function test_import_row_stores_validation_errors(): void
    {
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ImportType::Partners,
            'status' => ImportStatus::Pending,
            'original_filename' => 'customers.csv',
            'file_path' => 'imports/test.csv',
            'total_rows' => 1,
        ]);

        $row = ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => ['name' => ''],
            'is_valid' => false,
            'errors' => [
                'name' => ['The name field is required.'],
            ],
        ]);

        $this->assertFalse($row->is_valid);
        $this->assertEquals(['name' => ['The name field is required.']], $row->errors);
    }

    public function test_can_get_failed_rows_for_import_job(): void
    {
        /** @var ImportService $importService */
        $importService = app(ImportService::class);

        $job = $importService->createJob(
            tenantId: $this->tenant->id,
            userId: $this->user->id,
            type: ImportType::Partners,
            filename: 'customers.csv',
            filePath: 'imports/test.csv',
            totalRows: 3
        );

        $importService->addRow($job, 1, ['name' => 'Valid 1', 'type' => 'customer']);
        $importService->addRow($job, 2, ['name' => '', 'type' => 'customer']);
        $importService->addRow($job, 3, ['name' => 'Valid 2', 'type' => 'customer']);

        $importService->validateJob($job);

        $failedRows = $importService->getFailedRows($job);

        $this->assertCount(1, $failedRows);
        $this->assertEquals(2, $failedRows->first()->row_number);
    }

    // === API Tests ===

    public function test_api_can_upload_import_file(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'customers.csv',
            "name,type,email\nAcme Corp,customer,acme@example.com\nSupplier Inc,supplier,supplier@example.com"
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'partners',
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'type',
                    'status',
                    'original_filename',
                    'total_rows',
                ],
            ]);
    }

    public function test_api_can_get_import_job_status(): void
    {
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ImportType::Partners,
            'status' => ImportStatus::Validated,
            'original_filename' => 'customers.csv',
            'file_path' => 'imports/test.csv',
            'total_rows' => 100,
            'processed_rows' => 50,
            'successful_rows' => 48,
            'failed_rows' => 2,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$job->id}");

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'id' => $job->id,
                    'status' => 'validated',
                    'total_rows' => 100,
                    'processed_rows' => 50,
                    'successful_rows' => 48,
                    'failed_rows' => 2,
                ],
            ]);
    }

    public function test_api_can_get_import_errors(): void
    {
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ImportType::Partners,
            'status' => ImportStatus::Validated,
            'original_filename' => 'customers.csv',
            'file_path' => 'imports/test.csv',
            'total_rows' => 2,
        ]);

        ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => ['name' => 'Valid'],
            'is_valid' => true,
        ]);

        ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 2,
            'data' => ['name' => ''],
            'is_valid' => false,
            'errors' => ['name' => ['The name field is required.']],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$job->id}/errors");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'row_number',
                        'data',
                        'errors',
                    ],
                ],
            ]);
    }

    public function test_api_can_start_import(): void
    {
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ImportType::Partners,
            'status' => ImportStatus::Validated,
            'original_filename' => 'customers.csv',
            'file_path' => 'imports/test.csv',
            'total_rows' => 1,
            'successful_rows' => 1,
        ]);

        ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => ['name' => 'Test Customer', 'type' => 'customer'],
            'is_valid' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute");

        $response->assertOk();

        $job->refresh();
        $this->assertContains($job->status, [ImportStatus::Importing, ImportStatus::Completed]);
    }

    public function test_api_cannot_start_import_with_validation_errors(): void
    {
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ImportType::Partners,
            'status' => ImportStatus::Validated,
            'original_filename' => 'customers.csv',
            'file_path' => 'imports/test.csv',
            'total_rows' => 1,
            'failed_rows' => 1,
        ]);

        ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => ['name' => ''],
            'is_valid' => false,
            'errors' => ['name' => ['Required']],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute");

        $response->assertUnprocessable();
    }

    public function test_unauthorized_user_cannot_access_imports(): void
    {
        $response = $this->getJson('/api/v1/imports');

        $response->assertUnauthorized();
    }

    public function test_user_cannot_access_other_tenant_imports(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $job = ImportJob::create([
            'tenant_id' => $otherTenant->id,
            'user_id' => $this->user->id,
            'type' => ImportType::Partners,
            'status' => ImportStatus::Pending,
            'original_filename' => 'customers.csv',
            'file_path' => 'imports/test.csv',
            'total_rows' => 1,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$job->id}");

        $response->assertNotFound();
    }
}
