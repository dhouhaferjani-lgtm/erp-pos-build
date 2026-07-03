<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
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

    public function test_can_import_stock_levels(): void
    {
        // Create prerequisite data
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'type' => ProductType::Part,
        ]);

        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'code' => 'WH-MAIN',
            'type' => 'warehouse',
            'is_default' => true,
            'is_active' => true,
        ]);

        /** @var ImportService $importService */
        $importService = app(ImportService::class);

        $job = $importService->createJob(
            tenantId: $this->tenant->id,
            userId: $this->user->id,
            type: ImportType::StockLevels,
            filename: 'stock.csv',
            filePath: 'imports/stock.csv',
            totalRows: 1
        );

        $importService->addRow($job, 1, [
            'product_sku' => 'TEST-001',
            'location_code' => 'WH-MAIN',
            'quantity' => '100',
        ]);

        $importService->validateJob($job);
        $importService->executeImport($job);

        $job->refresh();
        $this->assertEquals(ImportStatus::Completed, $job->status);
        $this->assertEquals(1, $job->successful_rows);

        $this->assertDatabaseHas('stock_levels', [
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => '100.00',
        ]);
    }

    public function test_stock_level_import_fails_for_missing_product(): void
    {
        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'code' => 'WH-MAIN',
            'type' => 'warehouse',
            'is_default' => true,
            'is_active' => true,
        ]);

        /** @var ImportService $importService */
        $importService = app(ImportService::class);

        $job = $importService->createJob(
            tenantId: $this->tenant->id,
            userId: $this->user->id,
            type: ImportType::StockLevels,
            filename: 'stock.csv',
            filePath: 'imports/stock.csv',
            totalRows: 1
        );

        $importService->addRow($job, 1, [
            'product_sku' => 'NONEXISTENT',
            'location_code' => 'WH-MAIN',
            'quantity' => '100',
        ]);

        $importService->validateJob($job);

        // Validation passes but execution will fail
        $job->refresh();
        $this->assertEquals(ImportStatus::Validated, $job->status);

        $importService->executeImport($job);

        $job->refresh();
        $this->assertEquals(ImportStatus::Failed, $job->status);
        $this->assertEquals(1, $job->failed_rows);
    }

    // === Opening Balance Import Tests ===

    public function test_can_import_opening_balances(): void
    {
        // Create prerequisite account
        $account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1000',
            'name' => 'Cash',
            'type' => AccountType::Asset,
        ]);

        /** @var ImportService $importService */
        $importService = app(ImportService::class);

        $job = $importService->createJob(
            tenantId: $this->tenant->id,
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

        $this->assertDatabaseHas('journal_lines', [
            'account_id' => $account->id,
            'debit' => '5000.00',
        ]);
    }

    public function test_opening_balance_import_fails_for_missing_account(): void
    {
        /** @var ImportService $importService */
        $importService = app(ImportService::class);

        $job = $importService->createJob(
            tenantId: $this->tenant->id,
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
        $importService->executeImport($job);

        $job->refresh();
        $this->assertEquals(ImportStatus::Failed, $job->status);
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
    }
}
