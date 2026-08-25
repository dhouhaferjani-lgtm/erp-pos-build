<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchMovement;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\StockReservation;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `inventory:repair-phantom-default-batches` — the repair path for tenants
 * already carrying campaign W2-7's phantom `DEFAULT` lots.
 *
 * Fixture is the exact campaign shape: 30 physical units in one dated lot, plus
 * a `DEFAULT` lot holding a full 30-unit copy that a sales-order confirm minted,
 * with the confirm's reservation parked on that phantom lot.
 */
final class RepairPhantomDefaultBatchesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Product $product;

    private Batch $realLot;

    private Batch $phantomLot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'W2-7 Repair Tenant',
            'slug' => 'w27-repair-'.bin2hex(random_bytes(4)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'W2-7 Repair Company',
            'legal_name' => 'W2-7 Repair Company SARL',
            'tax_id' => 'W27R-TAX',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'W27R-WH',
            'name' => 'W2-7 Repair Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'W27R-CREM',
            'name' => 'Crème hydratante Bébé 200ml',
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 365,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => '30.0000',
            'reserved' => '0.0000',
        ]);

        $this->realLot = $this->seedLot('LOT-CRÈME-2026A', now()->addDays(45)->toDateString(), '30.0000', '0.0000');
        $this->phantomLot = $this->seedLot(
            BatchStockService::DEFAULT_BATCH_NUMBER,
            now()->addDays(365)->toDateString(),
            '30.0000',
            '1.0000',
        );

        StockReservation::create([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'batch_id' => $this->phantomLot->id,
            'quantity' => '1.0000',
            'source_type' => ReservationSource::SalesOrder,
            'source_id' => (string) Str::uuid(),
            'priority' => 0,
        ]);
    }

    /**
     * @param  numeric-string  $quantity
     * @param  numeric-string  $reserved
     */
    private function seedLot(string $batchNumber, string $expiryDate, string $quantity, string $reserved): Batch
    {
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'manufacturing_date' => now()->subDay()->toDateString(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved_quantity' => $reserved,
        ]);

        return $batch;
    }

    public function test_it_refuses_to_run_without_an_explicit_tenant_scope(): void
    {
        $this->artisan('inventory:repair-phantom-default-batches', ['--dry-run' => true])
            ->expectsOutputToContain('Refusing to run without an explicit scope')
            ->assertExitCode(2);
    }

    public function test_it_refuses_to_run_without_choosing_dry_run_or_execute(): void
    {
        $this->artisan('inventory:repair-phantom-default-batches', ['--tenant' => $this->tenant->id])
            ->expectsOutputToContain('Pass exactly one of --dry-run')
            ->assertExitCode(2);

        $this->artisan('inventory:repair-phantom-default-batches', [
            '--tenant' => $this->tenant->id,
            '--dry-run' => true,
            '--execute' => true,
        ])
            ->expectsOutputToContain('Pass exactly one of --dry-run')
            ->assertExitCode(2);
    }

    public function test_dry_run_reports_the_exact_delta_and_writes_nothing(): void
    {
        $this->artisan('inventory:repair-phantom-default-batches', [
            '--tenant' => $this->tenant->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Phantom DEFAULT lots: 1')
            ->expectsOutputToContain('Total phantom quantity: 30.0000')
            ->expectsOutputToContain('Dry run: nothing was written')
            ->assertExitCode(0);

        $this->assertSame(
            0,
            bccomp('30.0000', (string) BatchStock::where('batch_id', $this->phantomLot->id)->value('quantity'), 4),
            'A dry run must not touch the phantom lot.',
        );
        $this->assertSame(0, $this->myStockMovements()->count());
        $this->assertSame(0, $this->myBatchMovements()->count());
        $this->assertSame(
            $this->phantomLot->id,
            $this->myStockReservations()->sole()->batch_id,
            'A dry run must not re-point reservations.',
        );
    }

    public function test_execute_zeroes_the_phantom_lot_and_repoints_its_reservation_onto_the_real_lot(): void
    {
        $this->artisan('inventory:repair-phantom-default-batches', [
            '--tenant' => $this->tenant->id,
            '--execute' => true,
        ])
            ->expectsOutputToContain('Reservations re-pointed to real lots: 1')
            ->expectsOutputToContain('Reservations left on the DEFAULT lot: 0')
            ->assertExitCode(0);

        $phantomStock = BatchStock::where('batch_id', $this->phantomLot->id)->sole();
        $realStock = BatchStock::where('batch_id', $this->realLot->id)->sole();

        $this->assertSame(0, bccomp('0.0000', (string) $phantomStock->quantity, 4),
            '30 real units are fully lot-represented, so the DEFAULT lot holds nothing.');
        $this->assertSame(0, bccomp('0.0000', (string) $phantomStock->reserved_quantity, 4));

        $this->assertSame(0, bccomp('30.0000', (string) $realStock->quantity, 4));
        $this->assertSame(0, bccomp('1.0000', (string) $realStock->reserved_quantity, 4));

        $this->assertSame($this->realLot->id, $this->myStockReservations()->sole()->batch_id);

        // Document-per-action: the lot reduction carries its own justifying pair.
        $movement = $this->myStockMovements()->sole();
        $this->assertSame(MovementType::Adjustment, $movement->movement_type);
        $this->assertSame('batch_ledger_repair', $movement->reference_type);
        $this->assertSame(0, bccomp('0.0000', (string) $movement->quantity, 4),
            'The AGGREGATE quantity never changed — only the batch ledger was inflated.');
        $this->assertSame(0, bccomp('30.0000', (string) $movement->quantity_before, 4));
        $this->assertSame(0, bccomp('30.0000', (string) $movement->quantity_after, 4));

        $batchMovement = $this->myBatchMovements()->sole();
        $this->assertSame($movement->id, $batchMovement->movement_id);
        $this->assertSame((int) $this->phantomLot->id, (int) $batchMovement->batch_id);
        $this->assertSame(0, bccomp('-30.0000', (string) $batchMovement->quantity, 4));

        // Aggregate stock is untouched.
        $this->assertSame(
            0,
            bccomp('30.0000', (string) $this->myStockLevels()->sole()->quantity, 4),
        );
    }

    public function test_it_keeps_the_untracked_remainder_and_floors_at_reservations_no_real_lot_can_cover(): void
    {
        // 33 aggregate units: 30 in the dated lot, 3 genuinely untracked.
        $this->myStockLevels()->update(['quantity' => '33.0000']);

        // The dated lot is almost entirely spoken for (29 of its 30 units), so
        // the 5-unit reservation parked on the DEFAULT lot cannot be moved and
        // the DEFAULT lot must be floored at it instead of dropping to 3.
        BatchStock::where('batch_id', $this->realLot->id)->update(['reserved_quantity' => '29.0000']);
        $this->myStockReservations()->update(['quantity' => '5.0000']);
        BatchStock::where('batch_id', $this->phantomLot->id)->update(['reserved_quantity' => '5.0000']);

        $this->artisan('inventory:repair-phantom-default-batches', [
            '--tenant' => $this->tenant->id,
            '--execute' => true,
        ])
            ->expectsOutputToContain('Reservations re-pointed to real lots: 0')
            ->expectsOutputToContain('Reservations left on the DEFAULT lot: 1')
            ->expectsOutputToContain('Lots only partially reduced: 1')
            ->assertExitCode(0);

        $phantomStock = BatchStock::where('batch_id', $this->phantomLot->id)->sole();

        $this->assertSame(
            0,
            bccomp('5.0000', (string) $phantomStock->quantity, 4),
            'Floored at the un-movable reservation rather than dropped to the 3-unit remainder.',
        );
        $this->assertSame($this->phantomLot->id, $this->myStockReservations()->sole()->batch_id);
    }

    public function test_a_correctly_sized_default_lot_is_left_alone(): void
    {
        // Make the DEFAULT lot honest: 33 aggregate − 30 tracked = 3.
        $this->myStockLevels()->update(['quantity' => '33.0000']);
        BatchStock::where('batch_id', $this->phantomLot->id)
            ->update(['quantity' => '3.0000', 'reserved_quantity' => '1.0000']);

        $this->artisan('inventory:repair-phantom-default-batches', [
            '--tenant' => $this->tenant->id,
            '--execute' => true,
        ])
            ->expectsOutputToContain('DEFAULT lots inspected: 1')
            ->expectsOutputToContain('Phantom DEFAULT lots: 0')
            ->assertExitCode(0);

        $this->assertSame(
            0,
            bccomp('3.0000', (string) BatchStock::where('batch_id', $this->phantomLot->id)->value('quantity'), 4),
        );
        $this->assertSame(0, $this->myStockMovements()->count());
        $this->assertSame($this->phantomLot->id, $this->myStockReservations()->sole()->batch_id);
    }
    // ─────────────────────────── gate r1 fix round ───────────────────────────

    /**
     * Gate r1 finding 5 — `reference_type` is a type column, so the value must
     * come from the canonical enum, not a private string constant. The concrete
     * failure mode of a bypass is already in the tree: `EntryExitNoteController`
     * resolves the column through `StockMovementReferenceType::tryFrom()` and
     * falls back to printing the RAW string to the operator.
     */
    public function test_the_justifying_movement_carries_the_canonical_reference_type_enum(): void
    {
        $this->artisan('inventory:repair-phantom-default-batches', [
            '--tenant' => $this->tenant->id,
            '--execute' => true,
        ])->assertExitCode(0);

        $movement = $this->myStockMovements()->sole();

        $this->assertSame(
            StockMovementReferenceType::BatchLedgerRepair,
            StockMovementReferenceType::tryFrom((string) $movement->reference_type),
            'The operator-facing label is resolved through the enum; an unknown value prints raw.',
        );

        // Structural, because a value assertion alone cannot tell an enum-sourced
        // write from a re-introduced string constant (rule 9): the command must
        // name the case, never the literal.
        $source = (string) file_get_contents(
            base_path('app/Console/Commands/RepairPhantomDefaultBatchesCommand.php'),
        );

        $this->assertStringNotContainsString(
            "'batch_ledger_repair'",
            $source,
            'reference_type is a type column — write StockMovementReferenceType::BatchLedgerRepair->value, '
            .'never the raw string. The enum is where the vocabulary and its i18n label live.',
        );

        $this->assertStringContainsString('StockMovementReferenceType::BatchLedgerRepair->value', $source);
    }

    /**
     * Gate r1 finding 11 — a run id in the transcript of a run that processed
     * nothing is a misleading audit breadcrumb.
     */
    public function test_a_refused_scope_prints_no_repair_run_id(): void
    {
        $this->artisan('inventory:repair-phantom-default-batches', ['--dry-run' => true])
            ->doesntExpectOutputToContain('Repair run id')
            ->assertExitCode(2);
    }

    /**
     * 🚨 Gate r3 R3-7 — the census used to be structurally blind to most drift.
     * It reported from inside the correction loop, which iterates tuples that
     * HAVE a `DEFAULT` lot AND are phantom-drifted, so a tuple whose DEFAULT lot
     * is healthy or absent was invisible however far its real lots had drifted.
     *
     * That is precisely where the return-side drift lives: before gate r3 R3-1 a
     * customer return credited `stock_levels` and no lot, so `Σ lots` UNDERSTATES
     * on-hand — and the run printed "Tuples still drifted: 0" on a tenant bleeding
     * lot quantity on every return.
     *
     * Fixture: no phantom at all (the DEFAULT lot is removed), one real lot of
     * 25 against an aggregate of 30 — the shape a pre-r3 return leaves.
     */
    public function test_the_census_reports_a_drifted_tuple_that_carries_no_phantom_at_all(): void
    {
        $this->myStockReservations()->delete();
        BatchStock::where('batch_id', $this->phantomLot->id)->delete();
        Batch::whereKey($this->phantomLot->id)->delete();
        BatchStock::where('batch_id', $this->realLot->id)->update(['quantity' => '25.0000']);

        $this->artisan('inventory:repair-phantom-default-batches', [
            '--tenant' => $this->tenant->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Phantom DEFAULT lots: 0')
            ->expectsOutputToContain('WOULD REMAIN DRIFTED -5.0000')
            ->expectsOutputToContain('cause: inbound stock (a return or receipt) credited stock_levels, not the lot ledger')
            ->expectsOutputToContain('Tuples still drifted: 1')
            ->assertExitCode(0);

        $this->assertSame(0, $this->myStockMovements()->count());
    }

    /**
     * The same census must see a tuple with aggregate stock and NO lots at all —
     * the shape a delivery now refuses outright.
     */
    public function test_the_census_reports_a_batch_tracked_tuple_with_no_lots_at_all(): void
    {
        $this->myStockReservations()->delete();
        $this->myBatchStocks()->delete();
        $this->myBatches()->delete();

        $this->artisan('inventory:repair-phantom-default-batches', [
            '--tenant' => $this->tenant->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DEFAULT lots inspected: 0')
            ->expectsOutputToContain('WOULD REMAIN DRIFTED -30.0000')
            ->expectsOutputToContain('Tuples still drifted: 1')
            ->assertExitCode(0);
    }

    /**
     * 🚨 Gate r2 C-2 — the DRY RUN is the operator's evidence step, and it used to
     * print "Tuples still drifted: 0" on a tenant that IS drifted, because the
     * detection sat behind `--execute`.
     *
     * This is the wave-2 tenant's real shape: a real lot of 30 against an
     * aggregate of 29 (an `issue`/`delivery` movement moved `stock_levels` and
     * left the lot alone), plus a 29-unit phantom DEFAULT. Removing the phantom
     * leaves `Σ lots = 30` against 29 — psql shows exactly +1.0000 — and the
     * operator must be told BEFORE deciding to execute.
     */
    public function test_the_dry_run_reports_the_drift_that_would_remain_after_the_correction(): void
    {
        $this->myStockLevels()->update(['quantity' => '29.0000']);
        BatchStock::where('batch_id', $this->phantomLot->id)
            ->update(['quantity' => '29.0000', 'reserved_quantity' => '0.0000']);
        $this->myStockReservations()->delete();

        $this->artisan('inventory:repair-phantom-default-batches', [
            '--tenant' => $this->tenant->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('WOULD REMAIN DRIFTED 1.0000')
            ->expectsOutputToContain('cause: an outbound sale without batch_id moved stock_levels, not the lot ledger')
            ->expectsOutputToContain('Tuples still drifted: 1')
            ->expectsOutputToContain('Dry run: nothing was written')
            ->assertExitCode(0);

        // Still a pure read.
        $this->assertSame(0, $this->myStockMovements()->count());
        $this->assertSame(
            0,
            bccomp('29.0000', (string) BatchStock::where('batch_id', $this->phantomLot->id)->value('quantity'), 4),
        );
    }

    /**
     * Gate r1 finding 9 — the command attributes ledger drift to the DEFAULT lot
     * BY CONSTRUCTION, so a real lot that overstates the aggregate survives the
     * correction. This is the wave-2 tenant's actual shape (real lot 30 against
     * an aggregate of 29): zeroing the phantom leaves `Σ lots = 30` against
     * `stock_levels.quantity = 29`, and the operator must not read that as
     * reconciled.
     */
    public function test_residual_ledger_drift_is_reported_rather_than_read_as_reconciled(): void
    {
        $this->myStockLevels()->update(['quantity' => '29.0000']);
        BatchStock::where('batch_id', $this->phantomLot->id)
            ->update(['quantity' => '29.0000', 'reserved_quantity' => '0.0000']);
        $this->myStockReservations()->delete();

        $this->artisan('inventory:repair-phantom-default-batches', [
            '--tenant' => $this->tenant->id,
            '--execute' => true,
        ])
            ->expectsOutputToContain('RESIDUAL DRIFT 1.0000')
            ->expectsOutputToContain('Tuples still drifted: 1')
            ->assertExitCode(0);

        $this->assertSame(
            0,
            bccomp('0.0000', (string) BatchStock::where('batch_id', $this->phantomLot->id)->value('quantity'), 4),
            'the phantom is still removed — the warning is additional, not a refusal',
        );
    }

    /**
     * Gate r1 finding 3 — the justifying movement must record the SAME tuple the
     * excess was computed from, variant predicate included. With a variant-bearing
     * product and no product-level `stock_levels` row, the variant-blind read
     * stamped `0.0000` while the real aggregate was 12.
     */
    public function test_the_movement_records_the_variant_scoped_aggregate(): void
    {
        $this->myStockReservations()->delete();
        $this->myBatchStocks()->delete();
        $this->myBatches()->delete();
        $this->myStockLevels()->delete();

        // A SIBLING variant row at the same location, created FIRST and holding a
        // different quantity. This is what makes the assertion discriminating: a
        // variant-blind `where(company, product, location)->value('quantity')`
        // returns THIS row, not the one being repaired.
        $sibling = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_code' => 'V-200ML',
            'sku' => 'W27R-CREM-200',
            'name_suffix' => '200ml',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => $sibling->id,
            'location_id' => $this->location->id,
            'quantity' => '99.0000',
            'reserved' => '0.0000',
        ]);

        $variant = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_code' => 'V-50ML',
            'sku' => 'W27R-CREM-50',
            'name_suffix' => '50ml',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => $variant->id,
            'location_id' => $this->location->id,
            'quantity' => '12.0000',
            'reserved' => '0.0000',
        ]);

        $variantDefault = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => $variant->id,
            'batch_number' => BatchStockService::DEFAULT_BATCH_NUMBER,
            'expiry_date' => now()->addDays(365)->toDateString(),
            'manufacturing_date' => now()->subDay()->toDateString(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        $variantRealLot = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => $variant->id,
            'batch_number' => 'LOT-CRÈME-50-2026A',
            'expiry_date' => now()->addDays(45)->toDateString(),
            'manufacturing_date' => now()->subDay()->toDateString(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        foreach ([$variantDefault->id, $variantRealLot->id] as $batchId) {
            BatchStock::create([
                'tenant_id' => $this->tenant->id,
                'batch_id' => $batchId,
                'location_id' => $this->location->id,
                'quantity' => '12.0000',
                'reserved_quantity' => '0.0000',
            ]);
        }

        $this->artisan('inventory:repair-phantom-default-batches', [
            '--tenant' => $this->tenant->id,
            '--execute' => true,
        ])->assertExitCode(0);

        $movement = $this->myStockMovements()->sole();

        $this->assertSame(
            0,
            bccomp('12.0000', (string) $movement->quantity_before, 4),
            'quantity_before must be the REPAIRED variant row (12), not the sibling variant row (99).',
        );
        $this->assertSame(0, bccomp('12.0000', (string) $movement->quantity_after, 4));
        $this->assertSame(0, bccomp('0.0000', (string) $movement->quantity, 4));
        $this->assertSame(
            0,
            bccomp('0.0000', (string) BatchStock::where('batch_id', $variantDefault->id)->value('quantity'), 4),
        );
    }

    // =================================================================
    // Helpers — scoped reads (LEDGER C-7)
    // =================================================================

    /**
     * Ledger rows created by THIS test.
     *
     * `setUp()` mints a fresh tenant/company per test, so these filters are an
     * exact "the rows I created" scope. Unscoped, the same reads also see the
     * rows COMMITTED by `PosCoreReceiptProjectionRefundDispositionStockTest`,
     * which disables RefreshDatabase transactions to assert real rollback
     * semantics and therefore leaves its rows behind for the rest of the PHP
     * process — this class was green standalone and red in any multi-class run
     * (`sole()` raising MultipleRecordsFoundException, `count()` returning 28
     * instead of 0). Same root cause and same fix shape as the C-7 lane.
     *
     * @return EloquentBuilder<StockMovement>
     */
    private function myStockMovements(): EloquentBuilder
    {
        return StockMovement::query()->where('tenant_id', $this->tenant->id);
    }

    /** @return EloquentBuilder<BatchMovement> */
    private function myBatchMovements(): EloquentBuilder
    {
        return BatchMovement::query()->where('tenant_id', $this->tenant->id);
    }

    /**
     * `stock_reservations` carries no tenant column — scope on company_id.
     *
     * @return EloquentBuilder<StockReservation>
     */
    private function myStockReservations(): EloquentBuilder
    {
        return StockReservation::query()->where('company_id', $this->company->id);
    }

    /** @return EloquentBuilder<StockLevel> */
    private function myStockLevels(): EloquentBuilder
    {
        return StockLevel::query()->where('tenant_id', $this->tenant->id);
    }

    /** @return EloquentBuilder<Batch> */
    private function myBatches(): EloquentBuilder
    {
        return Batch::query()->where('tenant_id', $this->tenant->id);
    }

    /** @return EloquentBuilder<BatchStock> */
    private function myBatchStocks(): EloquentBuilder
    {
        return BatchStock::query()->whereIn(
            'batch_id',
            Batch::query()->select('id')->where('tenant_id', $this->tenant->id),
        );
    }
}
