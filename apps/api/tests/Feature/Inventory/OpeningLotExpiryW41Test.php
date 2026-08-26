<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\OpeningBalanceLine;
use App\Modules\Inventory\Application\DTOs\OpeningBalancePosting;
use App\Modules\Inventory\Application\Services\InventoryOpeningService;
use App\Modules\Inventory\Application\Services\OpeningBalancePostingService;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Campaign W4-1 — an opening lot must never carry an expiry nobody supplied.
 *
 * The launch tenant is a parapharmacy: every product is batch-tracked, and on
 * day one ALL stock arrives as an opening balance. The old build minted the
 * DEFAULT lot with `cutover + 365`, and because FEFO ranks on expiry, that
 * fabricated date was the EARLIEST on every product — so the transfer and
 * delivery-note FEFO guards did not merely SUGGEST the fictional lot, they
 * REFUSED every alternative.
 *
 * What is pinned here:
 *   1. no expiry supplied and no configured shelf life  -> lot expiry is NULL;
 *   2. a configured `default_shelf_life_days` is still honoured (that IS a
 *      supplied rule, not a fabrication);
 *   3. an expiry supplied on the opening line wins over the shelf life;
 *   4. FEFO ranks a NULL-expiry lot AFTER every dated lot, ties by received
 *      order — and still SEES it (an undated lot is not an expired lot);
 *   5. the opening wizard accepts, validates and posts an `expiry_date` column.
 */
final class OpeningLotExpiryW41Test extends TestCase
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
            'name' => 'W41 Tenant',
            'slug' => 'w41-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'W41 Company',
            'legal_name' => 'W41 Company LLC',
            'tax_id' => 'W41-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'W41 User',
            'email' => 'w41-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-W41',
            'name' => 'W41 Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        foreach ([
            ['3100', 'Inventory', AccountType::Asset, SystemAccountPurpose::Inventory],
            ['3900', 'Opening Balance Equity', AccountType::Equity, SystemAccountPurpose::OpeningBalanceEquity],
        ] as [$code, $name, $type, $purpose]) {
            Account::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'system_purpose' => $purpose,
                'is_active' => true,
                'is_system' => true,
            ]);
        }

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    // -----------------------------------------------------------------
    // 1-3: what dates the opening lot
    // -----------------------------------------------------------------

    public function test_opening_a_batch_tracked_product_with_no_shelf_life_mints_an_undated_lot(): void
    {
        $product = $this->product(requiresBatchTracking: true, shelfLifeDays: null);

        $this->postOpening($product, quantity: '40');

        $batch = $this->defaultLot($product);
        $this->assertNull(
            $batch->expiry_date,
            'W4-1: with no supplied expiry and no configured shelf life, the opening lot must record NO expiry — '
            .'not cutover + 365.',
        );

        // The lot still BACKS the stock: the default-batch invariant is untouched.
        $stock = BatchStock::where('batch_id', $batch->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();
        $this->assertSame(0, bccomp('40.0000', (string) $stock->quantity, 4));
    }

    public function test_a_configured_shelf_life_still_dates_the_opening_lot(): void
    {
        $product = $this->product(requiresBatchTracking: true, shelfLifeDays: 90);

        $this->postOpening($product, quantity: '10', entryDate: Carbon::parse('2026-03-01'));

        $this->assertSame(
            '2026-05-30',
            $this->defaultLot($product)->expiry_date?->toDateString(),
            'a shelf life the operator CONFIGURED is a supplied rule, not a fabrication — it must still apply',
        );
    }

    public function test_an_expiry_supplied_on_the_opening_line_wins_over_the_shelf_life(): void
    {
        $product = $this->product(requiresBatchTracking: true, shelfLifeDays: 90);

        $this->postOpening(
            $product,
            quantity: '10',
            entryDate: Carbon::parse('2026-03-01'),
            expiryDate: '2027-01-31',
        );

        $this->assertSame(
            '2027-01-31',
            $this->defaultLot($product)->expiry_date?->toDateString(),
            'the expiry printed on the sheet is the most specific fact there is; it must beat the shelf-life rule',
        );
    }

    public function test_a_blank_expiry_on_the_line_is_not_a_date(): void
    {
        $product = $this->product(requiresBatchTracking: true, shelfLifeDays: null);

        $this->postOpening($product, quantity: '10', expiryDate: '   ');

        $this->assertNull(
            $this->defaultLot($product)->expiry_date,
            'a blank cell means "not supplied", and must not be coerced into a date',
        );
    }

    public function test_a_malformed_expiry_on_the_line_is_refused_rather_than_guessed(): void
    {
        $product = $this->product(requiresBatchTracking: true, shelfLifeDays: null);

        $this->expectException(\InvalidArgumentException::class);

        OpeningBalanceLine::make(
            $product->id,
            null,
            $this->location->id,
            '10.0000',
            '5.000',
            3,
            '31/01/2027',
        );
    }

    // -----------------------------------------------------------------
    // 4: FEFO ranks an undated lot LAST, and still sees it
    // -----------------------------------------------------------------

    public function test_fefo_ranks_the_undated_lot_after_every_dated_lot(): void
    {
        $product = $this->product(requiresBatchTracking: true, shelfLifeDays: null);

        // Created undated FIRST, so a driver that merely preserved insertion
        // order — or SQLite's NULLS-FIRST default — would put it in front.
        $undated = $this->lot($product, 'DEFAULT', null, '40.0000');
        $late = $this->lot($product, 'LOT-LATE', '2028-06-30', '20.0000');
        $early = $this->lot($product, 'LOT-EARLY', '2027-11-30', '30.0000');

        $result = app(FEFOInventoryService::class)->suggestBatchesForSale(
            $product->id,
            $this->location->id,
            '90.0000',
        );

        $order = array_map(
            static fn ($suggestion): int => $suggestion->batch->id,
            $result->suggestions,
        );

        $this->assertSame(
            [$early->id, $late->id, $undated->id],
            $order,
            'W4-1: FEFO must draw the earliest DATED lot first and the undated lot LAST. Before this lane the '
            .'undated lot was a fabricated `cutover + 365`, which sorted FIRST and forced the operator to ship it.',
        );
        $this->assertTrue($result->fullyFulfilled, 'the undated lot must still be usable stock, not hidden stock');
    }

    public function test_an_undated_lot_is_visible_to_fefo_even_though_expired_lots_are_not(): void
    {
        $product = $this->product(requiresBatchTracking: true, shelfLifeDays: null);

        $undated = $this->lot($product, 'DEFAULT', null, '15.0000');
        $this->lot($product, 'LOT-GONE', Carbon::now()->subDay()->toDateString(), '99.0000');

        $result = app(FEFOInventoryService::class)->suggestBatchesForSale(
            $product->id,
            $this->location->id,
            '15.0000',
        );

        $this->assertSame(
            [$undated->id],
            array_map(static fn ($s): int => $s->batch->id, $result->suggestions),
            'an undated lot is NOT an expired lot: excluding it would make the entire opening catalogue invisible '
            .'the moment we stopped inventing expiries',
        );
    }

    public function test_two_undated_lots_are_ordered_by_the_order_they_were_received(): void
    {
        $product = $this->product(requiresBatchTracking: true, shelfLifeDays: null);

        $first = $this->lot($product, 'DEFAULT', null, '5.0000', createdAt: Carbon::parse('2026-01-05 08:00:00'));
        $second = $this->lot($product, 'NO-DATE-2', null, '5.0000', createdAt: Carbon::parse('2026-02-05 08:00:00'));

        $result = app(FEFOInventoryService::class)->suggestBatchesForSale(
            $product->id,
            $this->location->id,
            '10.0000',
        );

        $this->assertSame(
            [$first->id, $second->id],
            array_map(static fn ($s): int => $s->batch->id, $result->suggestions),
            'with no expiry to rank on, the oldest stock goes first — the tie must not be left to the driver',
        );
    }

    // -----------------------------------------------------------------
    // 5: the opening wizard's expiry_date column
    // -----------------------------------------------------------------

    public function test_the_opening_wizard_validates_maps_and_posts_a_supplied_expiry(): void
    {
        $product = $this->product(requiresBatchTracking: true, shelfLifeDays: null);
        $batch = $this->inventoryBatch();
        $this->wizardRow($batch, $product, ['expiry_date' => '2027-09-30']);

        $service = app(InventoryOpeningService::class);
        $validation = $service->validateBatch($batch);
        $this->assertTrue($validation['valid'], 'a well-formed expiry_date must not invalidate the row');

        $service->postBatch($batch->refresh(), (string) $this->user->id);

        $this->assertSame(
            '2027-09-30',
            $this->defaultLot($product)->expiry_date?->toDateString(),
            'the wizard column must reach the lot, not be dropped between validate and post',
        );
    }

    public function test_the_opening_wizard_leaves_the_lot_undated_when_the_column_is_blank(): void
    {
        $product = $this->product(requiresBatchTracking: true, shelfLifeDays: null);
        $batch = $this->inventoryBatch();
        $this->wizardRow($batch, $product, ['expiry_date' => '']);

        $service = app(InventoryOpeningService::class);
        $this->assertTrue($service->validateBatch($batch)['valid']);
        $service->postBatch($batch->refresh(), (string) $this->user->id);

        $this->assertNull($this->defaultLot($product)->expiry_date);
    }

    public function test_the_opening_wizard_refuses_a_malformed_expiry_with_a_field_error(): void
    {
        $product = $this->product(requiresBatchTracking: true, shelfLifeDays: null);
        $batch = $this->inventoryBatch();
        $row = $this->wizardRow($batch, $product, ['expiry_date' => '31/09/2027']);

        $validation = app(InventoryOpeningService::class)->validateBatch($batch);

        $this->assertFalse($validation['valid']);
        $this->assertArrayHasKey(
            'expiry_date',
            $validation['errors'][$row->id],
            'a locale-ambiguous or nonsense date must be REFUSED with its row, never silently reinterpreted',
        );
    }

    public function test_the_post_preview_shows_the_operator_that_a_lot_has_no_expiry(): void
    {
        $product = $this->product(requiresBatchTracking: true, shelfLifeDays: null);
        $batch = $this->inventoryBatch();
        $this->wizardRow($batch, $product, []);

        app(InventoryOpeningService::class)->validateBatch($batch);
        $preview = app(InventoryOpeningService::class)->getPostPreview($batch->refresh());

        $this->assertArrayHasKey('expiry_date', $preview['lines'][0]);
        $this->assertNull(
            $preview['lines'][0]['expiry_date'],
            'the operator must be able to SEE, before posting, that this lot will carry no expiry',
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function product(bool $requiresBatchTracking, ?int $shelfLifeDays): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'W41-'.uniqid(),
            'name' => 'W41 Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '0.000000',
            'requires_batch_tracking' => $requiresBatchTracking,
            'default_shelf_life_days' => $shelfLifeDays,
        ]);
    }

    private function postOpening(
        Product $product,
        string $quantity,
        ?Carbon $entryDate = null,
        ?string $expiryDate = null,
    ): void {
        app(OpeningBalancePostingService::class)->post(new OpeningBalancePosting(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: (string) $this->user->id,
            entryDate: $entryDate ?? Carbon::now(),
            isHistorical: true,
            sourceType: 'opening_balance',
            sourceId: $product->id,
            reference: 'W4-1 opening',
            notes: null,
            lines: [OpeningBalanceLine::make(
                $product->id,
                null,
                $this->location->id,
                $quantity,
                '5.000',
                3,
                $expiryDate,
            )],
        ));
    }

    private function defaultLot(Product $product): Batch
    {
        return Batch::where('product_id', $product->id)
            ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
            ->firstOrFail();
    }

    private function lot(
        Product $product,
        string $batchNumber,
        ?string $expiryDate,
        string $quantity,
        ?Carbon $createdAt = null,
    ): Batch {
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        if ($createdAt !== null) {
            $batch->forceFill(['created_at' => $createdAt])->save();
        }

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved_quantity' => '0.0000',
        ]);

        return $batch->refresh();
    }

    private function inventoryBatch(): OpeningBalanceBatch
    {
        return OpeningBalanceBatch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => OpeningBatchType::Inventory,
            'name' => 'W41 Inventory OB '.uniqid(),
            'cutover_date' => Carbon::parse('2026-01-01'),
            'status' => OpeningBatchStatus::Draft,
            'created_by' => (string) $this->user->id,
        ]);
    }

    /**
     * @param  array<string, string>  $extraRawData
     */
    private function wizardRow(
        OpeningBalanceBatch $batch,
        Product $product,
        array $extraRawData,
    ): OpeningBalanceImportRow {
        return OpeningBalanceImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'row_type' => 'INVENTORY',
            'status' => OpeningImportRowStatus::Pending,
            'raw_data' => array_merge([
                'product_code' => $product->sku,
                'location_code' => $this->location->code,
                'quantity' => '12.0000',
                'unit_cost' => '3.000',
            ], $extraRawData),
        ]);
    }
}
