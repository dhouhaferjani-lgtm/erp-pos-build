<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\CreateStockAdjustmentData;
use App\Modules\Inventory\Application\DTOs\StockAdjustmentLineInput;
use App\Modules\Inventory\Application\Services\StockAdjustmentDocumentService;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Exceptions\BatchNotApplicableException;
use App\Modules\Inventory\Domain\Exceptions\BatchRequiredForLineException;
use App\Modules\Inventory\Domain\Exceptions\UseBatchWriteOffException;
use App\Modules\Inventory\Domain\StockAdjustment;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DPA V7 / T16 — lot disposition end to end (plan D1b / D7a, inventory gate C2
 * and the round-2 batch-boundary ruling).
 *
 * The ruling that shapes this file: the lot rules are THREE INDEPENDENT
 * PREDICATES, not one flag.
 *
 *   1. may this line use damage/write_off?  -> products.requires_batch_tracking ALONE
 *   2. must a NEGATIVE line name a lot?     -> "a lot with stock exists here" (FLAG-INDEPENDENT)
 *   3. may this line name THIS lot?         -> product + location (FLAG-INDEPENDENT)
 *
 * Keying all three on the flag opened two doors, and both get a named test here:
 * door 1 (flag true, zero lots -> an unauthorable empty picker in the
 * pharmacy/parapharmacy default) and door 2 (flag toggled false while lots
 * remain -> gate C2's desync re-created through the other side).
 */
final class StockAdjustmentBatchDispositionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Location $annex;

    private StockAdjustmentDocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Lot Tenant',
            'slug' => 'lot-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Lot Co',
            'legal_name' => 'Lot Co LLC',
            'tax_id' => 'TAX-LOT',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Lot User',
            'email' => 'lot@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'LOT-01',
            'name' => 'Lot Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->annex = Location::create([
            'company_id' => $this->company->id,
            'code' => 'LOT-02',
            'name' => 'Lot Annex',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        $this->service = app(StockAdjustmentDocumentService::class);
    }

    // ------------------------------- predicate 1: the destructive-reason gate

    public function test_damage_and_write_off_are_refused_on_a_batch_tracked_product(): void
    {
        $product = $this->product('LOT-P1', batchTracked: true);
        $this->seedStock($product, '10.0000');
        $this->seedLot($product, 'LOT-A', '10.0000');

        foreach ([MovementReason::Damage, MovementReason::WriteOff] as $reason) {
            try {
                $this->draft([$this->line($product, $reason, '-2.0000', '10.0000')]);
                $this->fail("Expected UseBatchWriteOffException for {$reason->value}.");
            } catch (UseBatchWriteOffException $e) {
                $this->assertSame($product->id, $e->productId);
                $this->assertSame($reason, $e->reasonCode);
            }
        }
    }

    public function test_damage_is_allowed_on_a_non_batch_tracked_product(): void
    {
        $product = $this->product('LOT-P2', batchTracked: false);
        $this->seedStock($product, '10.0000');

        $adjustment = $this->draft([$this->line($product, MovementReason::Damage, '-2.0000', '10.0000')]);
        $this->service->post($adjustment->id, $this->user->id);

        $this->assertSame('8.0000', (string) $this->level($product)->quantity);
    }

    // ---------------------------- predicate 2: lot-required, FLAG-INDEPENDENT

    public function test_a_negative_line_must_name_a_lot_when_one_holds_stock_here_whatever_the_flag_says(): void
    {
        foreach ([true, false] as $flag) {
            $product = $this->product('LOT-P3-'.($flag ? 'T' : 'F'), batchTracked: $flag);
            $this->seedStock($product, '10.0000');
            $this->seedLot($product, 'LOT-'.($flag ? 'T' : 'F'), '10.0000');

            try {
                $this->draft([$this->line($product, MovementReason::AdjustmentNegative, '-2.0000', '10.0000')]);
                $this->fail('Expected BatchRequiredForLineException with requires_batch_tracking='.var_export($flag, true));
            } catch (BatchRequiredForLineException $e) {
                $this->assertSame($product->id, $e->productId);
            }
        }
    }

    /**
     * DOOR 2 spelled out: the flag is user-toggleable and nothing deletes
     * BatchStock on the reverse flip. Keying lot-required on the flag would have
     * FORBIDDEN naming the lot that actually holds the stock, while the aggregate
     * moved and the lots did not — gate C2's desync through the other door.
     */
    public function test_door_two_a_toggled_off_flag_still_requires_and_still_accepts_the_lot(): void
    {
        $product = $this->product('LOT-DOOR2', batchTracked: true);
        $this->seedStock($product, '10.0000');
        $lot = $this->seedLot($product, 'LOT-LEGACY', '10.0000');

        // The operator turns batch tracking OFF; the lots survive.
        $product->update(['requires_batch_tracking' => false]);

        $adjustment = $this->draft([
            $this->line($product, MovementReason::AdjustmentNegative, '-4.0000', '10.0000', $lot->uuid),
        ]);
        $this->service->post($adjustment->id, $this->user->id);

        $this->assertSame('6.0000', (string) $this->level($product)->quantity);
        $this->assertSame('6.0000', $this->lotQuantity($lot));
        $this->assertSame('6.0000', $this->totalLotQuantity($product));
    }

    public function test_a_negative_line_needs_no_lot_when_no_lot_holds_stock_here(): void
    {
        $product = $this->product('LOT-P4', batchTracked: false);
        $this->seedStock($product, '10.0000');
        // A lot exists — but at ANOTHER location.
        $this->seedLot($product, 'LOT-ELSEWHERE', '10.0000', $this->annex);

        $adjustment = $this->draft([
            $this->line($product, MovementReason::AdjustmentNegative, '-2.0000', '10.0000'),
        ]);
        $this->service->post($adjustment->id, $this->user->id);

        $this->assertSame('8.0000', (string) $this->level($product)->quantity);
    }

    // -------------------------------- predicate 3: lot-valid for this product

    public function test_a_lot_belonging_to_another_product_is_refused(): void
    {
        $product = $this->product('LOT-P5', batchTracked: true);
        $other = $this->product('LOT-P6', batchTracked: true);
        $this->seedStock($product, '10.0000');
        $foreignLot = $this->seedLot($other, 'LOT-FOREIGN', '10.0000');

        try {
            $this->draft([
                $this->line($product, MovementReason::AdjustmentNegative, '-2.0000', '10.0000', $foreignLot->uuid),
            ]);
            $this->fail('Expected BatchNotApplicableException.');
        } catch (BatchNotApplicableException $e) {
            $this->assertSame($product->id, $e->productId);
            $this->assertSame($foreignLot->uuid, $e->batchUuid);
        }
    }

    public function test_a_lot_with_no_stock_row_at_this_location_is_refused(): void
    {
        $product = $this->product('LOT-P7', batchTracked: true);
        $this->seedStock($product, '10.0000');
        $elsewhere = $this->seedLot($product, 'LOT-ANNEX', '10.0000', $this->annex);

        $this->expectException(BatchNotApplicableException::class);
        $this->draft([
            $this->line($product, MovementReason::AdjustmentNegative, '-2.0000', '10.0000', $elsewhere->uuid),
        ]);
    }

    public function test_a_lot_from_another_tenant_or_company_is_refused(): void
    {
        $product = $this->product('LOT-P8', batchTracked: true);
        $this->seedStock($product, '10.0000');
        $lot = $this->seedLot($product, 'LOT-MINE', '10.0000');

        // Re-home the lot to another tenant without touching anything else.
        $otherTenant = Tenant::create([
            'name' => 'Other Lot Tenant',
            'slug' => 'other-lot-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        DB::table('product_batches')->where('id', $lot->id)->update(['tenant_id' => $otherTenant->id]);

        $this->expectException(BatchNotApplicableException::class);
        $this->draft([
            $this->line($product, MovementReason::AdjustmentNegative, '-2.0000', '10.0000', $lot->uuid),
        ]);
    }

    // ------------------------------------------------- door 1 + the invariant

    /**
     * DOOR 1: `requires_batch_tracking = true` with ZERO lots — the pharmacy /
     * parapharmacy ONBOARDING case, and the modal's most common use (found
     * stock). Under a flag-keyed lot-required rule the picker would be empty and
     * the line unauthorable. A positive line never requires a lot; the writer
     * lands it in the DEFAULT lot BY THE DELTA.
     */
    public function test_door_one_a_positive_line_with_no_lot_is_accepted_and_creates_the_default_lot(): void
    {
        $product = $this->product('LOT-DOOR1', batchTracked: true);
        $this->seedStock($product, '0.0000');
        $this->assertSame(0, Batch::where('product_id', $product->id)->count());

        $adjustment = $this->draft([
            $this->line($product, MovementReason::AdjustmentPositive, '12.0000', '0.0000'),
        ]);
        $this->service->post($adjustment->id, $this->user->id);

        $this->assertSame('12.0000', (string) $this->level($product)->quantity);

        $default = Batch::query()
            ->where('product_id', $product->id)
            ->where('batch_number', 'DEFAULT')
            ->firstOrFail();

        $this->assertSame('12.0000', $this->lotQuantity($default));
        $this->assertSame('12.0000', $this->totalLotQuantity($product));
    }

    public function test_two_lines_on_the_same_product_with_different_lots_are_accepted(): void
    {
        $product = $this->product('LOT-MULTI', batchTracked: true);
        $this->seedStock($product, '30.0000');
        $lotA = $this->seedLot($product, 'LOT-M-A', '20.0000');
        $lotB = $this->seedLot($product, 'LOT-M-B', '10.0000');

        $adjustment = $this->draft([
            $this->line($product, MovementReason::AdjustmentNegative, '-5.0000', '30.0000', $lotA->uuid),
            $this->line($product, MovementReason::AdjustmentPositive, '2.0000', '30.0000', $lotB->uuid),
        ]);

        $this->assertCount(2, $adjustment->lines);

        $this->service->post($adjustment->id, $this->user->id);

        // Net −3 on the aggregate, and the lots move with it.
        $this->assertSame('27.0000', (string) $this->level($product)->quantity);
        $this->assertSame('15.0000', $this->lotQuantity($lotA));
        $this->assertSame('12.0000', $this->lotQuantity($lotB));
        $this->assertSame(
            (string) $this->level($product)->quantity,
            $this->totalLotQuantity($product),
            'Sigma BatchStock must equal stock_levels.quantity after a mixed multi-lot post — the invariant T1 pinned as broken.'
        );
    }

    public function test_two_lines_on_the_same_product_and_the_same_lot_are_refused_by_the_partial_unique(): void
    {
        $product = $this->product('LOT-DUP', batchTracked: true);
        $this->seedStock($product, '30.0000');
        $lot = $this->seedLot($product, 'LOT-DUP-A', '30.0000');

        $this->expectException(QueryException::class);
        $this->draft([
            $this->line($product, MovementReason::AdjustmentNegative, '-5.0000', '30.0000', $lot->uuid),
            $this->line($product, MovementReason::AdjustmentNegative, '-2.0000', '30.0000', $lot->uuid),
        ]);
    }

    public function test_two_lot_less_lines_on_the_same_product_are_refused_by_the_partial_unique(): void
    {
        $product = $this->product('LOT-DUP2', batchTracked: false);
        $this->seedStock($product, '30.0000');

        $this->expectException(QueryException::class);
        $this->draft([
            $this->line($product, MovementReason::AdjustmentPositive, '5.0000', '30.0000'),
            $this->line($product, MovementReason::AdjustmentPositive, '2.0000', '30.0000'),
        ]);
    }

    // ------------------------------------------------------------- fixtures

    private function product(string $sku, bool $batchTracked): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => "Product {$sku}",
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => $batchTracked,
            'default_shelf_life_days' => 180,
        ]);
    }

    /**
     * @param  list<StockAdjustmentLineInput>  $lines
     */
    private function draft(array $lines): StockAdjustment
    {
        return DB::transaction(fn (): StockAdjustment => $this->service->createDraft(new CreateStockAdjustmentData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $this->warehouse->id,
            createdByUserId: $this->user->id,
            lines: $lines,
        )));
    }

    private function line(
        Product $product,
        MovementReason $reason,
        string $delta,
        string $observedBefore,
        ?string $batchUuid = null,
    ): StockAdjustmentLineInput {
        return new StockAdjustmentLineInput(
            productId: $product->id,
            variantId: null,
            batchUuid: $batchUuid,
            reasonCode: $reason,
            deltaQuantity: $delta,
            observedBefore: $observedBefore,
        );
    }

    private function seedStock(Product $product, string $quantity): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    private function seedLot(Product $product, string $batchNumber, string $quantity, ?Location $location = null): Batch
    {
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'batch_number' => $batchNumber,
            'expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => ($location ?? $this->warehouse)->id,
            'quantity' => $quantity,
            'reserved_quantity' => '0.0000',
        ]);

        return $batch;
    }

    private function level(Product $product): StockLevel
    {
        return StockLevel::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->warehouse->id)
            ->firstOrFail();
    }

    private function lotQuantity(Batch $batch): string
    {
        return (string) BatchStock::query()
            ->where('batch_id', $batch->id)
            ->where('location_id', $this->warehouse->id)
            ->firstOrFail()
            ->quantity;
    }

    private function totalLotQuantity(Product $product): string
    {
        $total = '0.0000';

        $rows = BatchStock::query()
            ->whereIn('batch_id', Batch::query()->where('product_id', $product->id)->pluck('id'))
            ->where('location_id', $this->warehouse->id)
            ->get();

        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->quantity, 4); // precision-ok: batch quantity is decimal(15,4), canonical scale 4
        }

        return $total;
    }
}
