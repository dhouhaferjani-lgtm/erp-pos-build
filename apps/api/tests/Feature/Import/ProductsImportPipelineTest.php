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
use App\Modules\Import\Domain\ImportRow;
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
use Carbon\CarbonImmutable;
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
            $this->lotExpiryForSku('LOT-DATED'),
            'the expiry printed on the sheet must reach the opening lot',
        );
        $this->assertSame('ok', $this->onlyRowOf($jobId)->data['_results']['opening_stock'] ?? null);
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

        $this->assertSame('ok', $this->onlyRowOf($jobId)->data['_results']['opening_stock'] ?? null, 'the stock still opens');
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
