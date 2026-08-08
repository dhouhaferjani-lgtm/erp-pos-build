<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Application\DTOs\SupplierGoodsReturnLineData;
use App\Modules\Inventory\Application\Services\SupplierGoodsReturnNoteService;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Enums\SupplierGoodsReturnLineKind;
use App\Modules\Inventory\Domain\Enums\SupplierGoodsReturnNoteStatus;
use App\Modules\Inventory\Domain\Exceptions\BatchTrackedReturnUnsupportedException;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\SupplierGoodsReturnNote;
use App\Modules\Inventory\Domain\SupplierGoodsReturnNoteLine;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use App\Shared\Exceptions\UnboundCompanyContextException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V8 — the supplier goods-return note, the document that justifies a stock exit
 * back to a supplier.
 *
 * Before V8, `SupplierCreditNotePostingService::issueBonusReturnStock()` raw-wrote
 * a `StockLevel` decrement plus an `Issue` movement on a MONEY document's
 * lifecycle, and stamped `avg_cost_before = avg_cost_after = current WAC` — an
 * assertion that returning a ZERO-COST bonus unit leaves the weighted average
 * cost untouched. It does not: the free unit DILUTED the WAC on entry
 * (`GoodsReceiptService` receives free quantity via
 * `recordPurchase(landedUnitCost: '0')`), so handing it back must UN-dilute it.
 *
 * THE UN-DILUTION IS BOUNDED (gate round 1, C-1). Restoring the full value the
 * exiting units carry is only correct while nothing else has left since the bonus
 * receipt. Once units have gone, their share of the dilution left with them
 * through COGS, and pushing the whole amount onto the survivors double-counts it —
 * unboundedly as the survivor count shrinks (the gate probed 9.523808 against a
 * true 5.000000, +90%). So the restored value is capped by the headroom between
 * the current WAC and the price actually paid for the goods on the causing
 * receipt, and the un-restorable remainder is recorded on the note line rather
 * than silently absorbed.
 */
final class SupplierGoodsReturnNoteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The paid landed unit cost every fixture receipt in this file uses, and
     * therefore the ceiling the un-dilution may never push the WAC above.
     */
    private const string PAID_UNIT_COST = '5.000000';

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'V8 SGR Tenant',
            'slug' => 'v8-sgr-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'V8 SGR Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        // WeightedAverageCostService::scale() resolves the currency scale from the
        // bound company (no-arg getScale()). The real flow runs behind
        // CompanyContextMiddleware. Two tests below CLEAR this again on purpose,
        // to pin the worker-reality behaviour of both line kinds (CLAUDE rule 20).
        app(CompanyContext::class)->setCompanyId($this->company->id);

        // `locations.code` is varchar(20) — keep fixture codes short (the S0 lane
        // proved long fixture codes are green on SQLite and 22001 on PostgreSQL).
        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'V8 Main',
            'code' => 'V8-MAIN',
            'type' => LocationType::Warehouse,
            'is_default' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function service(): SupplierGoodsReturnNoteService
    {
        return app(SupplierGoodsReturnNoteService::class);
    }

    private function wac(): WeightedAverageCostService
    {
        return app(WeightedAverageCostService::class);
    }

    private function stock(): StockAdjustmentService
    {
        return app(StockAdjustmentService::class);
    }

    private function product(string $name = 'V8 Product', bool $batchTracked = false): Product
    {
        /** @var Product $product */
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => $name,
            'cost_price' => '0.000000',
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => $batchTracked,
        ]);

        return $product;
    }

    /**
     * A product whose WAC was built by a REAL dilution sequence rather than a
     * hand-set `cost_price` (gate finding I-3): `paidQty` units at
     * PAID_UNIT_COST, then `freeQty` units at zero — exactly what
     * `GoodsReceiptService` does for a bonus line.
     *
     * @param  numeric-string  $paidQty
     * @param  numeric-string  $freeQty
     */
    private function dilutedProduct(string $paidQty, string $freeQty, string $name = 'V8 Product'): Product
    {
        $product = $this->product($name);

        $this->wac()->recordPurchase(
            product: $product,
            location: $this->location,
            quantity: $paidQty,
            landedUnitCost: self::PAID_UNIT_COST,
        );

        $this->wac()->recordPurchase(
            product: $product,
            location: $this->location,
            quantity: $freeQty,
            landedUnitCost: '0',
        );

        /** @var Product $fresh */
        $fresh = $product->fresh();

        return $fresh;
    }

    /**
     * @param  numeric-string  $costPrice
     * @param  numeric-string  $onHand
     * @param  numeric-string  $reserved
     */
    private function stockedProduct(
        string $costPrice,
        string $onHand,
        string $name = 'V8 Product',
        string $reserved = '0.0000',
        ?Location $location = null,
        bool $batchTracked = false,
    ): Product {
        $product = $this->product($name, $batchTracked);
        $product->forceFill(['cost_price' => $costPrice])->save();

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => ($location ?? $this->location)->id,
            'quantity' => $onHand,
            'reserved' => $reserved,
        ]);

        /** @var Product $fresh */
        $fresh = $product->fresh();

        return $fresh;
    }

    /**
     * @param  numeric-string  $quantity
     */
    private function lineData(
        Product $product,
        SupplierGoodsReturnLineKind $kind,
        string $quantity,
        ?string $poLineId = null,
        ?string $unitCostCeiling = self::PAID_UNIT_COST,
        ?string $preferredLocationId = null,
    ): SupplierGoodsReturnLineData {
        return new SupplierGoodsReturnLineData(
            poLineId: $poLineId ?? Str::uuid()->toString(),
            productId: (string) $product->id,
            variantId: null,
            kind: $kind,
            quantity: $quantity,
            unitCostCeiling: $unitCostCeiling,
            goodsReceiptId: null,
            goodsReceiptLineId: null,
            preferredLocationId: $preferredLocationId ?? $this->location->id,
        );
    }

    /**
     * @param  list<SupplierGoodsReturnLineData>  $lines
     */
    private function draft(array $lines, ?string $creditNoteId = null): SupplierGoodsReturnNote
    {
        return $this->service()->createDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            partnerId: null,
            supplierCreditNoteId: $creditNoteId ?? Str::uuid()->toString(),
            reference: 'SCN-V8-TEST',
            lines: $lines,
            actorId: null,
        );
    }

    private function freshStock(Product $product, ?Location $location = null): string
    {
        /** @var StockLevel $level */
        $level = StockLevel::query()
            ->where('product_id', $product->id)
            ->where('location_id', ($location ?? $this->location)->id)
            ->firstOrFail();

        return (string) $level->quantity;
    }

    private function freshCost(Product $product): string
    {
        /** @var Product $fresh */
        $fresh = $product->fresh();

        return (string) $fresh->cost_price;
    }

    private function bonusLineOf(SupplierGoodsReturnNote $note): SupplierGoodsReturnNoteLine
    {
        /** @var SupplierGoodsReturnNoteLine $line */
        $line = $note->lines->firstWhere('kind', SupplierGoodsReturnLineKind::Bonus);

        return $line;
    }

    // -------------------------------------------------------------------------
    // 1. Draft moves nothing
    // -------------------------------------------------------------------------

    public function test_a_draft_note_moves_no_stock_and_carries_no_number(): void
    {
        $product = $this->stockedProduct('5.000000', '10.0000');

        $note = $this->draft([$this->lineData($product, SupplierGoodsReturnLineKind::Ordinary, '2.0000')]);

        $this->assertSame(SupplierGoodsReturnNoteStatus::Draft, $note->status);
        $this->assertNull($note->note_number);
        $this->assertNull($note->returned_at);
        $this->assertSame('10.0000', $this->freshStock($product));
        $this->assertSame(0, StockMovement::query()->where('product_id', $product->id)->count());
        $this->assertCount(1, $note->lines);
    }

    // -------------------------------------------------------------------------
    // 2. Confirm issues stock through the seam, linked to the NOTE
    // -------------------------------------------------------------------------

    public function test_confirming_a_note_issues_stock_linked_to_the_note_and_stamps_a_number(): void
    {
        $product = $this->stockedProduct('5.000000', '10.0000');

        $note = $this->service()->confirm(
            $this->draft([$this->lineData($product, SupplierGoodsReturnLineKind::Ordinary, '2.0000')]),
            null,
        );

        $this->assertSame(SupplierGoodsReturnNoteStatus::Confirmed, $note->status);
        $this->assertNotNull($note->note_number);
        $this->assertMatchesRegularExpression('/^SGR-\d{4}-\d{4}$/', (string) $note->note_number);
        $this->assertNotNull($note->returned_at);

        $this->assertSame('8.0000', $this->freshStock($product));
        // A paid unit leaves at WAC: the WAC itself is unchanged (correct).
        $this->assertSame('5.000000', $this->freshCost($product));

        /** @var StockMovement $movement */
        $movement = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('movement_type', MovementType::Issue)
            ->firstOrFail();

        $this->assertSame(
            StockMovementReferenceType::SupplierGoodsReturnNote->value,
            $movement->reference_type,
        );
        $this->assertSame($note->id, $movement->reference_id);
        $this->assertSame(MovementReason::SupplierReturn, $movement->reason);
        $this->assertSame('5.000000', (string) $movement->unit_cost);
        $this->assertSame('10.0000', (string) $movement->quantity_before);
        $this->assertSame('8.0000', (string) $movement->quantity_after);

        /** @var SupplierGoodsReturnNoteLine $line */
        $line = $note->lines->firstOrFail();
        $this->assertSame($movement->id, $line->movement_id);
        $this->assertNull($line->cost_adjustment_movement_id);
        $this->assertSame('5.000000', (string) $line->unit_cost);
        $this->assertSame($this->location->id, $line->location_id);
        // Ordinary lines never un-dilute: both audit figures stay null.
        $this->assertNull($line->wac_undilution_applied);
        $this->assertNull($line->wac_undilution_forgone);
    }

    // -------------------------------------------------------------------------
    // 3. THE POINT OF THE LANE: zero-cost bonus goods RAISE the remaining WAC —
    //    proven over a REAL dilution sequence, not a hand-set cost_price (I-3).
    // -------------------------------------------------------------------------

    public function test_a_real_receipt_dilution_round_trip_restores_the_paid_unit_cost(): void
    {
        // 20 paid @ 5.000000 then 1 free @ 0 — exactly GoodsReceiptService's
        // bonus-line path. 100 / 21 = 4.761904 (bcdiv truncates).
        $product = $this->dilutedProduct('20.0000', '1.0000');
        $this->assertSame('4.761904', $this->freshCost($product));
        $this->assertSame('21.0000', $this->freshStock($product));

        $note = $this->service()->confirm(
            $this->draft([$this->lineData($product, SupplierGoodsReturnLineKind::Bonus, '1.0000')]),
            null,
        );

        $this->assertSame('20.0000', $this->freshStock($product));

        // 4.761904 + 4.761904/20 = 4.9999992 -> truncated to COST_SCALE=6.
        // ONE ULP short of the 5.000000 the paid units cost: the missing 0.000016
        // was truncated away by the WAC blend at the free receipt and the model
        // never restores more value than the exiting units carry. The OLD raw path
        // asserted avg_cost_before == avg_cost_after == 4.761904 and left
        // cost_price alone.
        $this->assertSame('4.999999', $this->freshCost($product));

        /** @var StockMovement $issue */
        $issue = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('movement_type', MovementType::Issue)
            ->firstOrFail();
        $this->assertSame('4.761904', (string) $issue->unit_cost);
        $this->assertSame($note->id, $issue->reference_id);

        /** @var StockMovement $adjustment */
        $adjustment = StockMovement::query()
            ->where('reference_id', $note->id)
            ->where('movement_type', MovementType::Adjustment)
            ->firstOrFail();
        $this->assertSame('0.0000', (string) $adjustment->quantity);
        $this->assertSame('4.761904', (string) $adjustment->avg_cost_before);
        $this->assertSame('4.999999', (string) $adjustment->avg_cost_after);
        $this->assertSame(
            StockMovementReferenceType::SupplierGoodsReturnNote->value,
            $adjustment->reference_type,
        );

        $line = $this->bonusLineOf($note);
        $this->assertSame($issue->id, $line->movement_id);
        $this->assertSame($adjustment->id, $line->cost_adjustment_movement_id);
        // Nothing had left since the receipt, so the whole carried value was
        // restorable and nothing was forgone.
        $this->assertSame('4.761904', (string) $line->wac_undilution_applied);
        $this->assertSame('0.000000', (string) $line->wac_undilution_forgone);
    }

    // -------------------------------------------------------------------------
    // 4. CRITICAL C-1 — the un-dilution must be BOUNDED
    // -------------------------------------------------------------------------

    /**
     * Gate probe 2. A credit note returning PAID and BONUS units of the same
     * product is a shape `SupplierCreditNotePostingService` builds by design, and
     * the unbounded model inflated `cost_price` to 5.238094 against a true
     * 5.000000 (+4.8%).
     */
    public function test_a_mixed_paid_and_bonus_note_does_not_inflate_the_cost_price(): void
    {
        $product = $this->dilutedProduct('20.0000', '1.0000');

        $note = $this->service()->confirm(
            $this->draft([
                $this->lineData($product, SupplierGoodsReturnLineKind::Ordinary, '10.0000'),
                $this->lineData($product, SupplierGoodsReturnLineKind::Bonus, '1.0000'),
            ]),
            null,
        );

        $this->assertSame('10.0000', $this->freshStock($product));
        $this->assertSame('5.000000', $this->freshCost($product));

        $line = $this->bonusLineOf($note);
        // Carried value 4.761904; the 10 survivors could only absorb
        // 10 x (5.000000 - 4.761904) = 2.380960 without exceeding what was paid.
        $this->assertSame('2.380960', (string) $line->wac_undilution_applied);
        $this->assertSame('2.380944', (string) $line->wac_undilution_forgone);
    }

    /**
     * Gate probe 3 — the extreme. 19 of the 21 units were issued before the
     * return, so most of the dilution already left through COGS. The unbounded
     * model produced 9.523808 (+90%).
     */
    public function test_a_bonus_return_after_most_stock_has_been_issued_is_bounded_by_the_paid_cost(): void
    {
        $product = $this->dilutedProduct('20.0000', '1.0000');

        $this->stock()->issue(
            productId: (string) $product->id,
            locationId: $this->location->id,
            quantity: '19.0000',
            reference: 'V8-PRE-SALE',
            userId: null,
            expectedCompanyId: $this->company->id,
        );
        $this->assertSame('2.0000', $this->freshStock($product));
        $this->assertSame('4.761904', $this->freshCost($product));

        $note = $this->service()->confirm(
            $this->draft([$this->lineData($product, SupplierGoodsReturnLineKind::Bonus, '1.0000')]),
            null,
        );

        $this->assertSame('1.0000', $this->freshStock($product));
        // The single survivor may be valued at what it cost — never more.
        $this->assertSame('5.000000', $this->freshCost($product));

        $line = $this->bonusLineOf($note);
        $this->assertSame('0.238096', (string) $line->wac_undilution_applied);
        $this->assertSame('4.523808', (string) $line->wac_undilution_forgone);
    }

    /**
     * Gate probe 4 (also finding I-5). A full bonus return leaves NO survivor to
     * capitalize against — `recordCostAdjustment` documents that no-op. The WAC
     * stays diluted, which is unavoidable, but it must be RECORDED, not left as
     * an unexplained NULL.
     */
    public function test_a_full_bonus_return_records_the_forgone_undilution(): void
    {
        $product = $this->dilutedProduct('20.0000', '1.0000');

        $this->stock()->issue(
            productId: (string) $product->id,
            locationId: $this->location->id,
            quantity: '20.0000',
            reference: 'V8-PRE-SALE',
            userId: null,
            expectedCompanyId: $this->company->id,
        );
        $this->assertSame('1.0000', $this->freshStock($product));

        $note = $this->service()->confirm(
            $this->draft([$this->lineData($product, SupplierGoodsReturnLineKind::Bonus, '1.0000')]),
            null,
        );

        $this->assertSame('0.0000', $this->freshStock($product));
        $this->assertSame('4.761904', $this->freshCost($product));

        $line = $this->bonusLineOf($note);
        $this->assertNull($line->cost_adjustment_movement_id);
        $this->assertSame('0.000000', (string) $line->wac_undilution_applied);
        $this->assertSame('4.761904', (string) $line->wac_undilution_forgone);
        $this->assertSame(
            0,
            StockMovement::query()
                ->where('reference_id', $note->id)
                ->where('movement_type', MovementType::Adjustment)
                ->count(),
        );
    }

    /**
     * The bound is not optional: without a ceiling the service cannot tell a
     * legitimate un-dilution from an unbounded inflation, so it refuses rather
     * than falling back to the unbounded model.
     */
    public function test_a_bonus_line_without_a_cost_ceiling_is_refused(): void
    {
        $product = $this->dilutedProduct('20.0000', '1.0000');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('cost ceiling');

        $this->draft([
            $this->lineData($product, SupplierGoodsReturnLineKind::Bonus, '1.0000', unitCostCeiling: null),
        ]);
    }

    /**
     * A WAC already at or above what was paid has no headroom, so a bonus return
     * must not raise it further. Conservative by design — it can never inflate.
     */
    public function test_a_bonus_return_with_no_headroom_leaves_the_wac_untouched(): void
    {
        // Hand-set: an earlier, more expensive receipt left the WAC above this
        // receipt's paid cost.
        $product = $this->stockedProduct('6.000000', '10.0000');

        $note = $this->service()->confirm(
            $this->draft([$this->lineData($product, SupplierGoodsReturnLineKind::Bonus, '1.0000')]),
            null,
        );

        $this->assertSame('9.0000', $this->freshStock($product));
        $this->assertSame('6.000000', $this->freshCost($product));

        $line = $this->bonusLineOf($note);
        $this->assertSame('0.000000', (string) $line->wac_undilution_applied);
        $this->assertSame('6.000000', (string) $line->wac_undilution_forgone);
    }

    // -------------------------------------------------------------------------
    // 5. CRITICAL C-2 — batch-tracked products are refused, not silently desynced
    // -------------------------------------------------------------------------

    public function test_a_batch_tracked_product_is_refused_until_lot_selection_exists(): void
    {
        $product = $this->stockedProduct('5.000000', '10.0000', batchTracked: true);
        $note = $this->draft([$this->lineData($product, SupplierGoodsReturnLineKind::Ordinary, '2.0000')]);

        try {
            $this->service()->confirm($note, null);
            $this->fail('A batch-tracked product must be refused on the goods-return path.');
        } catch (BatchTrackedReturnUnsupportedException $e) {
            $this->assertStringContainsString((string) $product->id, $e->getMessage());
        }

        // Atomic refusal: nothing moved, the note is still Draft and unnumbered.
        /** @var SupplierGoodsReturnNote $fresh */
        $fresh = $note->fresh();
        $this->assertSame(SupplierGoodsReturnNoteStatus::Draft, $fresh->status);
        $this->assertNull($fresh->note_number);
        $this->assertSame('10.0000', $this->freshStock($product));
        $this->assertSame(0, StockMovement::query()->where('reference_id', $note->id)->count());
    }

    // -------------------------------------------------------------------------
    // 6. Both line kinds on ONE note
    // -------------------------------------------------------------------------

    public function test_a_single_note_carries_both_ordinary_and_bonus_lines(): void
    {
        $paid = $this->stockedProduct('5.000000', '10.0000', 'V8 Paid');
        $bonus = $this->dilutedProduct('20.0000', '1.0000', 'V8 Bonus');

        $note = $this->service()->confirm(
            $this->draft([
                $this->lineData($paid, SupplierGoodsReturnLineKind::Ordinary, '2.0000'),
                $this->lineData($bonus, SupplierGoodsReturnLineKind::Bonus, '1.0000'),
            ]),
            null,
        );

        $this->assertCount(2, $note->lines);
        $this->assertSame('8.0000', $this->freshStock($paid));
        $this->assertSame('20.0000', $this->freshStock($bonus));

        // Ordinary: WAC untouched. Bonus: WAC un-diluted.
        $this->assertSame('5.000000', $this->freshCost($paid));
        $this->assertSame('4.999999', $this->freshCost($bonus));

        // Exactly one adjustment movement in the whole note — the bonus one.
        $this->assertSame(
            1,
            StockMovement::query()
                ->where('reference_id', $note->id)
                ->where('movement_type', MovementType::Adjustment)
                ->count(),
        );
        $this->assertSame(
            2,
            StockMovement::query()
                ->where('reference_id', $note->id)
                ->where('movement_type', MovementType::Issue)
                ->count(),
        );
    }

    // -------------------------------------------------------------------------
    // 7. Exit-location selection respects reservations (I-2)
    // -------------------------------------------------------------------------

    public function test_the_exit_location_is_chosen_on_available_not_raw_quantity(): void
    {
        $overflow = Location::create([
            'company_id' => $this->company->id,
            'name' => 'V8 Overflow',
            'code' => 'V8-OVF',
            'type' => LocationType::Warehouse,
            'is_default' => false,
        ]);

        // Biggest RAW quantity, fully reserved for a customer order.
        $product = $this->stockedProduct('5.000000', '10.0000', reserved: '10.0000');
        // Smaller, but actually available.
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $overflow->id,
            'quantity' => '4.0000',
            'reserved' => '0.0000',
        ]);

        $note = $this->service()->confirm(
            $this->draft([
                $this->lineData(
                    $product,
                    SupplierGoodsReturnLineKind::Ordinary,
                    '2.0000',
                    // The hint points at the fully reserved location.
                    preferredLocationId: $this->location->id,
                ),
            ]),
            null,
        );

        // The reserved location was NOT touched; the available one was.
        $this->assertSame('10.0000', $this->freshStock($product, $this->location));
        $this->assertSame('2.0000', $this->freshStock($product, $overflow));

        /** @var SupplierGoodsReturnNoteLine $line */
        $line = $note->lines->firstOrFail();
        $this->assertSame($overflow->id, $line->location_id);
    }

    // -------------------------------------------------------------------------
    // 8. Idempotency, and the Draft-reuse hole (I-4)
    // -------------------------------------------------------------------------

    public function test_confirming_twice_issues_stock_only_once(): void
    {
        $product = $this->stockedProduct('5.000000', '10.0000');

        $note = $this->service()->confirm(
            $this->draft([$this->lineData($product, SupplierGoodsReturnLineKind::Ordinary, '2.0000')]),
            null,
        );
        $numberAfterFirst = $note->note_number;

        $again = $this->service()->confirm($note, null);

        $this->assertSame(SupplierGoodsReturnNoteStatus::Confirmed, $again->status);
        $this->assertSame($numberAfterFirst, $again->note_number);
        $this->assertSame('8.0000', $this->freshStock($product));
        $this->assertSame(1, StockMovement::query()->where('reference_id', $note->id)->count());
    }

    public function test_drafting_twice_with_the_same_line_set_returns_the_same_note(): void
    {
        $product = $this->stockedProduct('5.000000', '10.0000');
        $creditNoteId = Str::uuid()->toString();
        $poLineId = Str::uuid()->toString();

        $lines = [$this->lineData($product, SupplierGoodsReturnLineKind::Ordinary, '2.0000', poLineId: $poLineId)];

        $first = $this->draft($lines, $creditNoteId);
        $second = $this->draft($lines, $creditNoteId);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SupplierGoodsReturnNote::query()->count());
        $this->assertSame(1, SupplierGoodsReturnNoteLine::query()->count());
    }

    /**
     * I-4. Reusing a lingering Draft while DISCARDING the caller's freshly
     * computed lines is how the deferred guided-AP-modal lane would silently
     * confirm stale quantities after the counters had been decremented for new
     * ones. Refuse instead.
     */
    public function test_redrafting_the_same_credit_note_with_a_different_line_set_is_refused(): void
    {
        $product = $this->stockedProduct('5.000000', '10.0000');
        $creditNoteId = Str::uuid()->toString();
        $poLineId = Str::uuid()->toString();

        $this->draft(
            [$this->lineData($product, SupplierGoodsReturnLineKind::Ordinary, '2.0000', poLineId: $poLineId)],
            $creditNoteId,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('does not match');

        $this->draft(
            [$this->lineData($product, SupplierGoodsReturnLineKind::Ordinary, '3.0000', poLineId: $poLineId)],
            $creditNoteId,
        );
    }

    // -------------------------------------------------------------------------
    // 9. Refusals are explicit and roll back
    // -------------------------------------------------------------------------

    public function test_confirming_more_than_on_hand_throws_and_leaves_the_note_draft(): void
    {
        $product = $this->stockedProduct('5.000000', '1.0000');
        $note = $this->draft([$this->lineData($product, SupplierGoodsReturnLineKind::Ordinary, '2.0000')]);

        $this->expectException(InsufficientStockException::class);

        try {
            $this->service()->confirm($note, null);
        } finally {
            /** @var SupplierGoodsReturnNote $fresh */
            $fresh = $note->fresh();
            $this->assertSame(SupplierGoodsReturnNoteStatus::Draft, $fresh->status);
            $this->assertNull($fresh->note_number);
            $this->assertSame('1.0000', $this->freshStock($product));
            $this->assertSame(0, StockMovement::query()->where('reference_id', $note->id)->count());
        }
    }

    public function test_a_note_with_no_resolvable_stock_location_is_refused(): void
    {
        $product = $this->product('V8 No Stock Row');

        $note = $this->draft([$this->lineData(
            $product,
            SupplierGoodsReturnLineKind::Ordinary,
            '1.0000',
            preferredLocationId: null,
        )]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('no stock location');

        $this->service()->confirm($note, null);
    }

    public function test_a_note_without_lines_is_refused(): void
    {
        $this->expectException(\DomainException::class);

        $this->draft([]);
    }

    // -------------------------------------------------------------------------
    // 10. The worker reality: no CompanyContext bound (M-2, CLAUDE rule 20)
    // -------------------------------------------------------------------------

    public function test_an_ordinary_only_note_confirms_with_no_company_context(): void
    {
        $product = $this->stockedProduct('5.000000', '10.0000');
        $note = $this->draft([$this->lineData($product, SupplierGoodsReturnLineKind::Ordinary, '2.0000')]);

        app(CompanyContext::class)->clear();

        $confirmed = $this->service()->confirm($note, null);

        $this->assertSame(SupplierGoodsReturnNoteStatus::Confirmed, $confirmed->status);
        $this->assertSame('8.0000', $this->freshStock($product));
    }

    public function test_a_bonus_note_with_no_company_context_throws_and_rolls_back(): void
    {
        $product = $this->stockedProduct('4.761904', '21.0000');
        $note = $this->draft([$this->lineData($product, SupplierGoodsReturnLineKind::Bonus, '1.0000')]);

        app(CompanyContext::class)->clear();

        $this->expectException(UnboundCompanyContextException::class);

        try {
            $this->service()->confirm($note, null);
        } finally {
            /** @var SupplierGoodsReturnNote $fresh */
            $fresh = $note->fresh();
            $this->assertSame(SupplierGoodsReturnNoteStatus::Draft, $fresh->status);
            $this->assertSame('21.0000', $this->freshStock($product));
        }
    }

    // -------------------------------------------------------------------------
    // 11. The new reference type is renderable (S0 gate finding I-1's contract)
    // -------------------------------------------------------------------------

    /**
     * `EntryExitNoteController::sourceType()` emits the enum's backing value
     * verbatim for every seam-written reference type, and the FE renders it via
     * `entryExitNotes.sourceTypes.<code>`. An unmapped code is shown raw to the
     * user (CLAUDE rule 11), so minting an enum case without both locale keys is
     * a user-visible bug that no other test would catch.
     */
    public function test_the_new_reference_type_has_a_translation_in_both_locales(): void
    {
        $code = StockMovementReferenceType::SupplierGoodsReturnNote->value;

        foreach (['en', 'fr'] as $locale) {
            $path = base_path("../web/src/locales/{$locale}/inventory.json");
            $this->assertFileExists($path);

            /** @var array<string, mixed> $messages */
            $messages = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            $this->assertIsArray($messages['entryExitNotes'] ?? null);
            /** @var array<string, mixed> $entryExitNotes */
            $entryExitNotes = $messages['entryExitNotes'];
            $this->assertIsArray($entryExitNotes['sourceTypes'] ?? null);
            /** @var array<string, mixed> $sourceTypes */
            $sourceTypes = $entryExitNotes['sourceTypes'];

            $this->assertArrayHasKey(
                $code,
                $sourceTypes,
                "entryExitNotes.sourceTypes.{$code} is missing from {$locale}/inventory.json — "
                .'the Entry/Exit Notes screen would render the raw code.',
            );
        }
    }
}
