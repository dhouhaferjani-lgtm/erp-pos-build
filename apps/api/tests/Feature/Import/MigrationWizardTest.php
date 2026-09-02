<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Services\MigrationWizardService;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MigrationWizardTest extends TestCase
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
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
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
    }

    // === Service Tests ===

    public function test_migration_wizard_service_exists(): void
    {
        $this->assertTrue(class_exists(MigrationWizardService::class));
    }

    public function test_get_recommended_import_order(): void
    {
        /** @var MigrationWizardService $wizard */
        $wizard = app(MigrationWizardService::class);

        $order = $wizard->getRecommendedImportOrder();

        // Partners should come before products (for supplier references)
        $partnerIndex = array_search(ImportType::Partners, $order);
        $productIndex = array_search(ImportType::Products, $order);

        $this->assertLessThan($productIndex, $partnerIndex);

        // Products before opening balances
        $balanceIndex = array_search(ImportType::OpeningBalances, $order);
        $this->assertLessThan($balanceIndex, $productIndex);

        // Retired by owner ruling D4 — never offered as a step again.
        $this->assertNotContains(ImportType::StockLevels, $order);
    }

    public function test_check_dependencies_for_import_type(): void
    {
        /** @var MigrationWizardService $wizard */
        $wizard = app(MigrationWizardService::class);

        // Partners have no dependencies
        $partnerDeps = $wizard->checkDependencies($this->tenant->id, ImportType::Partners);
        $this->assertTrue($partnerDeps['can_import']);
        $this->assertEmpty($partnerDeps['missing_dependencies']);

        // Products have no hard dependencies
        $productDeps = $wizard->checkDependencies($this->tenant->id, ImportType::Products);
        $this->assertTrue($productDeps['can_import']);

        // Stock levels require products and locations
        $stockDeps = $wizard->checkDependencies($this->tenant->id, ImportType::StockLevels);
        $this->assertFalse($stockDeps['can_import']);
        $this->assertContains('products', $stockDeps['missing_dependencies']);
    }

    public function test_check_dependencies_passes_with_data(): void
    {
        // Create products and locations
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'type' => ProductType::Part,
        ]);

        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse',
            'code' => 'WH-001',
            'type' => 'warehouse',
            'is_default' => true,
            'is_active' => true,
        ]);

        /** @var MigrationWizardService $wizard */
        $wizard = app(MigrationWizardService::class);

        $stockDeps = $wizard->checkDependencies($this->tenant->id, ImportType::StockLevels);
        $this->assertTrue($stockDeps['can_import']);
    }

    public function test_get_column_mapping_suggestions(): void
    {
        /** @var MigrationWizardService $wizard */
        $wizard = app(MigrationWizardService::class);

        $sourceHeaders = ['customer_name', 'email_address', 'partner_type', 'phone_number'];
        $suggestions = $wizard->suggestColumnMapping(ImportType::Partners, $sourceHeaders);

        $this->assertArrayHasKey('name', $suggestions);
        $this->assertArrayHasKey('email', $suggestions);
        $this->assertArrayHasKey('type', $suggestions);
    }

    public function test_generate_import_template(): void
    {
        /** @var MigrationWizardService $wizard */
        $wizard = app(MigrationWizardService::class);

        $template = $wizard->generateTemplate(ImportType::Partners);

        $this->assertStringContainsString('name', $template);
        $this->assertStringContainsString('type', $template);
        $this->assertStringContainsString('email', $template);
    }

    public function test_get_migration_status(): void
    {
        // Create some data
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => PartnerType::Customer,
        ]);

        /** @var MigrationWizardService $wizard */
        $wizard = app(MigrationWizardService::class);

        $status = $wizard->getMigrationStatus($this->tenant->id);

        $this->assertArrayHasKey('partners', $status);
        $this->assertEquals(1, $status['partners']['count']);
        $this->assertTrue($status['partners']['has_data']);
    }

    // === API Tests ===

    public function test_api_returns_recommended_import_order(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/migration-wizard/order');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'type',
                        'label',
                        'description',
                    ],
                ],
            ]);
    }

    public function test_api_checks_dependencies(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/migration-wizard/dependencies/partners');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'can_import',
                    'missing_dependencies',
                    'warnings',
                ],
            ]);
    }

    public function test_api_returns_column_suggestions(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/migration-wizard/suggest-mapping', [
                'type' => 'partners',
                'headers' => ['customer_name', 'email_address', 'customer_type'],
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'suggestions',
                    'unmapped_source',
                    'unmapped_target',
                ],
            ]);
    }

    public function test_api_skips_blank_and_null_headers_without_discarding_named_suggestions(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/migration-wizard/suggest-mapping', [
                'type' => 'products',
                'headers' => ['name', '', null, 'sku'],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.suggestions.name', 'name')
            ->assertJsonPath('data.suggestions.sku', 'sku');

        $nonNullSuggestions = array_filter(
            $response->json('data.suggestions'),
            static fn (?string $source): bool => $source !== null,
        );

        $this->assertFalse(in_array('', $nonNullSuggestions, true));
    }

    public function test_real_product_headers_reserve_every_exact_match_before_fuzzy_matching(): void
    {
        /** @var MigrationWizardService $wizard */
        $wizard = app(MigrationWizardService::class);

        $headers = [
            'name',
            'sku',
            'description',
            'sale_price',
            'sale_price_incl_tax',
            'sale_price_excl_tax',
            'purchase_price',
            'margin',
            'quantity',
            'location_code',
            'placement_path',
            'category_name',
            'brand',
            'tax_rate',
            'unit',
            'barcode',
        ];

        $suggestions = $wizard->suggestColumnMapping(ImportType::Products, $headers);

        foreach ($headers as $header) {
            $this->assertSame($header, $suggestions[$header] ?? null, "Expected exact suggestion for {$header}");
        }
        $this->assertNull($suggestions['type']);

        $nonNullSuggestions = array_values(array_filter($suggestions));
        $this->assertCount(count(array_unique($nonNullSuggestions)), $nonNullSuggestions);
    }

    public function test_api_generates_template(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/migration-wizard/template/partners');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_api_returns_migration_status(): void
    {
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => PartnerType::Customer,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/migration-wizard/status');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'partners' => ['count', 'has_data'],
                    'products' => ['count', 'has_data'],
                    'stock_levels' => ['count', 'has_data'],
                    'accounts' => ['count', 'has_data'],
                ],
            ]);
    }

    public function test_unauthorized_user_cannot_access_wizard(): void
    {
        $response = $this->getJson('/api/v1/migration-wizard/order');

        $response->assertUnauthorized();
    }

    // === Parse Headers API ===

    public function test_api_parses_headers_from_comma_csv(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'products.csv',
            "name,sku,sale_price\nWidget,W-1,10.00\n"
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->post('/api/v1/migration-wizard/parse-headers', ['file' => $file], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJson(['data' => ['headers' => ['name', 'sku', 'sale_price']]]);
    }

    public function test_api_parses_headers_from_semicolon_csv(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'produits.csv',
            "name;sku;sale_price\nProduit;P-1;10,00\n"
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->post('/api/v1/migration-wizard/parse-headers', ['file' => $file], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJson(['data' => ['headers' => ['name', 'sku', 'sale_price']]]);
    }

    public function test_api_parses_headers_from_xlsx(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Name', 'SKU', 'Sale_Price'],
            ['Excel Product', 'X-1', 15.5],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'wizard_test_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        try {
            $file = new UploadedFile($path, 'products.xlsx', null, null, true);

            $response = $this->actingAs($this->user, 'sanctum')
                ->post('/api/v1/migration-wizard/parse-headers', ['file' => $file], ['Accept' => 'application/json']);

            $response->assertOk()
                ->assertJson(['data' => ['headers' => ['name', 'sku', 'sale_price']]]);
        } finally {
            @unlink($path);
        }
    }

    public function test_api_parses_headers_from_cp1252_encoded_csv(): void
    {
        $accentedHeader = 'désignation';
        $accentedValue = "Crème solaire à l'abricot é è ç";
        $cp1252Header = mb_convert_encoding($accentedHeader, 'Windows-1252', 'UTF-8');
        $cp1252Value = mb_convert_encoding($accentedValue, 'Windows-1252', 'UTF-8');
        $content = "{$cp1252Header};sku\n{$cp1252Value};P-1\n";

        $file = UploadedFile::fake()->createWithContent(
            'produits.csv',
            $content
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->post('/api/v1/migration-wizard/parse-headers', ['file' => $file], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJson(['data' => ['headers' => [$accentedHeader, 'sku'], 'row_count' => 1]]);
    }

    public function test_api_parse_headers_rejects_unsupported_file_type(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'notes.pdf',
            '%PDF-1.4 not a spreadsheet'
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->post('/api/v1/migration-wizard/parse-headers', ['file' => $file], ['Accept' => 'application/json']);

        $response->assertStatus(422);
    }

    public function test_api_parse_headers_requires_auth(): void
    {
        $response = $this->postJson('/api/v1/migration-wizard/parse-headers');

        $response->assertUnauthorized();
    }
}
