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
use App\Modules\Import\Services\ImportService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ResultWorkbookTest extends TestCase
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
            'slug' => 'test-result-workbook',
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

        $this->user = $this->makeUser('workbook@example.com');
        $this->user->assignRole('admin');
        $this->attachUserToCompany($this->user);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_result_workbook_contains_imported_and_rejected_rows_with_warnings(): void
    {
        $service = app(ImportService::class);
        $job = $service->createJob(
            tenantId: $this->tenant->id,
            userId: $this->user->id,
            type: ImportType::Products,
            filename: 'products.csv',
            filePath: 'imports/test/products.csv',
            totalRows: 2
        );

        $importedRow = $service->addRow($job, 1, [
            'sku' => 'SKU-1',
            'name' => 'Imported Product',
            'sale_price_incl_tax' => '12.000',
        ]);
        $importedRow->update([
            'is_valid' => true,
            'is_imported' => true,
            'imported_entity_id' => 'product-1',
            // Execution writes internal bookkeeping (arrays) into data — the
            // workbook must skip underscore-prefixed keys, not crash on them.
            'data' => array_merge($importedRow->data, ['_results' => ['opening_stock' => 'ok']]),
        ]);
        $service->addRowWarning($importedRow, 'price_conflict', 'provided 12.000 vs derived 11.900');

        $rejectedRow = $service->addRow($job, 2, [
            'sku' => 'SKU-2',
            'name' => '',
            'sale_price_incl_tax' => 'bad',
        ]);
        $rejectedRow->update([
            'is_valid' => false,
            'errors' => ['name' => ['The name field is required.']],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->get("/api/v1/imports/{$job->id}/result-workbook");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = tempnam(sys_get_temp_dir(), 'result-workbook-response-');
        $this->assertIsString($path);
        file_put_contents($path, $response->streamedContent());

        $spreadsheet = IOFactory::load($path);
        unlink($path);

        $this->assertSame(['Imported', 'Rejected'], $spreadsheet->getSheetNames());

        $importedSheet = $spreadsheet->getSheetByName('Imported');
        $this->assertNotNull($importedSheet);
        $this->assertSame('warnings', $importedSheet->getCell('D1')->getValue());
        $this->assertSame('Imported Product', $importedSheet->getCell('B2')->getValue());
        $this->assertSame('price_conflict: provided 12.000 vs derived 11.900', $importedSheet->getCell('D2')->getValue());

        $rejectedSheet = $spreadsheet->getSheetByName('Rejected');
        $this->assertNotNull($rejectedSheet);
        $this->assertSame('warnings', $rejectedSheet->getCell('D1')->getValue());
        $this->assertSame('reasons', $rejectedSheet->getCell('E1')->getValue());
        $this->assertSame('name: The name field is required.', $rejectedSheet->getCell('E2')->getValue());
    }

    public function test_result_workbook_route_requires_import_permission(): void
    {
        $job = app(ImportService::class)->createJob(
            tenantId: $this->tenant->id,
            userId: $this->user->id,
            type: ImportType::Products,
            filename: 'products.csv',
            filePath: 'imports/test/products.csv',
            totalRows: 0
        );

        $unpermitted = $this->makeUser('no-imports@example.com');
        $this->attachUserToCompany($unpermitted);

        $response = $this->actingAs($unpermitted, 'sanctum')
            ->get("/api/v1/imports/{$job->id}/result-workbook");

        $response->assertForbidden();
    }

    private function makeUser(string $email): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
    }

    private function attachUserToCompany(User $user): void
    {
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
    }
}
