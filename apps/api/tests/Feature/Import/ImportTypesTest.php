<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\Enums\ImportWarningCode;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
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

class ImportTypesTest extends TestCase
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
        app(UnitsProvisioningService::class)->provisionForCompany($this->company);

        Storage::fake('local');
    }

    // === Partner Import Tests ===

    public function test_can_import_partners_from_csv(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'partners.csv',
            "name,type,email,phone\nAcme Corp,customer,acme@example.com,+1234567890\nSupplier Inc,supplier,supplier@example.com,+0987654321"
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'partners',
            ]);

        $response->assertCreated();

        $jobId = $response->json('data.id');
        $job = ImportJob::find($jobId);
        $this->assertInstanceOf(ImportJob::class, $job);

        $this->assertEquals(ImportStatus::Validated, $job->status);
        $this->assertEquals(2, $job->total_rows);
        $this->assertEquals(0, $job->failed_rows);

        // Execute the import
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute");

        $response->assertOk();

        // Verify partners were created
        $this->assertEquals(2, Partner::where('tenant_id', $this->tenant->id)->count());
        $this->assertDatabaseHas('partners', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Acme Corp',
            'email' => 'acme@example.com',
        ]);
    }

    public function test_partner_import_validates_required_fields(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'partners.csv',
            "name,type,email\nAcme Corp,customer,acme@example.com\n,customer,missing-name@example.com"
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'partners',
            ]);

        $response->assertCreated();
        $this->assertEquals(1, $response->json('data.failed_rows'));
    }

    public function test_partner_import_validates_type_field(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'partners.csv',
            "name,type,email\nAcme Corp,invalid_type,acme@example.com"
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'partners',
            ]);

        $response->assertCreated();
        $this->assertEquals(1, $response->json('data.failed_rows'));
    }

    public function test_partner_import_with_both_type(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'partners.csv',
            "name,type,email\nBoth Company,both,both@example.com"
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'partners',
            ]);

        $response->assertCreated();

        $jobId = $response->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute");

        $partner = Partner::where('name', 'Both Company')->first();
        $this->assertNotNull($partner);
        $this->assertEquals('both', $partner->type->value);
    }

    // === Product Import Tests ===

    public function test_product_barcode_validation_and_k11_vocabularies_are_pinned(): void
    {
        $rules = ImportType::Products->getValidationRules();

        $this->assertSame(['nullable', 'string', 'max:100'], $rules['barcode'] ?? null);
        $this->assertSame('barcode_identity_conflict', ImportErrorCode::tryFrom('barcode_identity_conflict')?->value);
        $this->assertSame('multi_location', ImportWarningCode::tryFrom('multi_location')?->value);
        $this->assertSame(
            'barcode_float_corruption_suspected',
            ImportWarningCode::tryFrom('barcode_float_corruption_suspected')?->value,
        );
    }

    public function test_can_import_products_from_csv(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'products.csv',
            "name,sku,type,sale_price,purchase_price\nBrake Pad,BP-001,part,25.99,15.00\nOil Change,OC-001,service,45.00,0"
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'products',
            ]);

        $response->assertCreated();

        $jobId = $response->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute");

        $this->assertEquals(2, Product::where('tenant_id', $this->tenant->id)->count());
        $this->assertDatabaseHas('products', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Brake Pad',
            'sku' => 'BP-001',
        ]);
    }

    public function test_product_import_accepts_extended_columns_and_defaults_type(): void
    {
        $this->assertSame(['name'], ImportType::Products->getRequiredColumns());
        $this->assertContains('sale_price_incl_tax', ImportType::Products->getOptionalColumns());
        $this->assertContains('sale_price_excl_tax', ImportType::Products->getOptionalColumns());
        $this->assertContains('margin', ImportType::Products->getOptionalColumns());
        $this->assertContains('quantity', ImportType::Products->getOptionalColumns());
        $this->assertContains('location_code', ImportType::Products->getOptionalColumns());
        $this->assertContains('brand', ImportType::Products->getOptionalColumns());

        $templateResponse = $this->actingAs($this->user, 'sanctum')
            ->get('/api/v1/migration-wizard/template/products');

        $templateResponse->assertOk();
        $templateResponse->assertSee('sale_price_incl_tax', false);
        $templateResponse->assertSee('sale_price_excl_tax', false);
        $templateResponse->assertSee('margin', false);
        $templateResponse->assertSee('quantity', false);
        $templateResponse->assertSee('location_code', false);
        $templateResponse->assertSee('brand', false);

        $file = UploadedFile::fake()->createWithContent(
            'products.csv',
            "name,sku,margin\nBrake Pad,BP-DEFAULT,12.5"
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'products',
            ]);

        $response->assertCreated();
        $this->assertEquals(0, $response->json('data.failed_rows'));

        $jobId = $response->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk();

        $product = Product::where('sku', 'BP-DEFAULT')->firstOrFail();
        $this->assertSame(ProductType::Part, $product->type);
    }

    public function test_product_import_validates_margin_scale(): void
    {
        $valid = UploadedFile::fake()->createWithContent(
            'products.csv',
            "name,margin\nValid Margin,12.5"
        );

        $validResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $valid,
                'type' => 'products',
            ]);

        $validResponse->assertCreated();
        $this->assertEquals(0, $validResponse->json('data.failed_rows'));

        $invalid = UploadedFile::fake()->createWithContent(
            'products.csv',
            "name,margin\nInvalid Margin,12.555"
        );

        $invalidResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $invalid,
                'type' => 'products',
            ]);

        $invalidResponse->assertCreated();
        $this->assertEquals(1, $invalidResponse->json('data.failed_rows'));
    }

    public function test_product_import_validates_product_type(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'products.csv',
            "name,sku,type\nBrake Pad,BP-001,invalid"
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'products',
            ]);

        $response->assertCreated();
        $this->assertEquals(1, $response->json('data.failed_rows'));
    }

    // === Stock Level Import Tests ===
    //
    // Removed by owner ruling D4 (document-per-action remediation, lane V6):
    // the `stock_levels` import type is retired. Its refusal — and the fact
    // that historical stock_levels jobs stay readable — is pinned by
    // StockLevelsImportDeprecatedTest.

    // === Opening Balance Import Tests ===

    public function test_can_import_opening_balances(): void
    {
        // Create prerequisite accounts: the target account plus the OBE plug
        // account the batch-documented path offsets one-sided rows against.
        $account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1000',
            'name' => 'Cash',
            'type' => AccountType::Asset,
        ]);

        $obeAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1080',
            'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity,
            'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity->value,
            'is_system' => true,
        ]);

        /** @var ImportService $importService */
        $importService = app(ImportService::class);

        $job = $importService->createJob(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: $this->user->id,
            type: ImportType::OpeningBalances,
            filename: 'balances.csv',
            filePath: 'imports/balances.csv',
            totalRows: 1
        );

        $importService->addRow($job, 1, [
            'account_code' => '1000',
            'debit' => '5000.00',
            'credit' => '0.00',
            'description' => 'Opening cash balance',
        ]);

        $importService->validateJob($job);
        $importService->executeImport($job);

        $job->refresh();
        $this->assertEquals(ImportStatus::Completed, $job->status);

        $batch = OpeningBalanceBatch::forCompany($this->company->id)
            ->ofType(OpeningBatchType::Accounting)
            ->firstOrFail();

        $entry = JournalEntry::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('opening_balance', $entry->source_type);
        $this->assertSame($batch->id, $entry->source_id);
        $this->assertTrue($entry->is_historical);

        $lines = $entry->lines()->get();
        $this->assertCount(2, $lines);

        $accountLine = $lines->firstWhere('account_id', $account->id);
        $this->assertNotNull($accountLine);
        $this->assertSame(0, bccomp($accountLine->debit, '5000', 3));

        $obeLine = $lines->firstWhere('account_id', $obeAccount->id);
        $this->assertNotNull($obeLine);
        $this->assertSame(0, bccomp($obeLine->credit, '5000', 3));
    }

    /**
     * Requirement 4/5: the unmappable account becomes actionable per-row feedback
     * rather than an aborted run / opaque execution error. The job's terminal LABEL
     * is Failed because nothing posted.
     */
    public function test_opening_balance_import_marks_missing_account_row_invalid_without_aborting(): void
    {
        /** @var ImportService $importService */
        $importService = app(ImportService::class);

        $job = $importService->createJob(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: $this->user->id,
            type: ImportType::OpeningBalances,
            filename: 'balances.csv',
            filePath: 'imports/balances.csv',
            totalRows: 1
        );

        $importService->addRow($job, 1, [
            'account_code' => '9999',
            'debit' => '5000.00',
            'credit' => '0.00',
        ]);

        $importService->validateJob($job);
        // Must not throw — that is what requirement 4 forbids.
        $result = $importService->executeImport($job);
        $this->assertSame(0, $result['imported_count']);

        $job->refresh();
        $this->assertEquals(ImportStatus::Failed, $job->status);
        $this->assertSame(0, JournalEntry::where('company_id', $this->company->id)->count());

        $row = $job->rows()->where('row_number', 1)->firstOrFail();
        $this->assertSame('balance_not_posted', ($row->warnings ?? [])[0]['code'] ?? null);
        $this->assertSame('error: validation_failed', $row->data['_results']['accounting_balances']['gl_balance'] ?? null);
        $this->assertFalse($row->is_imported);
        $this->assertSame(0, $job->successful_rows);

        // Nothing posted => no batch residue blocking the next import or the wizard.
        $this->assertSame(0, OpeningBalanceBatch::forCompany($this->company->id)->count());
    }

    /**
     * A GL opening-balance import posts a permanent, locked opening entry — the same
     * act the opening-batch API gates behind accounts.manage. imports.manage alone
     * must not be a way around that gate.
     */
    public function test_opening_balance_upload_requires_accounts_manage(): void
    {
        $importOnly = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Import Only',
            'email' => 'import-only@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $importOnly->givePermissionTo('imports.manage');

        UserCompanyMembership::create([
            'user_id' => $importOnly->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        $this->assertFalse($importOnly->can('accounts.manage'));

        $response = $this->actingAs($importOnly, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => UploadedFile::fake()->createWithContent(
                    'balances.csv',
                    "account_code,debit,credit\n1000,5000.00,0.00"
                ),
                'type' => 'opening_balances',
            ]);

        $response->assertForbidden();
        $this->assertSame('OPENING_BALANCES_REQUIRE_ACCOUNTS_MANAGE', $response->json('error.code'));
        $this->assertSame(0, ImportJob::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_opening_balance_import_rejects_money_beyond_three_decimals(): void
    {
        /** @var ImportService $importService */
        $importService = app(ImportService::class);

        $job = $importService->createJob(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: $this->user->id,
            type: ImportType::OpeningBalances,
            filename: 'balances.csv',
            filePath: 'imports/balances.csv',
            totalRows: 1
        );

        $importService->addRow($job, 1, [
            'account_code' => '1000',
            'debit' => '5000.1234',
            'credit' => '0.00',
        ]);

        $importService->validateJob($job);

        $row = $job->rows()->where('row_number', 1)->firstOrFail();
        $this->assertFalse($row->is_valid, 'excess decimals must be rejected, not silently truncated');
        $this->assertArrayHasKey('debit', $row->errors ?? []);
    }

    // === API Error Handling Tests ===

    public function test_api_rejects_missing_required_columns(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'partners.csv',
            "name,email\nAcme Corp,acme@example.com"
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'partners',
            ]);

        $response->assertUnprocessable();
        $this->assertArrayHasKey('missing_columns', $response->json('errors'));
        $this->assertContains('type', $response->json('errors.missing_columns'));
    }

    public function test_import_type_validation_rules_are_correct(): void
    {
        $partnerRules = ImportType::Partners->getValidationRules();
        $this->assertArrayHasKey('name', $partnerRules);
        $this->assertContains('required', $partnerRules['name']);

        $productRules = ImportType::Products->getValidationRules();
        $this->assertArrayHasKey('sku', $productRules);
        $this->assertContains('nullable', $productRules['sku']);
        $this->assertContains('nullable', $productRules['type']);
        $this->assertContains('regex:/^-?\d+(\.\d{1,2})?$/', $productRules['margin']);

        $stockRules = ImportType::StockLevels->getValidationRules();
        $this->assertArrayHasKey('quantity', $stockRules);
        $this->assertContains('required', $stockRules['quantity']);

        $balanceRules = ImportType::OpeningBalances->getValidationRules();
        $this->assertArrayHasKey('account_code', $balanceRules);
        $this->assertContains('regex:/^-?\d+(\.\d{1,3})?$/', $balanceRules['debit']);
        $this->assertContains('regex:/^-?\d+(\.\d{1,3})?$/', $balanceRules['credit']);
    }
}
