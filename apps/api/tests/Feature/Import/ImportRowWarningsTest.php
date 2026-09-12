<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\Enums\ImportWarningCode;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportRowExportService;
use App\Modules\Import\Services\ImportService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Application\Services\UnitsProvisioningService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ImportRowWarningsTest extends TestCase
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
            'slug' => 'test-import-warnings',
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
            'email' => 'warnings@example.com',
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
        app(UnitsProvisioningService::class)->provisionForCompany($this->company);

        Storage::fake('local');
    }

    public function test_warnings_accumulate_and_do_not_affect_validity_or_failed_counts(): void
    {
        $service = app(ImportService::class);
        $job = $service->createJob(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: $this->user->id,
            type: ImportType::Partners,
            filename: 'partners.csv',
            filePath: 'imports/test/partners.csv',
            totalRows: 1
        );
        $row = $service->addRow($job, 1, ['name' => 'ACME', 'type' => 'customer']);

        $service->addRowWarning($row, ImportWarningCode::PriceConflict, 'provided 12.00 vs derived 11.90');
        $service->addRowWarning($row, ImportWarningCode::BalanceNotPosted, 'opening balances locked');

        $row->refresh();
        $warnings = $row->warnings;
        $this->assertIsArray($warnings);
        $this->assertCount(2, $warnings);
        $this->assertSame('price_conflict', $warnings[0]['code'] ?? null);

        $service->validateJob($job);
        $this->assertTrue($row->refresh()->is_valid);
        $this->assertSame(0, $job->refresh()->failed_rows);

        $exportPath = app(ImportRowExportService::class)->generate($job->refresh(), 'csv');
        $this->assertNotNull($exportPath);
        $this->assertStringContainsString('warning,price_conflict', Storage::disk('local')->get($exportPath));
    }

    public function test_float_shaped_barcode_ending_in_five_zeroes_gets_a_non_blocking_corruption_warning(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'file' => UploadedFile::fake()->createWithContent('float-barcode.csv', implode("\n", [
                'name,sku,barcode',
                'Float Barcode Product,FLOAT-BARCODE,3337870000000.00000',
            ])),
            'type' => 'products',
        ])->assertCreated();
        $jobId = $response->json('data.id');
        $this->assertIsString($jobId);

        $row = ImportJob::query()->findOrFail($jobId)->rows()->sole();
        $this->assertTrue($row->is_valid);
        $this->assertContains(
            'barcode_float_corruption_suspected',
            array_column($row->warnings ?? [], 'code'),
        );
    }
}
