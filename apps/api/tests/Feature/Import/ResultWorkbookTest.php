<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\Enums\ImportWarningCode;
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

    /**
     * Stable, valid UUID used as the imported product's entity id. `import_rows.imported_entity_id`
     * is a `uuid` column on PostgreSQL, so the fixture value must parse as one.
     */
    private const IMPORTED_PRODUCT_ID = '0192f3a1-4c7b-7d2e-8f10-a1b2c3d4e5f6';

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
            companyId: $this->company->id,
            userId: $this->user->id,
            type: ImportType::Products,
            filename: 'products.csv',
            filePath: 'imports/test/products.csv',
            totalRows: 4
        );

        $importedRow = $service->addRow($job, 1, [
            'sku' => 'SKU-1',
            'name' => 'Imported Product',
            'sale_price_incl_tax' => '12.000',
        ]);
        $importedRow->update([
            'is_valid' => true,
            'is_imported' => true,
            'outcome' => ImportRowOutcome::Imported,
            // C-10: this column is `uuid` in PostgreSQL — a non-UUID literal
            // ('product-1') is silently accepted by SQLite but raises 22P02 on
            // PG. Bind a real UUID so the class is driver-agnostic.
            'imported_entity_id' => self::IMPORTED_PRODUCT_ID,
            // Execution writes internal bookkeeping (arrays) into data — the
            // workbook must skip underscore-prefixed keys, not crash on them.
            'data' => array_merge($importedRow->data, ['_results' => ['opening_stock' => ['opening_stock' => 'ok']]]),
        ]);
        $service->addRowWarning($importedRow, ImportWarningCode::PriceConflict, 'provided 12.000 vs derived 11.900');

        $rejectedRow = $service->addRow($job, 2, [
            'sku' => 'SKU-2',
            'name' => '',
            'sale_price_incl_tax' => 'bad',
        ]);
        $rejectedRow->update([
            'is_valid' => false,
            'outcome' => ImportRowOutcome::Failed,
            'errors' => ['name' => ['The name field is required.']],
            'import_error_code' => ImportErrorCode::BarcodeIdentityConflict,
            'import_error' => 'The barcode is reused by contradictory product identities.',
        ]);

        $skippedRow = $service->addRow($job, 3, [
            'sku' => 'SKU-3',
            'name' => 'Skipped Product',
            'sale_price_incl_tax' => '9.000',
        ]);
        $skippedRow->update([
            'is_valid' => true,
            'is_imported' => false,
            'outcome' => ImportRowOutcome::DuplicateSkipped,
            'warnings' => [['code' => 'duplicate_in_file', 'detail' => 'Existing product kept.']],
        ]);

        $pendingRow = $service->addRow($job, 4, [
            'sku' => 'SKU-4',
            'name' => 'Pending Product',
            'sale_price_incl_tax' => '7.000',
        ]);
        $pendingRow->update([
            'is_valid' => true,
            'is_imported' => false,
            'outcome' => ImportRowOutcome::Pending,
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

        $this->assertSame(['Imported', 'Skipped', 'Rejected'], $spreadsheet->getSheetNames());

        $importedSheet = $spreadsheet->getSheetByName('Imported');
        $this->assertNotNull($importedSheet);
        $this->assertSame('warnings', $importedSheet->getCell('D1')->getValue());
        $this->assertSame('Imported Product', $importedSheet->getCell('B2')->getValue());
        $this->assertSame('price_conflict: provided 12.000 vs derived 11.900', $importedSheet->getCell('D2')->getValue());
        $this->assertSame(2, $importedSheet->getHighestDataRow(), 'A duplicate_skipped row must never appear as imported.');

        $skippedSheet = $spreadsheet->getSheetByName('Skipped');
        $this->assertNotNull($skippedSheet);
        $this->assertSame('Skipped Product', $skippedSheet->getCell('B2')->getValue());
        $this->assertSame('duplicate_in_file: Existing product kept.', $skippedSheet->getCell('D2')->getValue());
        $this->assertSame('Pending Product', $skippedSheet->getCell('B3')->getValue());
        $this->assertSame(
            'not_processed: This row was not processed.',
            $skippedSheet->getCell('E3')->getValue(),
        );

        $rejectedSheet = $spreadsheet->getSheetByName('Rejected');
        $this->assertNotNull($rejectedSheet);
        $this->assertStringContainsString(
            'barcode_identity_conflict: The barcode is reused by contradictory product identities.',
            (string) $rejectedSheet->getCell('E2')->getValue()
        );
        $this->assertSame('warnings', $rejectedSheet->getCell('D1')->getValue());
        $this->assertSame('reasons', $rejectedSheet->getCell('E1')->getValue());
        $this->assertSame(
            'name: The name field is required.; barcode_identity_conflict: The barcode is reused by contradictory product identities.',
            $rejectedSheet->getCell('E2')->getValue()
        );

        $reportedSkus = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            for ($rowNumber = 2; $rowNumber <= $sheet->getHighestDataRow(); $rowNumber++) {
                $sku = $sheet->getCell("A{$rowNumber}")->getValue();
                if (is_string($sku) && $sku !== '') {
                    $reportedSkus[] = $sku;
                }
            }
        }
        sort($reportedSkus);
        $this->assertSame(['SKU-1', 'SKU-2', 'SKU-3', 'SKU-4'], $reportedSkus);
        $this->assertCount(4, array_unique($reportedSkus), 'Every staged row must appear on exactly one result sheet.');
    }

    public function test_result_workbook_route_requires_import_permission(): void
    {
        $job = app(ImportService::class)->createJob(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
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
