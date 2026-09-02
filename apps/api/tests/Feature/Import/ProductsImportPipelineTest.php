<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Modules\Import\Services\ImportService;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Application\Services\UnitsProvisioningService;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use Carbon\CarbonImmutable;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ProductsImportPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Products Pipeline Tenant',
            'slug' => 'products-pipeline-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Products Pipeline Company',
            'legal_name' => 'Products Pipeline Company LLC',
            'tax_id' => 'TAX-PROD-001',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
            'default_tax_rate' => '19.00',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Import User',
            'email' => 'products-import@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'type' => 'warehouse',
            'is_default' => true,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '3100',
            'name' => 'Inventory Asset',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
            'is_active' => true,
            'is_system' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '3900',
            'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity,
            'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity,
            'is_active' => true,
            'is_system' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(UnitsProvisioningService::class)->provisionForCompany($this->company);
        Storage::fake('local');
    }

    public function test_products_import_resolves_prices_and_posts_opening_stock_with_warnings(): void
    {
        $duplicate = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Duplicate Opening Product',
            'sku' => 'DUP-OPEN',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
        ]);

        StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $duplicate->id,
            'location_id' => $this->location->id,
            'movement_type' => MovementType::Opening,
            'reason' => MovementReason::OpeningBalance,
            'quantity' => '1.0000',
            'quantity_before' => '0.0000',
            'quantity_after' => '1.0000',
            'unit_cost' => '2.000000',
            'total_cost' => '2.000000',
            'reference' => 'PRE-OPENING',
            'user_id' => $this->user->id,
            'is_historical' => true,
        ]);

        $file = UploadedFile::fake()->createWithContent('products.csv', implode("\n", [
            'name,sku,type,sale_price_incl_tax,sale_price_excl_tax,margin,quantity,location_code,purchase_price,tax_rate',
            'Happy Product,HAPPY,part,7.140,,,5.0000,MAIN,3.000,',
            'No Cost Product,NOCOST,part,,,,2.0000,MAIN,,',
            'Service Product,SERV,service,,,,4.0000,MAIN,1.000,',
            'Duplicate Opening Product,DUP-OPEN,part,,,,3.0000,MAIN,2.000,',
            'HT Product,HTPRICE,part,,10.000,,0,MAIN,,',
            'Margin Conflict Product,MARGIN,part,20.000,,30,0,MAIN,10.000,',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'products',
                'options' => [
                    'price_authority' => 'ht',
                    'location_code' => 'MAIN',
                ],
            ]);

        $createResponse->assertCreated();
        $jobId = $createResponse->json('data.id');

        $executeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute");

        $executeResponse->assertOk();
        $executeResponse->assertJsonPath('data.warning_rows', 4);

        $happy = Product::where('sku', 'HAPPY')->firstOrFail();
        $this->assertSame('7.140', $happy->sale_price);
        $this->assertSame('3.000000', $happy->refresh()->cost_price);
        $this->assertSame(1, StockMovement::where('product_id', $happy->id)->where('movement_type', MovementType::Opening)->count());
        $this->assertSame('5.0000', StockLevel::where('product_id', $happy->id)->firstOrFail()->quantity);

        $htProduct = Product::where('sku', 'HTPRICE')->firstOrFail();
        $this->assertSame('11.900', $htProduct->sale_price);

        $job = ImportJob::query()->findOrFail($jobId);
        $this->assertInstanceOf(ImportJob::class, $job);
        $rows = $job->rows()->orderBy('row_number')->get()->keyBy('row_number');

        $this->assertSame('ok', $rows[1]->data['_results']['opening_stock']['opening_stock'] ?? null);
        // Gate r1 F-4: the breadcrumb names the LEVEL now, not just "not from the
        // file". This row carries no category, so the company default answered.
        $this->assertSame('company_default', $rows[5]->data['_results']['product']['tax_source'] ?? null);
        $this->assertSame('qty_without_cost', $rows[2]->warnings[0]['code'] ?? null);
        $this->assertSame('quantity_ignored_service', $rows[3]->warnings[0]['code'] ?? null);
        $this->assertSame('opening_exists', $rows[4]->warnings[0]['code'] ?? null);
        $this->assertSame('price_conflict', $rows[6]->warnings[0]['code'] ?? null);
        $this->assertSame(1, StockMovement::where('product_id', $duplicate->id)->where('movement_type', MovementType::Opening)->count());
    }

    public function test_numeric_boundary_warns_on_float_noise_and_codes_rejected_numbers_by_column(): void
    {
        $file = UploadedFile::fake()->createWithContent('numeric-boundary.csv', implode("\n", [
            'name,sku,sale_price_excl_tax,purchase_price,margin,quantity,tax_rate,unit',
            'Noise Product,NOISE-1,71.162000000000006,6.0999999999999999E-2,30.000000000000004,1.2340000000000002,19.000000000000004,pc',
            'Over Precision Product,OVER-1,71.1624,1.000,30,1,19,pc',
            'Malformed Product,BAD-1,10,not-a-number,30,1,19,pc',
        ]));

        $create = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'file' => $file,
            'type' => 'products',
            'options' => ['price_authority' => 'ht'],
        ])->assertCreated();

        $jobId = (string) $create->json('data.id');
        $job = ImportJob::query()->findOrFail($jobId);
        $rows = $job->rows()->orderBy('row_number')->get()->keyBy('row_number');
        $normalizedRow = $rows->get(1);
        $overPrecisionRow = $rows->get(2);
        $malformedRow = $rows->get(3);
        $this->assertInstanceOf(ImportRow::class, $normalizedRow);
        $this->assertInstanceOf(ImportRow::class, $overPrecisionRow);
        $this->assertInstanceOf(ImportRow::class, $malformedRow);

        $this->assertSame('71.162', $normalizedRow->data['sale_price_excl_tax'] ?? null);
        $this->assertSame('0.061', $normalizedRow->data['purchase_price'] ?? null);
        $this->assertSame('30.00', $normalizedRow->data['margin'] ?? null);
        $this->assertSame('1.2340', $normalizedRow->data['quantity'] ?? null);
        $this->assertSame('19.00', $normalizedRow->data['tax_rate'] ?? null);
        $this->assertSame('numeric_normalized', $normalizedRow->warnings[0]['code'] ?? null);
        $this->assertTrue($normalizedRow->is_valid);

        $this->assertSame('71.1624', $overPrecisionRow->data['sale_price_excl_tax'] ?? null);
        $this->assertSame('invalid_number', $overPrecisionRow->import_error_code?->value);
        $this->assertSame('sale_price_excl_tax', $overPrecisionRow->import_error_detail['column'] ?? null);
        $this->assertSame('71.1624', $overPrecisionRow->import_error_detail['raw'] ?? null);

        $this->assertSame('invalid_number', $malformedRow->import_error_code?->value);
        $this->assertSame('purchase_price', $malformedRow->import_error_detail['column'] ?? null);
        $this->assertSame('not-a-number', $malformedRow->import_error_detail['raw'] ?? null);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('import_result.imported_count', 1)
            ->assertJsonPath('data.failed_rows', 2);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data.warning_summary.numeric_normalized', 1);
    }

    public function test_real_no_barcode_fixture_imports_856_rows_and_refuses_only_three_negative_quantities(): void
    {
        $path = realpath(__DIR__.'/../../../../web/e2e-local/real-produits-nobarcode.csv');
        $this->assertIsString($path, 'The 859-row real-file-derived fixture must remain available.');
        $this->assertStringContainsString(
            '6.0999999999999999E-2',
            (string) file_get_contents($path),
            'The row-376 scientific-notation value must remain verbatim in the acceptance fixture.',
        );

        $pc = Unit::query()->where('code', 'pc')->firstOrFail();
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/uom/unit-text-mappings', [
                'source_text' => 'piece',
                'target_unit_id' => $pc->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.productCount', 0)
            ->assertJsonPath('data.importRowCount', 0)
            ->assertJsonPath('data.applied', false)
            ->assertJsonPath('data.aliasStored', true);
        $this->assertSame(1, DB::table('unit_text_mappings')->where('source_text', 'piece')->count());
        $this->assertSame(1, AuditEvent::query()
            ->where('company_id', $this->company->id)
            ->where('event_type', 'uom.unit_text_mapping_applied')
            ->count());

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/uom/unit-text-mappings', [
                'source_text' => 'piece',
                'target_unit_id' => $pc->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.applied', false)
            ->assertJsonPath('data.aliasStored', true);
        $this->assertSame(1, DB::table('unit_text_mappings')->where('source_text', 'piece')->count());
        $this->assertSame(1, AuditEvent::query()
            ->where('company_id', $this->company->id)
            ->where('event_type', 'uom.unit_text_mapping_applied')
            ->count());

        $file = new UploadedFile($path, 'real-produits-nobarcode.csv', 'text/csv', null, true);
        $create = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'file' => $file,
            'type' => 'products',
            'options' => [
                'price_authority' => 'ht',
                'duplicate_policy' => 'override',
            ],
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.total_rows', 859)
            ->assertJsonPath('data.failed_rows', 3);

        $jobId = (string) $create->json('data.id');
        $job = ImportJob::query()->findOrFail($jobId);
        $this->assertSame(0, $job->rows()->where('import_error_code', ImportErrorCode::UnitUnknown)->count());
        $this->assertSame('0.061', $job->rows()->where('row_number', 376)->firstOrFail()->data['purchase_price'] ?? null);
        foreach ([141, 160, 827] as $rowNumber) {
            $row = $job->rows()->where('row_number', $rowNumber)->firstOrFail();
            $this->assertSame('-1', $row->data['quantity'] ?? null);
            $this->assertArrayHasKey('quantity', $row->errors ?? []);
            $this->assertSame(ImportErrorCode::ValidationFailed, $row->import_error_code);
        }

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertAccepted();

        $job->refresh();
        $this->assertSame(856, $job->successful_rows);
        $this->assertSame(3, $job->failed_rows);
        $this->assertSame(856, Product::query()->count());
        $this->assertSame(0, $job->rows()->where('import_error_code', ImportErrorCode::InternalError)->count());

        $detail = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/imports/{$jobId}")->assertOk();
        $normalizedCount = $detail->json('data.warning_summary.numeric_normalized');
        $this->assertIsInt($normalizedCount);
        $this->assertSame(626, $normalizedCount);
    }

    public function test_override_reimport_recomputes_sale_price_from_ht_without_erasing_blank_purchase_price(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Harissa 200g',
            'sku' => 'HAR-200',
            'type' => ProductType::Part,
            'sale_price' => '4.165',
            'purchase_price' => '2.000',
            'tax_rate' => '19.00',
            'unit' => 'pc',
            'barcode' => '6191234567890',
        ]);
        $before = now()->subDay();
        DB::table('products')->where('id', $product->id)->update(['updated_at' => $before]);

        $file = UploadedFile::fake()->createWithContent('harissa.csv', implode("\n", [
            'name,sku,type,sale_price_excl_tax,purchase_price,tax_rate,unit,barcode',
            'Harissa 200g,HAR-200,part,3.750,,19,pc,6191234567890',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'file' => $file,
            'type' => 'products',
            'options' => [
                'price_authority' => 'ht',
                'duplicate_policy' => 'override',
            ],
        ]);
        $createResponse->assertCreated();
        $jobId = (string) $createResponse->json('data.id');

        $executeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute");

        $executeResponse->assertOk()->assertJsonPath('import_result.imported_count', 1);
        $product->refresh();
        $this->assertSame('4.462', $product->sale_price);
        $this->assertSame('2.000', $product->purchase_price);
        $updatedAt = $product->updated_at;
        $this->assertNotNull($updatedAt);
        $this->assertTrue($updatedAt->greaterThan($before));

        $row = ImportJob::query()->findOrFail($jobId)->rows()->sole();
        $this->assertSame(
            ['name', 'sku', 'type', 'sale_price_excl_tax', 'tax_rate', 'unit', 'barcode'],
            $row->data['_provided'] ?? null,
        );
        $this->assertSame('imported', $row->outcome->value);
    }

    public function test_override_purchase_price_only_preserves_existing_ttc_sale_price(): void
    {
        [$product] = $this->executeSparsePriceOverride(
            'SPARSE-PURCHASE',
            ['purchase_price'],
            ['9.000'],
        );

        $this->assertSame('9.000', $product->purchase_price);
        $this->assertSame('11.900', $product->sale_price);
    }

    public function test_override_margin_only_derives_sale_price_from_existing_purchase_price(): void
    {
        $this->company->update(['default_tax_rate' => '7.00']);

        [$product, $row] = $this->executeSparsePriceOverride(
            'SPARSE-MARGIN',
            ['margin'],
            ['25'],
            'margin',
        );

        $this->assertSame('8.000', $product->purchase_price);
        $this->assertSame('11.900', $product->sale_price);
        $this->assertSame('11.900', $row->data['sale_price'] ?? null);
    }

    public function test_override_tax_rate_only_preserves_existing_ttc_sale_price(): void
    {
        [$product] = $this->executeSparsePriceOverride(
            'SPARSE-TAX',
            ['tax_rate'],
            ['7'],
        );

        $this->assertSame('7.00', $product->tax_rate);
        $this->assertSame('8.000', $product->purchase_price);
        $this->assertSame('11.900', $product->sale_price);
    }

    public function test_override_all_blank_price_cells_preserves_existing_prices(): void
    {
        [$product, $row] = $this->executeSparsePriceOverride(
            'SPARSE-BLANKS',
            ['sale_price', 'sale_price_incl_tax', 'sale_price_excl_tax', 'purchase_price', 'margin'],
            ['', '', '', '', ''],
        );

        $this->assertSame('8.000', $product->purchase_price);
        $this->assertSame('11.900', $product->sale_price);
        $this->assertSame(['name', 'sku'], $row->data['_provided'] ?? null);
    }

    public function test_products_import_job_summarizes_unresolved_location_warnings(): void
    {
        $file = UploadedFile::fake()->createWithContent('products-without-location.csv', implode("\n", [
            'name,sku,type,quantity,purchase_price',
            'Unlocated Product One,UNLOC-1,part,2.0000,3.000',
            'Unlocated Product Two,UNLOC-2,part,4.0000,5.000',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'products',
            ])
            ->assertCreated();

        $jobId = $createResponse->json('data.id');
        $this->assertIsString($jobId);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.warning_rows', 2)
            ->assertJsonPath('data.warning_summary.location_unresolved', 2);
    }

    public function test_import_list_and_processing_show_omit_warning_summary_while_terminal_show_counts_valid_codes_per_row(): void
    {
        $file = UploadedFile::fake()->createWithContent('products-warning-summary.csv', implode("\n", [
            'name,sku,type',
            'Warning Product,WARN-1,part',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', ['file' => $file, 'type' => 'products'])
            ->assertCreated();
        $jobId = $createResponse->json('data.id');
        $this->assertIsString($jobId);

        $this->onlyRowOf($jobId)->update([
            'is_valid' => false,
            'import_error_code' => ImportErrorCode::UnitUnknown,
            'import_error_detail' => ['supplied' => 'piece', 'accepted' => ['pc', 'kg']],
            'warnings' => [
                ['code' => 'price_conflict', 'detail' => 'TTC conflicts with HT'],
                ['code' => 'price_conflict', 'detail' => 'Margin conflicts with HT'],
                ['detail' => 'Legacy warning without a code'],
                'Legacy scalar warning',
                ['code' => '', 'detail' => 'Legacy warning with an empty code'],
                ['code' => 42, 'detail' => 'Legacy warning with a non-string code'],
            ],
        ]);

        ImportJob::query()->findOrFail($jobId)->update(['status' => ImportStatus::Importing]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $list = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/imports')
            ->assertOk()
            ->assertJsonPath('data.0.warning_rows', 1)
            ->assertJsonPath('data.0.warning_summary', null)
            ->assertJsonPath('data.0.error_summary', null);
        unset($list);

        $listRowQueries = array_values(array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains($query['query'], 'import_rows'),
        ));
        DB::disableQueryLog();
        foreach ($listRowQueries as $query) {
            $this->assertStringNotContainsString(
                'import_error_detail',
                $query['query'],
                'The paginated import list must never build a row-derived unit error summary.',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/select\s+["`]?data["`]?/i',
                $query['query'],
                'The paginated import list must never hydrate staged row payloads.',
            );
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data.warning_rows', 1)
            ->assertJsonPath('data.warning_summary', null)
            ->assertJsonPath('data.error_summary.unknown_units.0.text', 'piece')
            ->assertJsonPath('data.error_summary.unknown_units.0.count', 1);

        $detailQueries = array_values(array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains($query['query'], 'import_error_detail'),
        ));
        DB::disableQueryLog();
        $this->assertCount(1, $detailQueries, 'A single-job payload must summarize unit errors with one aggregate query.');
        $this->assertMatchesRegularExpression('/count\(\*\).*group by.*import_error_detail/is', $detailQueries[0]['query']);
        $this->assertDoesNotMatchRegularExpression('/select\s+["`]?data["`]?/i', $detailQueries[0]['query']);

        ImportJob::query()->findOrFail($jobId)->update(['status' => ImportStatus::Completed]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data.warning_rows', 1)
            ->assertJsonPath('data.warning_summary', ['price_conflict' => 1]);
    }

    public function test_products_import_uses_job_location_option_when_rows_have_no_location_code(): void
    {
        $file = UploadedFile::fake()->createWithContent('products-option-location.csv', implode("\n", [
            'name,sku,type,quantity,purchase_price',
            'Option Location Product,OPTION-LOC,part,3.0000,2.500',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'products',
                'options' => ['location_code' => 'MAIN'],
            ])
            ->assertCreated();
        $jobId = $createResponse->json('data.id');
        $this->assertIsString($jobId);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.warning_rows', 0);

        $product = Product::where('sku', 'OPTION-LOC')->firstOrFail();
        $movement = StockMovement::where('product_id', $product->id)
            ->where('movement_type', MovementType::Opening)
            ->firstOrFail();

        $this->assertSame($this->location->id, $movement->location_id);
        $this->assertSame(
            '3.0000',
            StockLevel::where('product_id', $product->id)
                ->where('location_id', $this->location->id)
                ->firstOrFail()
                ->quantity,
        );
    }

    public function test_per_row_location_code_overrides_the_job_location_option(): void
    {
        $branch = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Branch Warehouse',
            'code' => 'BRANCH',
            'type' => 'warehouse',
            'is_default' => false,
            'is_active' => true,
        ]);
        $file = UploadedFile::fake()->createWithContent('products-row-location.csv', implode("\n", [
            'name,sku,type,quantity,purchase_price,location_code',
            'Row Location Product,ROW-LOC,part,4.0000,3.500,BRANCH',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'products',
                'options' => ['location_code' => 'MAIN'],
            ])
            ->assertCreated();
        $jobId = $createResponse->json('data.id');
        $this->assertIsString($jobId);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.warning_rows', 0);

        $product = Product::where('sku', 'ROW-LOC')->firstOrFail();
        $movement = StockMovement::where('product_id', $product->id)
            ->where('movement_type', MovementType::Opening)
            ->firstOrFail();

        $this->assertSame($branch->id, $movement->location_id);
        $this->assertSame(
            0,
            StockLevel::where('product_id', $product->id)
                ->where('location_id', $this->location->id)
                ->count(),
        );
    }

    public function test_batch_tracked_product_quantity_creates_opening_movement_with_default_lot(): void
    {
        // Parapharmacy verticals default requires_batch_tracking=true for every
        // product — opening stock must still import (the posting service backs
        // it with a DEFAULT lot), otherwise quantity import is a no-op for the
        // whole vertical.
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Lot Tracked Product',
            'sku' => 'LOT-1',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 365,
        ]);

        $file = UploadedFile::fake()->createWithContent('products-lot.csv', implode("\n", [
            'name,sku,type,quantity,location_code,purchase_price',
            'Lot Tracked Product,LOT-1,part,6.0000,MAIN,2.500',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'products',
            ]);
        $createResponse->assertCreated();
        $jobId = $createResponse->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.warning_rows', 0);

        $product = Product::where('sku', 'LOT-1')->firstOrFail();
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->where('movement_type', MovementType::Opening)->count());
        $this->assertSame('6.0000', StockLevel::where('product_id', $product->id)->firstOrFail()->quantity);
        $this->assertSame(
            1,
            Batch::where('product_id', $product->id)->count(),
            'batch-tracked opening stock must be backed by a default lot'
        );

        $row = ImportJob::query()->whereKey($jobId)->firstOrFail()->rows()->firstOrFail();
        $this->assertSame('ok', $row->data['_results']['opening_stock']['opening_stock'] ?? null);
        $this->assertNull($row->warnings);
    }

    /**
     * Campaign W4-1 — the Products import carries the opening stock for the
     * launch tenant, and the launch tenant is a parapharmacy: every product is
     * batch-tracked. Before this lane the sheet had NO expiry column at all, so
     * every opening lot took `cutover + 365` — the earliest date on the product,
     * which the FEFO guards then compelled the operator to ship first.
     */
    public function test_the_optional_expiry_date_column_dates_the_opening_lot(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Dated Lot Product',
            'sku' => 'LOT-DATED',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
            'requires_batch_tracking' => true,
        ]);

        $file = UploadedFile::fake()->createWithContent('products-expiry.csv', implode("\n", [
            'name,sku,type,quantity,location_code,purchase_price,expiry_date',
            'Dated Lot Product,LOT-DATED,part,6.0000,MAIN,2.500,2027-09-30',
        ]));

        $jobId = $this->runImport($file);

        $this->assertSame(
            '2027-09-30',
            $this->lotExpiryForSku('LOT-DATED'),
            'the expiry printed on the sheet must reach the opening lot',
        );
        $this->assertSame('ok', $this->onlyRowOf($jobId)->data['_results']['opening_stock']['opening_stock'] ?? null);
    }

    public function test_an_omitted_expiry_column_leaves_the_opening_lot_undated_rather_than_inventing_one(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Undated Lot Product',
            'sku' => 'LOT-UNDATED',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
            'requires_batch_tracking' => true,
            // No default_shelf_life_days: exactly the wave-4 shape, where the
            // Products import had no expiry column and nothing configured one.
        ]);

        $file = UploadedFile::fake()->createWithContent('products-no-expiry.csv', implode("\n", [
            'name,sku,type,quantity,location_code,purchase_price',
            'Undated Lot Product,LOT-UNDATED,part,6.0000,MAIN,2.500',
        ]));

        $this->runImport($file);

        $batch = Batch::where('product_id', Product::where('sku', 'LOT-UNDATED')->firstOrFail()->id)->firstOrFail();
        $this->assertNull(
            $batch->expiry_date,
            'W4-1: with no expiry column and no configured shelf life, the lot must record NO expiry — not cutover + 365',
        );
    }

    public function test_a_malformed_expiry_cell_is_refused_with_its_row_rather_than_reinterpreted(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Bad Expiry Product',
            'sku' => 'LOT-BADEXP',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
            'requires_batch_tracking' => true,
        ]);

        // 03/04/2027 is 3 April or 4 March depending on the reader. Guessing it
        // would put a wrong date on a parapharmacy lot, so it must be refused.
        $file = UploadedFile::fake()->createWithContent('products-bad-expiry.csv', implode("\n", [
            'name,sku,type,quantity,location_code,purchase_price,expiry_date',
            'Bad Expiry Product,LOT-BADEXP,part,6.0000,MAIN,2.500,03/04/2027',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', ['file' => $file, 'type' => 'products']);
        $createResponse->assertCreated();
        $jobId = $createResponse->json('data.id');

        // The row is refused at VALIDATION, and since it is the only row the whole
        // job refuses rather than importing a product with a guessed expiry.
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertStatus(422)
            ->assertJsonPath('failed_rows', 1)
            ->assertJsonPath('valid_rows', 0);

        $row = $this->onlyRowOf($jobId);
        $this->assertNotNull($row->errors);
        $this->assertStringContainsString(
            'expiry_date',
            json_encode($row->errors, JSON_THROW_ON_ERROR),
            'the refusal must name the offending column so the operator can fix that cell',
        );

        $this->assertSame(
            0,
            $this->lotCountForSku('LOT-BADEXP'),
            'a refused row must not have opened stock',
        );
    }

    /**
     * Gate r1 [CRITICAL] — the WIZARD path. `runImport()` above posts NO
     * `column_mapping`, which is `applyColumnMapping()`'s `$mapping === null`
     * early return — the only path an operator never takes. The wizard always
     * posts a mapping, and `applyColumnMapping()` keeps ONLY mapped targets, so
     * this is the path where a missing target silently strips the column.
     */
    public function test_the_expiry_column_survives_the_wizards_column_mapping(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Mapped Lot Product',
            'sku' => 'LOT-MAPPED',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
            'requires_batch_tracking' => true,
        ]);

        // Headers as a real sheet carries them, mapped onto the target names —
        // exactly what ImportWizardPage builds from TARGET_COLUMNS.
        $file = UploadedFile::fake()->createWithContent('products-mapped.csv', implode("\n", [
            'nom,reference,type,qte,depot,cout,peremption',
            'Mapped Lot Product,LOT-MAPPED,part,6.0000,MAIN,2.500,2027-09-30',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'products',
                'column_mapping' => json_encode([
                    'nom' => 'name',
                    'reference' => 'sku',
                    'type' => 'type',
                    'qte' => 'quantity',
                    'depot' => 'location_code',
                    'cout' => 'purchase_price',
                    'peremption' => 'expiry_date',
                ], JSON_THROW_ON_ERROR),
            ]);
        $createResponse->assertCreated();
        $jobId = $createResponse->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.failed_rows', 0);

        $this->assertSame(
            '2027-09-30',
            $this->lotExpiryForSku('LOT-MAPPED'),
            'a mapped expiry_date must reach the lot; applyColumnMapping() drops every unmapped target',
        );
    }

    /**
     * RULED policy (gate r1): a PAST expiry on an opening lot is ALLOWED — a
     * parapharmacy may legitimately open with expired stock in order to scrap it —
     * but it is never silent. The lot is born EXPIRED, so FEFO and the transfer
     * guard treat it as unsellable; an operator who typed the wrong year has to
     * see that on the row, not discover it at the first refused issue.
     */
    public function test_a_past_expiry_opens_the_stock_and_warns_instead_of_refusing(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Expired Stock Product',
            'sku' => 'LOT-PAST',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
            'requires_batch_tracking' => true,
        ]);

        $past = CarbonImmutable::today()->subMonths(2)->toDateString();
        $file = UploadedFile::fake()->createWithContent('products-past.csv', implode("\n", [
            'name,sku,type,quantity,location_code,purchase_price,expiry_date',
            "Expired Stock Product,LOT-PAST,part,6.0000,MAIN,2.500,{$past}",
        ]));

        $jobId = $this->runImport($file);

        $batch = Batch::where('product_id', Product::where('sku', 'LOT-PAST')->firstOrFail()->id)->firstOrFail();
        $this->assertSame($past, $batch->expiry_date?->toDateString(), 'the past date is honoured, not silently dropped');
        $this->assertTrue($batch->isExpired(), 'and the lot is genuinely expired — which is why the row must warn');

        $this->assertSame('ok', $this->onlyRowOf($jobId)->data['_results']['opening_stock']['opening_stock'] ?? null, 'the stock still opens');
        $this->assertContains(
            'expiry_in_past',
            $this->warningCodesOf($jobId),
            'a past expiry must be reported on the row, not left for the operator to discover at the first refusal',
        );
    }

    /**
     * Gate r1 — one DEFAULT lot exists per product across ALL locations, so the
     * SECOND row of a multi-location opening for the same SKU meets a lot that
     * already exists. Its expiry used to be discarded while the row still reported
     * `ok`: the same lost-fact defect this lane exists to remove, one layer up.
     */
    public function test_a_second_location_row_fills_an_undated_lot_and_reports_a_conflict_when_it_disagrees(): void
    {
        Location::create([
            'company_id' => $this->company->id,
            'code' => 'ANNEX',
            'name' => 'Annex',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Two Site Product',
            'sku' => 'LOT-TWOSITE',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
            'requires_batch_tracking' => true,
        ]);

        // Row 1 supplies NO expiry (lot minted undated); row 2 supplies one.
        // Set-once must FILL it rather than drop it on the floor.
        $file = UploadedFile::fake()->createWithContent('products-twosite.csv', implode("\n", [
            'name,sku,type,quantity,location_code,purchase_price,expiry_date',
            'Two Site Product,LOT-TWOSITE,part,4.0000,MAIN,2.500,',
            'Two Site Product,LOT-TWOSITE,part,3.0000,ANNEX,2.500,2027-05-31',
        ]));

        $this->runImport($file);

        $product = Product::where('sku', 'LOT-TWOSITE')->firstOrFail();
        $this->assertSame(
            '2027-05-31',
            $this->lotExpiryForSku('LOT-TWOSITE'),
            'set-once: an expiry supplied by a later row must FILL an undated lot',
        );

        // Now a third opening that disagrees: the existing date wins (rewriting a
        // lot that already holds stock and movements would be a silent ledger
        // correction) and the row says so.
        Location::create([
            'company_id' => $this->company->id,
            'code' => 'THIRD',
            'name' => 'Third',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        $conflicting = UploadedFile::fake()->createWithContent('products-conflict.csv', implode("\n", [
            'name,sku,type,quantity,location_code,purchase_price,expiry_date',
            'Two Site Product,LOT-TWOSITE,part,2.0000,THIRD,2.500,2028-01-31',
        ]));

        $jobId = $this->runImport($conflicting);

        $this->assertSame(
            '2027-05-31',
            $this->lotExpiryForSku('LOT-TWOSITE'),
            'an existing expiry is never overwritten',
        );
        $this->assertContains(
            'expiry_conflict_existing_lot',
            $this->warningCodesOf($jobId),
            'and the operator is told their date was not used',
        );
    }

    public function test_an_expiry_on_a_non_batch_tracked_product_is_reported_not_silently_dropped(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Plain Product',
            'sku' => 'PLAIN-1',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
            'requires_batch_tracking' => false,
        ]);

        $file = UploadedFile::fake()->createWithContent('products-plain.csv', implode("\n", [
            'name,sku,type,quantity,location_code,purchase_price,expiry_date',
            'Plain Product,PLAIN-1,part,6.0000,MAIN,2.500,2027-09-30',
        ]));

        $jobId = $this->runImport($file);

        $this->assertSame(0, $this->lotCountForSku('PLAIN-1'));
        $this->assertContains(
            'expiry_ignored_not_batch_tracked',
            $this->warningCodesOf($jobId),
            'validation accepted the date and the preview echoed it — dropping it silently here would leave the '
            .'operator believing an expiry was recorded',
        );
    }

    public function test_the_unparseable_refusal_carries_a_machine_readable_code(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Coded Refusal Product',
            'sku' => 'LOT-CODE',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
            'requires_batch_tracking' => true,
        ]);

        $file = UploadedFile::fake()->createWithContent('products-code.csv', implode("\n", [
            'name,sku,type,quantity,location_code,purchase_price,expiry_date',
            'Coded Refusal Product,LOT-CODE,part,6.0000,MAIN,2.500,03/04/2027',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', ['file' => $file, 'type' => 'products']);
        $createResponse->assertCreated();
        $jobId = $createResponse->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertStatus(422);

        $errors = $this->onlyRowOf($jobId)->errors;
        $this->assertStringContainsString(
            'expiry_unparseable',
            json_encode($errors, JSON_THROW_ON_ERROR),
            'row errors carry no separate code channel, so the refusal code rides the message and must survive into '
            .'the failed-rows export and the result workbook',
        );
    }

    /**
     * The job's single row, narrowed.
     *
     * `ImportJob::findOrFail()` is typed `Model|Collection`, so the chained
     * `->rows()` the rest of this file uses is untyped to static analysis. The
     * W4-1 cases go through here instead of adding more of that idiom.
     */
    /**
     * The product's single lot expiry AS STORED, or null when it records none.
     *
     * Read off the table rather than through the model's date cast: this asserts
     * what the import WROTE, and the cast would hide the difference between NULL
     * and a date the lot never received.
     */
    private function lotExpiryForSku(string $sku): ?string
    {
        $productId = Product::query()->where('sku', $sku)->value('id');
        $value = DB::table('product_batches')->where('product_id', $productId)->value('expiry_date');

        return $value === null ? null : substr((string) $value, 0, 10);
    }

    private function lotCountForSku(string $sku): int
    {
        $productId = Product::query()->where('sku', $sku)->value('id');

        return (int) DB::table('product_batches')->where('product_id', $productId)->count();
    }

    private function onlyRowOf(string $jobId): ImportRow
    {
        /** @var ImportJob $job */
        $job = ImportJob::query()->findOrFail($jobId);

        /** @var ImportRow $row */
        $row = $job->rows()->firstOrFail();

        return $row;
    }

    /**
     * @return list<string> The warning codes recorded on the job's single row.
     */
    private function warningCodesOf(string $jobId): array
    {
        return array_map(
            static fn (array $warning): string => (string) $warning['code'],
            $this->onlyRowOf($jobId)->warnings ?? [],
        );
    }

    private function runImport(UploadedFile $file): string
    {
        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', ['file' => $file, 'type' => 'products']);
        $createResponse->assertCreated();
        $jobId = (string) $createResponse->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.failed_rows', 0);

        return $jobId;
    }

    public function test_missing_category_is_created_linked_and_reported_instead_of_silently_dropped(): void
    {
        // W2-3: the products import used to LOOK UP category_name and drop it on
        // miss. On a day-one tenant `categories` is empty and there is no
        // categories ImportType (ImportType.php:125 lists category_name as a
        // PRODUCTS column), so lookup-only meant every category was discarded.
        $file = UploadedFile::fake()->createWithContent('products-cat.csv', implode("\n", [
            'name,sku,type,category_name',
            'Creme hydratante Bebe 200ml,CREM-BEBE_200,part,Soins Bebe',
            'Lingettes Bebe,LING-BEBE_72,part,Soins Bebe',
            'Elixir apaisant,ELIX-APAI_50,part,Aromatherapie',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', ['file' => $file, 'type' => 'products']);
        $createResponse->assertCreated();
        $jobId = $createResponse->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.successful_rows', 3)
            ->assertJsonPath('data.failed_rows', 0);

        $soins = Category::where('company_id', $this->company->id)->where('name', 'Soins Bebe')->first();
        $this->assertNotNull($soins, 'category_name must create the missing category, not drop it');
        $aroma = Category::where('company_id', $this->company->id)->where('name', 'Aromatherapie')->first();
        $this->assertNotNull($aroma);
        $this->assertSame(2, Category::where('company_id', $this->company->id)->count());

        $this->assertSame($soins->id, Product::where('sku', 'CREM-BEBE_200')->firstOrFail()->category_id);
        $this->assertSame($soins->id, Product::where('sku', 'LING-BEBE_72')->firstOrFail()->category_id);
        $this->assertSame($aroma->id, Product::where('sku', 'ELIX-APAI_50')->firstOrFail()->category_id);

        $rows = ImportJob::query()->whereKey($jobId)->firstOrFail()->rows()->orderBy('row_number')->get()->keyBy('row_number');

        // Row 1 created the category -> reported. Row 2 reused it -> matched, no warning.
        $this->assertSame('category_created', $rows[1]->warnings[0]['code'] ?? null);
        $this->assertStringContainsString('Soins Bebe', (string) ($rows[1]->warnings[0]['detail'] ?? ''));
        $this->assertSame('created', $rows[1]->data['_results']['product']['category'] ?? null);
        $this->assertNull($rows[2]?->warnings);
        $this->assertSame('matched', $rows[2]->data['_results']['product']['category'] ?? null);
        $this->assertSame('category_created', $rows[3]->warnings[0]['code'] ?? null);

        // ...and it reaches the operator's result workbook, which is the only
        // artefact they keep after the wizard closes.
        $workbook = $this->actingAs($this->user, 'sanctum')
            ->get("/api/v1/imports/{$jobId}/result-workbook");
        $workbook->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'w23-workbook-');
        $this->assertIsString($path);
        file_put_contents($path, $workbook->streamedContent());
        $spreadsheet = IOFactory::load($path);
        unlink($path);

        $imported = $spreadsheet->getSheetByName('Imported');
        $this->assertNotNull($imported);
        $cells = json_encode($imported->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($cells);
        $this->assertStringContainsString('category_created', $cells);
        $this->assertStringContainsString('Soins Bebe', $cells);
    }

    /**
     * W2-5 / C-23(iii). Two defects on the same line of the products import:
     *
     *  1. `ImportService::importProduct()` pre-sets `tax_rate` from
     *     `getDefaultTaxForNewProduct($company)` with NO category, so the
     *     category-aware branch further down in `ProductService::upsert()` is
     *     already satisfied by the time the row reaches it. A category that
     *     carries its own `default_tax_rate` never applied to imported products.
     *  2. Nothing on the import path ever set `default_tax_configuration_id`, so
     *     every imported product reached the product screen with a BLANK tax
     *     selector even though the company has a default configuration from
     *     provisioning.
     */
    public function test_imported_products_inherit_the_category_rate_and_a_default_tax_configuration(): void
    {
        [$tva19, $tva7] = $this->seedTunisianVatConfigurations();

        Category::create([
            'company_id' => $this->company->id,
            'name' => 'Medicaments',
            'slug' => 'medicaments',
            'default_tax_rate' => '7.00',
            'default_tax_configuration_id' => $tva7->id,
            'is_active' => true,
        ]);

        $this->runProductImport([
            'name,sku,type,category_name,sale_price_incl_tax',
            'Paracetamol 500mg,PARA-500,part,Medicaments,10.000',
            'Brosse a dents,BROS-01,part,,10.000',
        ], 2);

        $categorised = Product::where('sku', 'PARA-500')->firstOrFail();
        $this->assertDecimalEquals(
            '7.00',
            $categorised->tax_rate,
            'A product whose category carries its own default rate must import at that rate, not the company default.'
        );
        $this->assertSame(
            $tva7->id,
            $categorised->default_tax_configuration_id,
            'and it must carry the category tax configuration so the product screen selector is not blank.'
        );

        $uncategorised = Product::where('sku', 'BROS-01')->firstOrFail();
        $this->assertDecimalEquals('19.00', $uncategorised->tax_rate);
        $this->assertSame(
            $tva19->id,
            $uncategorised->default_tax_configuration_id,
            'A product with no category falls back to the COMPANY default configuration, still not null.'
        );
    }

    /**
     * Gate r1 F-3(1). The coherence guard's FAILING direction, which nothing
     * exercised: a category rate that agrees with NO available configuration
     * must leave `default_tax_configuration_id` NULL rather than reach for the
     * company's. A blank selector is a question; a wrong one is a wrong tax.
     */
    public function test_a_category_rate_matching_no_configuration_leaves_the_selector_blank(): void
    {
        [$tva19] = $this->seedTunisianVatConfigurations();

        Category::create([
            'company_id' => $this->company->id,
            'name' => 'Cosmetiques',
            'slug' => 'cosmetiques',
            // 13 % is a real TN rate, but no 13 % configuration exists here.
            'default_tax_rate' => '13.00',
            'default_tax_configuration_id' => null,
            'is_active' => true,
        ]);

        $this->runProductImport([
            'name,sku,type,category_name,sale_price_incl_tax',
            'Creme solaire,CREM-SOL,part,Cosmetiques,10.000',
        ], 1);

        $product = Product::where('sku', 'CREM-SOL')->firstOrFail();

        $this->assertDecimalEquals('13.00', $product->tax_rate);
        $this->assertNull(
            $product->default_tax_configuration_id,
            'No configuration states 13 %, so none may be stored — least of all the company 19 % one.'
        );
        $this->assertNotSame($tva19->id, $product->default_tax_configuration_id);
    }

    /**
     * Gate r1 F-3(2). Re-import idempotency for the configuration: a rate that
     * has not moved must leave the operator's chosen configuration exactly where
     * it was.
     */
    public function test_a_re_import_at_the_same_rate_does_not_clobber_the_operators_configuration(): void
    {
        [, $tva7] = $this->seedTunisianVatConfigurations();

        Category::create([
            'company_id' => $this->company->id,
            'name' => 'Medicaments',
            'slug' => 'medicaments',
            'default_tax_rate' => '7.00',
            'default_tax_configuration_id' => $tva7->id,
            'is_active' => true,
        ]);

        $rows = [
            'name,sku,type,category_name,tax_rate,sale_price_incl_tax',
            'Paracetamol 500mg,PARA-500,part,Medicaments,7.00,10.000',
        ];

        $this->runProductImport($rows, 1);
        $first = Product::where('sku', 'PARA-500')->firstOrFail();
        $this->assertSame($tva7->id, $first->default_tax_configuration_id);

        $this->runProductImport($rows, 1);

        $second = Product::where('sku', 'PARA-500')->firstOrFail();
        $this->assertSame($first->id, $second->id, 'Pre-condition: the re-import updates the same product.');
        $this->assertSame(
            $tva7->id,
            $second->default_tax_configuration_id,
            'A re-import at an unchanged rate must leave the configuration alone.'
        );
    }

    /**
     * Gate r1 F-1 [CRITICAL] + F-3(3). The coherence guard used to run ONLY when
     * the product had no configuration, while `tax_rate` was rewritten on every
     * write. So a second import at a different rate left the first import's
     * configuration standing beside a rate it disagrees with — and
     * `DocumentLineTaxResolver` prefers the configuration while the POS seals
     * `products.tax_rate`, so the same product taxed at 7 % on a document line
     * and 19 % at the till. That is an executable N-1.
     *
     * The rule now: coherence is evaluated on EVERY write. A configuration that
     * disagrees with the incoming rate is re-resolved from category -> company
     * for the NEW rate, and nulled when nothing matches. Never left stale.
     */
    public function test_a_re_import_at_a_changed_rate_re_resolves_the_configuration_instead_of_leaving_it_stale(): void
    {
        [$tva19, $tva7] = $this->seedTunisianVatConfigurations();

        Category::create([
            'company_id' => $this->company->id,
            'name' => 'Medicaments',
            'slug' => 'medicaments',
            'default_tax_rate' => '7.00',
            'default_tax_configuration_id' => $tva7->id,
            'is_active' => true,
        ]);

        $this->runProductImport([
            'name,sku,type,category_name,tax_rate,sale_price_incl_tax',
            'Paracetamol 500mg,PARA-500,part,Medicaments,7.00,10.000',
        ], 1);

        $this->assertSame($tva7->id, Product::where('sku', 'PARA-500')->firstOrFail()->default_tax_configuration_id);

        // The ordinary onboarding loop: the same file re-sent with a corrected rate.
        $this->runProductImport([
            'name,sku,type,category_name,tax_rate,sale_price_incl_tax',
            'Paracetamol 500mg,PARA-500,part,Medicaments,19.00,10.000',
        ], 1);

        $product = Product::where('sku', 'PARA-500')->firstOrFail();

        $this->assertDecimalEquals('19.00', $product->tax_rate, 'Pre-condition: the rate moved.');
        $this->assertNotSame(
            $tva7->id,
            $product->default_tax_configuration_id,
            'A 7 % configuration must not survive beside a 19 % rate — that is the N-1 shape.'
        );
        $this->assertSame(
            $tva19->id,
            $product->default_tax_configuration_id,
            'and the 19 % configuration is available, so it is re-resolved rather than nulled.'
        );
    }

    /**
     * The other half of F-1: when the changed rate matches NOTHING, the stale
     * configuration is nulled rather than retained.
     */
    public function test_a_re_import_at_a_rate_no_configuration_states_nulls_the_configuration(): void
    {
        [, $tva7] = $this->seedTunisianVatConfigurations();

        Category::create([
            'company_id' => $this->company->id,
            'name' => 'Medicaments',
            'slug' => 'medicaments',
            'default_tax_rate' => '7.00',
            'default_tax_configuration_id' => $tva7->id,
            'is_active' => true,
        ]);

        $this->runProductImport([
            'name,sku,type,category_name,tax_rate,sale_price_incl_tax',
            'Paracetamol 500mg,PARA-500,part,Medicaments,7.00,10.000',
        ], 1);

        $this->runProductImport([
            'name,sku,type,category_name,tax_rate,sale_price_incl_tax',
            'Paracetamol 500mg,PARA-500,part,Medicaments,13.00,10.000',
        ], 1);

        $product = Product::where('sku', 'PARA-500')->firstOrFail();

        $this->assertDecimalEquals('13.00', $product->tax_rate);
        $this->assertNull(
            $product->default_tax_configuration_id,
            'Nothing states 13 %, so the stale 7 % configuration must be cleared, not kept.'
        );
    }

    /**
     * Gate r1 F-2 [IMPORTANT]. A category may state its tax as a CONFIGURATION
     * with `default_tax_rate` left NULL — `CategoryController` stores the two
     * columns independently and syncs neither. The rate ladder read only
     * `default_tax_rate`, so such a category was skipped entirely: the product
     * imported at the COMPANY rate and then, because the company configuration
     * agreed with that company rate, landed with a confident 19 % selector in a
     * category the operator had marked 7 %.
     */
    public function test_a_category_that_states_its_tax_only_as_a_configuration_still_drives_the_rate(): void
    {
        [, $tva7] = $this->seedTunisianVatConfigurations();

        Category::create([
            'company_id' => $this->company->id,
            'name' => 'Medicaments',
            'slug' => 'medicaments',
            'default_tax_rate' => null,
            'default_tax_configuration_id' => $tva7->id,
            'is_active' => true,
        ]);

        $this->runProductImport([
            'name,sku,type,category_name,sale_price_incl_tax',
            'Paracetamol 500mg,PARA-500,part,Medicaments,10.000',
        ], 1);

        $product = Product::where('sku', 'PARA-500')->firstOrFail();

        $this->assertDecimalEquals(
            '7.00',
            $product->tax_rate,
            "The category's configuration IS its rate statement; the company default must not override it."
        );
        $this->assertSame($tva7->id, $product->default_tax_configuration_id);
    }

    /**
     * Gate r2 NEW-1 [IMPORTANT]. The percent gate used to cap the FILE's textual
     * scale at two decimals, mirroring a ceiling that exists on the API path
     * (`CreateProductRequest`) but NOT on this one: `ImportType::Products` gives
     * `tax_rate` only `numeric|min:0|max:100` — no percent regex, unlike its
     * `margin` sibling — and `NumericFieldNormalizer` decides "percent field" by
     * that regex being present, so it passes `19.000` through untouched.
     *
     * A TND sheet formats its whole numeric block to 3 decimals (every price
     * column already reads `10.000`), so `19.000` is the ORDINARY shape of that
     * cell — and it was returning null before the candidate ladder was reached,
     * importing with the blank selector this lane exists to remove.
     *
     * Textual scale is not the question. `19.000` and `19.00` are the same rate:
     * both land as `19.00` in `decimal(5,2)` and `bccomp(…, 2)` says so. The gate
     * is only there to keep exponent forms away from bcmath, which it still does.
     */
    public function test_a_three_decimal_rate_cell_still_resolves_a_configuration(): void
    {
        [$tva19] = $this->seedTunisianVatConfigurations();

        $this->runProductImport([
            'name,sku,type,tax_rate,sale_price_incl_tax',
            'Brosse a dents,BROS-01,part,19.000,10.000',
        ], 1);

        $product = Product::where('sku', 'BROS-01')->firstOrFail();

        $this->assertDecimalEquals('19.00', $product->tax_rate);
        $this->assertSame(
            $tva19->id,
            $product->default_tax_configuration_id,
            'A 3-decimal cell states the same rate as a 2-decimal one; it must resolve the same configuration.'
        );
    }

    /**
     * Gate r2 NEW-1, the half that matters most to an import lane: re-import
     * idempotency must not depend on cell FORMATTING. Run 1 at `19.00` set the
     * configuration; run 2 of the same file re-exported at `19.000` cleared it,
     * silently — the rate never moved, and the result workbook carries no reason
     * for a nulled configuration.
     */
    public function test_a_re_import_that_reformats_the_rate_to_three_decimals_keeps_the_configuration(): void
    {
        [$tva19] = $this->seedTunisianVatConfigurations();

        $this->runProductImport([
            'name,sku,type,tax_rate,sale_price_incl_tax',
            'Brosse a dents,BROS-01,part,19.00,10.000',
        ], 1);

        $this->assertSame($tva19->id, Product::where('sku', 'BROS-01')->firstOrFail()->default_tax_configuration_id);

        // Same file, same rate, exported by a tool that pads to the currency scale.
        $this->runProductImport([
            'name,sku,type,tax_rate,sale_price_incl_tax',
            'Brosse a dents,BROS-01,part,19.000,10.000',
        ], 1);

        $product = Product::where('sku', 'BROS-01')->firstOrFail();

        $this->assertDecimalEquals('19.00', $product->tax_rate, 'Pre-condition: the rate did not move.');
        $this->assertSame(
            $tva19->id,
            $product->default_tax_configuration_id,
            'Re-formatting a cell is not a rate change; the agreeing configuration must survive it.'
        );
    }

    /**
     * Gate r1 F-4 [MINOR]. `tax_source` is the row's only breadcrumb about where
     * its rate came from, and it said `default` for a category-derived rate —
     * wrong for exactly the case this lane exists to fix.
     */
    public function test_the_row_records_which_level_of_the_ladder_supplied_the_rate(): void
    {
        [, $tva7] = $this->seedTunisianVatConfigurations();

        Category::create([
            'company_id' => $this->company->id,
            'name' => 'Medicaments',
            'slug' => 'medicaments',
            'default_tax_rate' => '7.00',
            'default_tax_configuration_id' => $tva7->id,
            'is_active' => true,
        ]);

        $jobId = $this->runProductImport([
            'name,sku,type,category_name,sale_price_incl_tax',
            'Paracetamol 500mg,PARA-500,part,Medicaments,10.000',
            'Brosse a dents,BROS-01,part,,10.000',
            'Sirop,SIRO-01,part,Medicaments,10.000',
        ], 3);

        $rows = ImportJob::query()->whereKey($jobId)->firstOrFail()->rows()->orderBy('row_number')->get()->keyBy('row_number');

        $this->assertSame('category_default', $rows[1]->data['_results']['product']['tax_source'] ?? null);
        $this->assertSame('company_default', $rows[2]->data['_results']['product']['tax_source'] ?? null);
        $this->assertSame('category_default', $rows[3]->data['_results']['product']['tax_source'] ?? null);
    }

    public function test_re_importing_the_same_categories_reuses_them_without_duplicates_or_warnings(): void
    {
        $existing = Category::create([
            'company_id' => $this->company->id,
            'name' => 'Hygiene',
        ]);

        $rowsCsv = [
            'name,sku,type,category_name',
            'Gel douche,GEL-CAVA_500,part,Hygiene',
            // Slug-equivalent spelling of the SAME category: must match, never
            // collide on the unique (company_id, slug) index.
            'Savon doux,SAVO-DOUX_100,part,hygiene',
        ];

        foreach ([1, 2] as $pass) {
            $file = UploadedFile::fake()->createWithContent("products-cat-{$pass}.csv", implode("\n", $rowsCsv));

            $createResponse = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/v1/imports', ['file' => $file, 'type' => 'products']);
            $createResponse->assertCreated();
            $jobId = $createResponse->json('data.id');

            $this->actingAs($this->user, 'sanctum')
                ->postJson("/api/v1/imports/{$jobId}/execute")
                ->assertOk()
                ->assertJsonPath('data.failed_rows', 0)
                // Row 1 hits the category by exact name -> silent. Row 2 spells it
                // differently and merges on the slug -> reported (gate r1 finding 3).
                ->assertJsonPath('data.warning_rows', 1);

            $this->assertSame(
                1,
                Category::where('company_id', $this->company->id)->count(),
                "pass {$pass}: re-import must not duplicate an existing category"
            );

            foreach (['GEL-CAVA_500', 'SAVO-DOUX_100'] as $sku) {
                $this->assertSame($existing->id, Product::where('sku', $sku)->firstOrFail()->category_id);
            }

            $passRows = ImportJob::query()->whereKey($jobId)->firstOrFail()->rows()->orderBy('row_number')->get()->keyBy('row_number');
            $this->assertSame('matched', $passRows[1]->data['_results']['product']['category'] ?? null);
            $this->assertNull($passRows[1]?->warnings, "pass {$pass}: an exact-name match must stay silent");
            $this->assertSame('matched_by_slug', $passRows[2]->data['_results']['product']['category'] ?? null);
            $this->assertSame('category_matched_by_slug', $passRows[2]->warnings[0]['code'] ?? null);
        }
    }

    /**
     * Gate r1 finding 1 (PROBE-A). `categories` soft-deletes and the unique
     * (company_id, slug) index is NOT partial, so a trashed row keeps its slug.
     * Every import row runs inside DB::transaction (ImportService.php importRow),
     * and on PostgreSQL a failed INSERT aborts that transaction (25P02) — so a
     * recovery SELECT after a caught QueryException cannot run. Must be handled
     * BEFORE any statement is allowed to fail. RUN THIS ON PG.
     */
    public function test_soft_deleted_category_holding_the_slug_is_restored_and_linked(): void
    {
        $trashed = Category::create([
            'company_id' => $this->company->id,
            'name' => 'Hygiene',
        ]);
        $trashed->delete();
        $this->assertSoftDeleted('categories', ['id' => $trashed->id]);

        $file = UploadedFile::fake()->createWithContent('products-trashed-cat.csv', implode("\n", [
            'name,sku,type,category_name',
            'Gel douche,GEL-CAVA_500,part,Hygiene',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', ['file' => $file, 'type' => 'products']);
        $createResponse->assertCreated();
        $jobId = $createResponse->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.successful_rows', 1)
            ->assertJsonPath('data.failed_rows', 0);

        $row = ImportJob::query()->whereKey($jobId)->firstOrFail()->rows()->firstOrFail();
        $this->assertNull($row->import_error, 'a trashed slug holder must not poison the row transaction');
        $this->assertSame('restored', $row->data['_results']['product']['category'] ?? null);
        $this->assertSame('category_restored', $row->warnings[0]['code'] ?? null);
        // The operator must be told a deleted category came back WITH its policy:
        // categories carry default_tax_rate / margin / discount / restock policy.
        $this->assertStringContainsString('tax', strtolower((string) ($row->warnings[0]['detail'] ?? '')));

        $this->assertSame(1, Category::where('company_id', $this->company->id)->count());
        $this->assertNotNull(Category::find($trashed->id));
        $this->assertSame($trashed->id, Product::where('sku', 'GEL-CAVA_500')->firstOrFail()->category_id);
    }

    /**
     * Gate r1 finding 2 (PROBE-C). `categories.name`/`slug` are varchar(255); an
     * over-long cell used to be ignored and now reaches an INSERT, so on PG it
     * rejects the whole product row with a raw SQLSTATE. It must be caught at
     * VALIDATION, with the row number, like every other over-long column.
     */
    public function test_over_long_category_name_is_a_row_validation_error_not_a_database_error(): void
    {
        $file = UploadedFile::fake()->createWithContent('products-long-cat.csv', implode("\n", [
            'name,sku,type,category_name',
            'Gel douche,GEL-CAVA_500,part,'.str_repeat('A', 300),
            'Savon doux,SAVO-DOUX_100,part,Hygiene',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', ['file' => $file, 'type' => 'products']);
        $createResponse->assertCreated();
        $jobId = $createResponse->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.successful_rows', 1)
            ->assertJsonPath('data.failed_rows', 1);

        $rows = ImportJob::query()->whereKey($jobId)->firstOrFail()->rows()->orderBy('row_number')->get()->keyBy('row_number');
        $this->assertFalse((bool) $rows[1]?->is_valid);
        $this->assertArrayHasKey('category_name', $rows[1]->errors ?? []);
        $this->assertNull($rows[1]?->import_error, 'must fail validation, never mid-import with a SQLSTATE');
        $this->assertSame(1, $rows[1]?->row_number);
        $this->assertTrue((bool) $rows[2]?->is_valid);
        $this->assertSame(0, Product::where('sku', 'GEL-CAVA_500')->count());
    }

    /**
     * Gate r1 finding 3. Matching on the slug is what keeps the unique index
     * survivable, but Str::slug collapses more than case+accents, so two
     * genuinely different names can merge into one category. Merging is right;
     * doing it silently is not — it is a master-data decision taken from a cell.
     */
    public function test_slug_match_on_a_different_name_is_reported_not_silently_merged(): void
    {
        $file = UploadedFile::fake()->createWithContent('products-slug-merge.csv', implode("\n", [
            'name,sku,type,category_name',
            'Creme hydratante,CREM-1,part,Crème',
            'Creme legere,CREM-2,part,Creme',
            'Serum eclat,SERU-1,part,Soins & Beauté',
            'Serum nuit,SERU-2,part,Soins Beauté',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', ['file' => $file, 'type' => 'products']);
        $createResponse->assertCreated();
        $jobId = $createResponse->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.failed_rows', 0);

        // Two categories, not four — the merge itself is the intended behaviour.
        $this->assertSame(2, Category::where('company_id', $this->company->id)->count());

        $rows = ImportJob::query()->whereKey($jobId)->firstOrFail()->rows()->orderBy('row_number')->get()->keyBy('row_number');
        $this->assertSame('created', $rows[1]->data['_results']['product']['category'] ?? null);
        $this->assertSame('matched_by_slug', $rows[2]->data['_results']['product']['category'] ?? null);
        $this->assertSame('category_matched_by_slug', $rows[2]->warnings[0]['code'] ?? null);

        $detail = (string) ($rows[2]->warnings[0]['detail'] ?? '');
        $this->assertStringContainsString('Creme', $detail, 'the warning must name the incoming value');
        $this->assertStringContainsString('Crème', $detail, 'and the category it was merged into');

        $this->assertSame('created', $rows[3]->data['_results']['product']['category'] ?? null);
        $this->assertSame('matched_by_slug', $rows[4]->data['_results']['product']['category'] ?? null);
        $this->assertSame('category_matched_by_slug', $rows[4]->warnings[0]['code'] ?? null);

        // An EXACT name hit stays silent — no new noise on ordinary re-imports.
        $this->assertNull($rows[1]->warnings[1] ?? null);
    }

    public function test_unit_resolution_is_identical_during_validation_execution_and_revalidation(): void
    {
        $file = UploadedFile::fake()->createWithContent('unit-honesty.csv', implode("\n", [
            'name,sku,type,unit',
            'Unknown piece,UNIT-HONEST-1,part,piece',
            'Unknown pcs,UNIT-HONEST-2,part,pcs',
            'Default piece,UNIT-HONEST-3,part,',
            'Exact kilogram,UNIT-HONEST-4,part,kg',
        ]));

        $create = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'file' => $file,
            'type' => 'products',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.status', ImportStatus::Validated->value)
            ->assertJsonPath('data.total_rows', 4)
            ->assertJsonPath('data.failed_rows', 2)
            ->assertJsonPath('data.error_summary.unknown_units.0.text', 'pcs')
            ->assertJsonPath('data.error_summary.unknown_units.0.count', 1)
            ->assertJsonPath('data.error_summary.unknown_units.1.text', 'piece')
            ->assertJsonPath('data.error_summary.unknown_units.1.count', 1);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/imports/'.(string) $create->json('data.id').'/error-summary')
            ->assertOk()
            ->assertJsonPath('data.error_summary.unknown_units.0.text', 'pcs')
            ->assertJsonPath('data.error_summary.unknown_units.1.text', 'piece');

        $job = ImportJob::query()->findOrFail((string) $create->json('data.id'));
        foreach ([1 => 'piece', 2 => 'pcs'] as $rowNumber => $supplied) {
            $row = $job->rows()->where('row_number', $rowNumber)->firstOrFail();
            $this->assertFalse($row->is_valid);
            $this->assertSame(ImportErrorCode::UnitUnknown, $row->import_error_code);
            $this->assertSame($supplied, $row->import_error_detail['supplied'] ?? null);
            $accepted = $row->import_error_detail['accepted'] ?? null;
            $this->assertIsArray($accepted);
            $this->assertLessThanOrEqual(20, count($accepted));
            $this->assertContains('pc', $accepted);
            $this->assertContains('kg', $accepted);
            $this->assertStringStartsWith(
                ImportErrorCode::UnitUnknown->value.':',
                $row->errors['unit'][0] ?? '',
            );
        }
        $this->assertTrue(
            $job->rows()->where('row_number', 3)->firstOrFail()->is_valid,
            'blank create must validate through the pc default',
        );
        $this->assertTrue(
            $job->rows()->where('row_number', 4)->firstOrFail()->is_valid,
            'an exact visible code must validate',
        );

        app(ImportService::class)->validateJob($job->refresh());
        $this->assertSame(2, $job->rows()->where('is_valid', false)->count());
        $this->assertSame(2, $job->rows()->where('is_valid', true)->count());

        $execute = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute");
        $execute->assertOk()
            ->assertJsonPath('import_result.imported_count', 2)
            ->assertJsonPath('import_result.skipped_count', 0)
            ->assertJsonPath('import_result.execution_error_count', 0)
            ->assertJsonPath('import_result.preview_drift_count', 0);

        $this->assertSame(2, $job->refresh()->failed_rows);
        $this->assertSame(0, $job->rows()->whereNotNull('import_error')->count());
        $this->assertSame('pc', Product::query()->where('sku', 'UNIT-HONEST-3')->value('unit'));
        $this->assertSame('kg', Product::query()->where('sku', 'UNIT-HONEST-4')->value('unit'));
    }

    public function test_validation_uses_each_company_visible_unit_catalog_and_is_idempotent(): void
    {
        $category = UnitCategory::query()->firstOrFail();
        Unit::factory()
            ->for($category, 'category')
            ->tenant($this->tenant->id)
            ->create([
                'code' => 'tenant-only',
                'name' => 'Tenant-only unit',
                'symbol' => 'to',
            ]);

        $firstJob = app(ImportService::class)->createJob(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: $this->user->id,
            type: ImportType::Products,
            filename: 'first-company.csv',
            filePath: 'imports/first-company.csv',
            totalRows: 1,
        );
        app(ImportService::class)->addRow($firstJob, 1, [
            'name' => 'First company product',
            'sku' => 'FIRST-COMPANY-UNIT',
            'unit' => 'tenant-only',
        ]);
        app(ImportService::class)->validateJob($firstJob);
        $this->assertSame(1, $firstJob->rows()->where('is_valid', true)->count());

        $secondTenant = Tenant::create([
            'name' => 'Second Unit Catalog Tenant',
            'slug' => 'second-unit-catalog-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $secondCompany = Company::create([
            'tenant_id' => $secondTenant->id,
            'name' => 'Second Unit Catalog Company',
            'legal_name' => 'Second Unit Catalog Company LLC',
            'tax_id' => 'TAX-PROD-SECOND',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
            'default_tax_rate' => '19.00',
        ]);
        $secondUser = User::create([
            'tenant_id' => $secondTenant->id,
            'name' => 'Second Import User',
            'email' => 'second-products-import@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($secondCompany->id);
        $secondJob = app(ImportService::class)->createJob(
            tenantId: $secondTenant->id,
            companyId: $secondCompany->id,
            userId: $secondUser->id,
            type: ImportType::Products,
            filename: 'second-company.csv',
            filePath: 'imports/second-company.csv',
            totalRows: 1,
        );
        app(ImportService::class)->addRow($secondJob, 1, [
            'name' => 'Second company product',
            'sku' => 'SECOND-COMPANY-UNIT',
            'unit' => 'tenant-only',
        ]);

        app(ImportService::class)->validateJob($secondJob);
        $row = $secondJob->rows()->firstOrFail();
        $this->assertFalse($row->is_valid);
        $this->assertSame(ImportErrorCode::UnitUnknown, $row->import_error_code);
        $this->assertNotContains('tenant-only', $row->import_error_detail['accepted'] ?? []);

        app(ImportService::class)->validateJob($secondJob->refresh());
        $row->refresh();
        $this->assertFalse($row->is_valid);
        $this->assertSame(ImportErrorCode::UnitUnknown, $row->import_error_code);
        $this->assertSame(1, $secondJob->rows()->where('is_valid', false)->count());

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_real_xlsx_upload_census_reports_the_77_conflict_groups_across_754_rows(): void
    {
        $path = realpath(__DIR__.'/../../Fixtures/Import/real-produits.xlsx');
        $this->assertIsString($path, 'The real products workbook fixture must remain available in tests/fixtures.');

        $file = new UploadedFile(
            $path,
            'real-produits.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
        $create = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'file' => $file,
            'type' => 'products',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.total_rows', 859)
            ->assertJsonPath('data.failed_rows', 859)
            ->assertJsonPath('data.options.duplicate_census.barcode_groups.counts.multi_location_products', 0)
            ->assertJsonPath('data.options.duplicate_census.barcode_groups.counts.barcode_identity_conflict_groups', 77)
            ->assertJsonPath('data.options.duplicate_census.barcode_groups.counts.barcode_identity_conflict_rows', 754);

        $jobId = $create->json('data.id');
        $this->assertIsString($jobId);
        $preview = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$jobId}/preview");
        $preview->assertOk()
            ->assertJsonPath('data.summary.valid_rows', 0)
            ->assertJsonPath('data.summary.invalid_rows', 859)
            ->assertJsonPath('data.duplicates.barcode_groups.counts.barcode_identity_conflict_groups', 77)
            ->assertJsonPath('data.duplicates.barcode_groups.counts.barcode_identity_conflict_rows', 754);
    }

    public function test_preview_counts_a_unit_invalid_barcode_conflict_row_only_once(): void
    {
        $file = UploadedFile::fake()->createWithContent('mixed-barcode-conflict.csv', implode("\n", [
            'name,sku,barcode,type,unit',
            'Valid conflict identity,MIXED-CONFLICT-A,123,part,pc',
            'Unit-invalid conflict identity,MIXED-CONFLICT-B,123,part,piece',
            'Independent valid product,MIXED-VALID,456,part,pc',
        ]));

        $create = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'file' => $file,
            'type' => 'products',
        ])->assertCreated();
        $jobId = (string) $create->json('data.id');

        $job = ImportJob::query()->findOrFail($jobId);
        $this->assertTrue($job->rows()->where('row_number', 1)->firstOrFail()->is_valid);
        $this->assertFalse($job->rows()->where('row_number', 2)->firstOrFail()->is_valid);
        $this->assertSame(ImportErrorCode::UnitUnknown, $job->rows()->where('row_number', 2)->firstOrFail()->import_error_code);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$jobId}/preview")
            ->assertOk()
            ->assertJsonPath('data.summary.total_rows', 3)
            ->assertJsonPath('data.summary.barcode_identity_conflict_rows', 2)
            ->assertJsonPath('data.summary.valid_rows', 1)
            ->assertJsonPath('data.summary.invalid_rows', 2);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.successful_rows', 1)
            ->assertJsonPath('data.failed_rows', 2);

        $this->assertSame(1, Product::query()->where('company_id', $this->company->id)->count());
        $this->assertNotNull(Product::query()->where('sku', 'MIXED-VALID')->first());
    }

    public function test_numeric_barcode_variants_stage_and_import_as_one_multi_location_product(): void
    {
        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Annex Warehouse',
            'code' => 'ANNEX',
            'type' => 'warehouse',
            'is_default' => false,
            'is_active' => true,
        ]);
        $file = UploadedFile::fake()->createWithContent('canonical-barcodes.csv', implode("\n", [
            'name,barcode,type,location_code',
            'Canonical barcode product,123,part,MAIN',
            'Canonical barcode product,123.0,part,ANNEX',
        ]));

        $create = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'file' => $file,
            'type' => 'products',
            'options' => [
                'duplicate_policy' => 'skip',
                'multi_location_confirmed' => true,
            ],
        ])->assertCreated()
            ->assertJsonPath('data.options.duplicate_census.barcode_groups.counts.multi_location_products', 1);
        $jobId = (string) $create->json('data.id');

        $job = ImportJob::query()->findOrFail($jobId);
        $this->assertSame(
            ['123', '123'],
            $job->rows()->orderBy('row_number')->get()->map(
                static fn (ImportRow $row): mixed => $row->data['barcode'] ?? null,
            )->all(),
            'The staging boundary must persist the same canonical barcode used by the census.',
        );

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.successful_rows', 2)
            ->assertJsonPath('data.failed_rows', 0);

        $this->assertSame(1, Product::query()->where('company_id', $this->company->id)->count());
        $product = Product::query()->where('company_id', $this->company->id)->sole();
        $this->assertSame('123', $product->barcode);
        $this->assertSame(
            [ImportRowOutcome::Imported, ImportRowOutcome::MergedLine],
            $job->rows()->orderBy('row_number')->pluck('outcome')->all(),
        );
    }

    public function test_real_products_workbook_reports_all_859_piece_rows_and_preserves_the_three_quantity_errors(): void
    {
        $path = realpath(__DIR__.'/../../../../web/e2e-local/real-produits.xlsx');
        $this->assertIsString($path, 'The owner-provided real products workbook must remain available.');
        $file = new UploadedFile(
            $path,
            'real-produits.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $unitQueryCount = 0;
        DB::listen(static function ($query) use (&$unitQueryCount): void {
            if (preg_match('/\b(?:from|join)\s+["`]?units?["`]?|\bfrom\s+["`]?unit_text_mappings["`]?/i', $query->sql) === 1) {
                $unitQueryCount++;
            }
        });

        $createStartedAt = hrtime(true);
        $create = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'file' => $file,
            'type' => 'products',
        ]);
        $createDurationSeconds = (hrtime(true) - $createStartedAt) / 1_000_000_000;
        $createUnitQueryCount = $unitQueryCount;

        $create->assertCreated()
            ->assertJsonPath('data.status', ImportStatus::Validated->value)
            ->assertJsonPath('data.total_rows', 859)
            ->assertJsonPath('data.failed_rows', 859)
            ->assertJsonPath('data.error_summary.unknown_units.0.text', 'piece')
            ->assertJsonPath('data.error_summary.unknown_units.0.count', 859);

        $jobId = (string) $create->json('data.id');
        $unitQueryCount = 0;
        $patchStartedAt = hrtime(true);
        $patch = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/imports/{$jobId}/options", [
                'options' => ['duplicate_policy' => 'override'],
            ]);
        $patchDurationSeconds = (hrtime(true) - $patchStartedAt) / 1_000_000_000;
        $patchUnitQueryCount = $unitQueryCount;

        fwrite(STDERR, sprintf(
            "\nreal-produits.xlsx timings: POST /imports %.3f s (%d unit queries); PATCH /imports/{id}/options %.3f s (%d unit queries)\n",
            $createDurationSeconds,
            $createUnitQueryCount,
            $patchDurationSeconds,
            $patchUnitQueryCount,
        ));

        $patch->assertOk()
            ->assertJsonPath('data.status', ImportStatus::Validated->value)
            ->assertJsonPath('data.total_rows', 859)
            ->assertJsonPath('data.failed_rows', 859)
            ->assertJsonPath('data.error_summary.unknown_units.0.text', 'piece')
            ->assertJsonPath('data.error_summary.unknown_units.0.count', 859);
        $this->assertLessThanOrEqual(
            3,
            $createUnitQueryCount,
            'POST /imports must load unit resolution data a bounded number of times.',
        );
        $this->assertLessThanOrEqual(
            3,
            $patchUnitQueryCount,
            'PATCH /imports/{id}/options must load unit resolution data a bounded number of times.',
        );

        $preview = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$jobId}/preview");
        $preview->assertOk()
            ->assertJsonPath('data.summary.total_rows', 859)
            ->assertJsonPath('data.summary.valid_rows', 0)
            ->assertJsonPath('data.summary.invalid_rows', 859)
            ->assertJsonPath('data.error_summary.unknown_units.0.text', 'piece')
            ->assertJsonPath('data.error_summary.unknown_units.0.count', 859);

        $job = ImportJob::query()->findOrFail($jobId);
        foreach ([141, 160, 827] as $rowNumber) {
            $row = $job->rows()->where('row_number', $rowNumber)->firstOrFail();
            $this->assertSame('-1', $row->data['quantity'] ?? null);
            $this->assertArrayHasKey('quantity', $row->errors ?? []);
            $this->assertArrayHasKey('unit', $row->errors ?? []);
            $this->assertSame(ImportErrorCode::UnitUnknown, $row->import_error_code);
        }

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertStatus(422)
            ->assertJsonPath('valid_rows', 0)
            ->assertJsonPath('failed_rows', 859);
        $this->assertSame(0, Product::query()->count());
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    public function test_confirmed_multi_location_group_under_skip_lands_each_line_once_and_reruns_idempotently(): void
    {
        $annex = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Annex Warehouse',
            'code' => 'ANNEX',
            'type' => 'warehouse',
            'is_default' => false,
            'is_active' => true,
        ]);
        $csv = implode("\n", [
            'name,sku,type,barcode,quantity,location_code,purchase_price',
            'Shared Product,SHARED-MULTI,part,6192222222222,3.0000,MAIN,2.000',
            'Shared Product,SHARED-MULTI,part,6192222222222,4.0000,ANNEX,2.000',
        ]);

        $run = function () use ($csv): string {
            $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
                'file' => UploadedFile::fake()->createWithContent('multi-location.csv', $csv),
                'type' => 'products',
                'options' => [
                    'duplicate_policy' => 'skip',
                    'multi_location_confirmed' => true,
                ],
            ])->assertCreated();
            $jobId = $response->json('data.id');
            $this->assertIsString($jobId);

            $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/v1/imports/{$jobId}/preview")
                ->assertOk()
                ->assertJsonPath('data.duplicates.barcode_groups.counts.multi_location_products', 1)
                ->assertJsonPath('data.summary.barcode_identity_conflict_rows', 0);

            $this->actingAs($this->user, 'sanctum')
                ->postJson("/api/v1/imports/{$jobId}/execute")
                ->assertOk()
                ->assertJsonPath('data.successful_rows', 2)
                ->assertJsonPath('data.multi_location_products', 1);

            return $jobId;
        };

        $firstJobId = $run();
        $product = Product::query()->where('company_id', $this->company->id)->where('sku', 'SHARED-MULTI')->sole();
        $this->assertSame(1, Product::query()->where('company_id', $this->company->id)->where('barcode', '6192222222222')->count());
        $this->assertSame('3.0000', StockLevel::query()->where('product_id', $product->id)->where('location_id', $this->location->id)->sole()->quantity);
        $this->assertSame('4.0000', StockLevel::query()->where('product_id', $product->id)->where('location_id', $annex->id)->sole()->quantity);
        $this->assertSame(
            ['imported', 'merged_line'],
            DB::table('import_rows')->where('import_job_id', $firstJobId)->orderBy('row_number')->pluck('outcome')->all(),
        );

        $secondJobId = $run();
        $this->assertSame(1, Product::query()->where('company_id', $this->company->id)->where('sku', 'SHARED-MULTI')->count());
        $this->assertSame('3.0000', StockLevel::query()->where('product_id', $product->id)->where('location_id', $this->location->id)->sole()->quantity);
        $this->assertSame('4.0000', StockLevel::query()->where('product_id', $product->id)->where('location_id', $annex->id)->sole()->quantity);
        $this->assertSame(
            ['merged_line', 'merged_line'],
            DB::table('import_rows')->where('import_job_id', $secondJobId)->orderBy('row_number')->pluck('outcome')->all(),
        );
    }

    public function test_multi_location_group_cannot_execute_until_confirmation_is_persisted(): void
    {
        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Second Warehouse',
            'code' => 'SECOND',
            'type' => 'warehouse',
            'is_active' => true,
        ]);
        $file = UploadedFile::fake()->createWithContent('multi-location-unconfirmed.csv', implode("\n", [
            'name,sku,barcode,location_code',
            'Confirmation Product,CONFIRM-MULTI,6193333333333,MAIN',
            'Confirmation Product,CONFIRM-MULTI,6193333333333,SECOND',
        ]));
        $jobId = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'file' => $file,
            'type' => 'products',
            'options' => ['duplicate_policy' => 'skip'],
        ])->assertCreated()->json('data.id');
        $this->assertIsString($jobId);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MULTI_LOCATION_CONFIRMATION_REQUIRED');

        $this->assertSame(0, Product::query()->where('company_id', $this->company->id)->where('sku', 'CONFIRM-MULTI')->count());
    }

    public function test_import_never_rewrites_a_sku_matched_product_with_another_products_barcode(): void
    {
        $target = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Target',
            'sku' => 'TARGET-SKU',
            'barcode' => 'TARGET-BARCODE',
        ]);
        $holder = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Holder',
            'sku' => 'HOLDER-SKU',
            'barcode' => 'HOLDER-BARCODE',
        ]);
        $jobId = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'file' => UploadedFile::fake()->createWithContent('collision.csv', implode("\n", [
                'name,sku,barcode',
                'Target,TARGET-SKU,HOLDER-BARCODE',
            ])),
            'type' => 'products',
            'options' => ['duplicate_policy' => 'override'],
        ])->assertCreated()->json('data.id');
        $this->assertIsString($jobId);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.failed_rows', 1);

        $this->assertSame('TARGET-BARCODE', $target->refresh()->barcode);
        $this->assertSame('HOLDER-BARCODE', $holder->refresh()->barcode);
        $this->assertSame('barcode_identity_conflict', ImportJob::query()->findOrFail($jobId)->rows()->sole()->import_error_code?->value);
    }

    /**
     * Execute an override import for one existing product through the real HTTP
     * create and execute endpoints.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $values
     * @return array{0: Product, 1: ImportRow}
     */
    private function executeSparsePriceOverride(
        string $sku,
        array $columns,
        array $values,
        ?string $priceAuthority = null,
    ): array {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Sparse price product',
            'sku' => $sku,
            'type' => ProductType::Part,
            'sale_price' => '11.900',
            'purchase_price' => '8.000',
            'tax_rate' => '19.00',
        ]);
        $file = UploadedFile::fake()->createWithContent('sparse-price.csv', implode("\n", [
            implode(',', ['name', 'sku', ...$columns]),
            implode(',', ['Sparse price product', $sku, ...$values]),
        ]));
        $options = ['duplicate_policy' => 'override'];
        if ($priceAuthority !== null) {
            $options['price_authority'] = $priceAuthority;
        }

        $createResponse = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'file' => $file,
            'type' => 'products',
            'options' => $options,
        ]);
        $createResponse->assertCreated();
        $jobId = (string) $createResponse->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('import_result.imported_count', 1);

        return [
            $product->refresh(),
            ImportJob::query()->findOrFail($jobId)->rows()->sole(),
        ];
    }

    /**
     * The two TN line-item VAT configurations these tests reason about, plus the
     * company default (19 %) that provisioning would have set.
     *
     * @return array{0: TaxConfiguration, 1: TaxConfiguration} [TVA 19 %, TVA 7 %]
     */
    private function seedTunisianVatConfigurations(): array
    {
        $this->seed(CountriesSeeder::class);

        $tva19 = TaxConfiguration::create([
            'country_code' => 'TN',
            'tax_type' => 'PERCENTAGE',
            'name' => 'TVA 19%',
            'code' => 'TVA_19',
            'percentage_rate' => '19.00',
            'applies_to' => 'LINE_ITEMS',
            'is_default' => true,
            'is_active' => true,
        ]);

        $tva7 = TaxConfiguration::create([
            'country_code' => 'TN',
            'tax_type' => 'PERCENTAGE',
            'name' => 'TVA 7%',
            'code' => 'TVA_7',
            'percentage_rate' => '7.00',
            'applies_to' => 'LINE_ITEMS',
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->company->update(['default_tax_configuration_id' => $tva19->id]);

        return [$tva19, $tva7];
    }

    /**
     * Run one products import end to end, through the real endpoints.
     *
     * @param  list<string>  $lines  CSV header + rows
     * @return string the import job id
     */
    private function runProductImport(array $lines, int $expectedSuccessful): string
    {
        $file = UploadedFile::fake()->createWithContent(
            'products-'.bin2hex(random_bytes(4)).'.csv',
            implode("\n", $lines),
        );

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', ['file' => $file, 'type' => 'products']);
        $createResponse->assertCreated();

        $jobId = $createResponse->json('data.id');
        $this->assertIsString($jobId);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.successful_rows', $expectedSuccessful)
            ->assertJsonPath('data.failed_rows', 0);

        return $jobId;
    }

    /**
     * @param  numeric-string  $expected
     */
    private function assertDecimalEquals(string $expected, ?string $actual, string $message = ''): void
    {
        if ($actual === null || ! is_numeric($actual)) {
            self::fail($message !== '' ? $message : 'Expected a numeric decimal string.');
        }

        $this->assertSame(0, bccomp($actual, $expected, 2), $message);
    }
}
