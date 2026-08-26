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
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

        $job = ImportJob::findOrFail($jobId);
        $this->assertInstanceOf(ImportJob::class, $job);
        $rows = $job->rows()->orderBy('row_number')->get()->keyBy('row_number');

        $this->assertSame('ok', $rows[1]->data['_results']['opening_stock'] ?? null);
        $this->assertSame('default', $rows[5]->data['_results']['tax_source'] ?? null);
        $this->assertSame('qty_without_cost', $rows[2]->warnings[0]['code'] ?? null);
        $this->assertSame('quantity_ignored_service', $rows[3]->warnings[0]['code'] ?? null);
        $this->assertSame('opening_exists', $rows[4]->warnings[0]['code'] ?? null);
        $this->assertSame('price_conflict', $rows[6]->warnings[0]['code'] ?? null);
        $this->assertSame(1, StockMovement::where('product_id', $duplicate->id)->where('movement_type', MovementType::Opening)->count());
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

        $row = ImportJob::findOrFail($jobId)->rows()->firstOrFail();
        $this->assertSame('ok', $row->data['_results']['opening_stock'] ?? null);
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
            Batch::where('product_id', Product::where('sku', 'LOT-DATED')->firstOrFail()->id)
                ->firstOrFail()->expiry_date?->toDateString(),
            'the expiry printed on the sheet must reach the opening lot',
        );
        $this->assertSame('ok', ImportJob::findOrFail($jobId)->rows()->firstOrFail()->data['_results']['opening_stock'] ?? null);
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

        $row = ImportJob::findOrFail($jobId)->rows()->firstOrFail();
        $this->assertNotNull($row->errors);
        $this->assertStringContainsString(
            'expiry_date',
            json_encode($row->errors, JSON_THROW_ON_ERROR),
            'the refusal must name the offending column so the operator can fix that cell',
        );

        $this->assertSame(
            0,
            Batch::whereHas('product', fn ($q) => $q->where('sku', 'LOT-BADEXP'))->count(),
            'a refused row must not have opened stock',
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

        $rows = ImportJob::findOrFail($jobId)->rows()->orderBy('row_number')->get()->keyBy('row_number');

        // Row 1 created the category -> reported. Row 2 reused it -> matched, no warning.
        $this->assertSame('category_created', $rows[1]->warnings[0]['code'] ?? null);
        $this->assertStringContainsString('Soins Bebe', (string) ($rows[1]->warnings[0]['detail'] ?? ''));
        $this->assertSame('created', $rows[1]->data['_results']['category'] ?? null);
        $this->assertNull($rows[2]->warnings);
        $this->assertSame('matched', $rows[2]->data['_results']['category'] ?? null);
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

            $passRows = ImportJob::findOrFail($jobId)->rows()->orderBy('row_number')->get()->keyBy('row_number');
            $this->assertSame('matched', $passRows[1]->data['_results']['category'] ?? null);
            $this->assertNull($passRows[1]->warnings, "pass {$pass}: an exact-name match must stay silent");
            $this->assertSame('matched_by_slug', $passRows[2]->data['_results']['category'] ?? null);
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

        $row = ImportJob::findOrFail($jobId)->rows()->firstOrFail();
        $this->assertNull($row->import_error, 'a trashed slug holder must not poison the row transaction');
        $this->assertSame('restored', $row->data['_results']['category'] ?? null);
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

        $rows = ImportJob::findOrFail($jobId)->rows()->orderBy('row_number')->get()->keyBy('row_number');
        $this->assertFalse((bool) $rows[1]->is_valid);
        $this->assertArrayHasKey('category_name', $rows[1]->errors ?? []);
        $this->assertNull($rows[1]->import_error, 'must fail validation, never mid-import with a SQLSTATE');
        $this->assertSame(1, $rows[1]->row_number);
        $this->assertTrue((bool) $rows[2]->is_valid);
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

        $rows = ImportJob::findOrFail($jobId)->rows()->orderBy('row_number')->get()->keyBy('row_number');
        $this->assertSame('created', $rows[1]->data['_results']['category'] ?? null);
        $this->assertSame('matched_by_slug', $rows[2]->data['_results']['category'] ?? null);
        $this->assertSame('category_matched_by_slug', $rows[2]->warnings[0]['code'] ?? null);

        $detail = (string) ($rows[2]->warnings[0]['detail'] ?? '');
        $this->assertStringContainsString('Creme', $detail, 'the warning must name the incoming value');
        $this->assertStringContainsString('Crème', $detail, 'and the category it was merged into');

        $this->assertSame('created', $rows[3]->data['_results']['category'] ?? null);
        $this->assertSame('matched_by_slug', $rows[4]->data['_results']['category'] ?? null);
        $this->assertSame('category_matched_by_slug', $rows[4]->warnings[0]['code'] ?? null);

        // An EXACT name hit stays silent — no new noise on ordinary re-imports.
        $this->assertNull($rows[1]->warnings[1] ?? null);
    }
}
