<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Application\DTOs\SupplierGoodsReturnLineData;
use App\Modules\Inventory\Application\Services\SupplierGoodsReturnNoteService;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Enums\SupplierGoodsReturnLineKind;
use App\Modules\Inventory\Domain\Enums\SupplierGoodsReturnNoteStatus;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\SupplierGoodsReturnNote;
use App\Modules\Inventory\Domain\SupplierGoodsReturnNoteLine;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\StockMovementReferenceType;
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
 * The un-dilution is exact and composes two existing, cost-locked primitives:
 *
 *   issue(unitCost: WAC)                  quantity Q -> Q-q, implied value -q*WAC
 *   recordCostAdjustment(+q*WAC)          value restored, spread over Q-q units
 *   => new WAC = WAC + q*WAC/(Q-q) = WAC*Q/(Q-q) = value/(Q-q)
 *
 * i.e. the inventory VALUE is preserved (free goods cost nothing to give back)
 * and the per-unit cost rises to what the paid units actually cost.
 */
final class SupplierGoodsReturnNoteTest extends TestCase
{
    use RefreshDatabase;

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
        // CompanyContextMiddleware; bind it explicitly here rather than let the
        // WAC un-dilution blow up on an UnboundCompanyContextException.
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

    /**
     * @param  numeric-string  $costPrice
     * @param  numeric-string  $onHand
     */
    private function stockedProduct(string $costPrice, string $onHand, string $name = 'V8 Product'): Product
    {
        /** @var Product $product */
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => $name,
            'cost_price' => $costPrice,
            'is_active' => true,
            'is_physical' => true,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => $onHand,
            'reserved' => '0.0000',
        ]);

        return $product;
    }

    /**
     * @param  numeric-string  $quantity
     */
    private function lineData(
        Product $product,
        SupplierGoodsReturnLineKind $kind,
        string $quantity,
    ): SupplierGoodsReturnLineData {
        return new SupplierGoodsReturnLineData(
            poLineId: Str::uuid()->toString(),
            productId: (string) $product->id,
            variantId: null,
            kind: $kind,
            quantity: $quantity,
            goodsReceiptId: null,
            goodsReceiptLineId: null,
            preferredLocationId: $this->location->id,
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

    private function freshStock(Product $product): string
    {
        /** @var StockLevel $level */
        $level = StockLevel::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();

        return (string) $level->quantity;
    }

    private function freshCost(Product $product): string
    {
        /** @var Product $fresh */
        $fresh = $product->fresh();

        return (string) $fresh->cost_price;
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
    }

    // -------------------------------------------------------------------------
    // 3. THE POINT OF THE LANE: zero-cost bonus goods RAISE the remaining WAC
    // -------------------------------------------------------------------------

    public function test_returning_zero_cost_bonus_goods_raises_the_remaining_wac(): void
    {
        // 20 paid units at 5.000 plus 1 free unit blended to 100/21 = 4.761904.
        $product = $this->stockedProduct('4.761904', '21.0000');

        $note = $this->service()->confirm(
            $this->draft([$this->lineData($product, SupplierGoodsReturnLineKind::Bonus, '1.0000')]),
            null,
        );

        $this->assertSame('20.0000', $this->freshStock($product));

        // 4.761904 + 4.761904/20 = 4.9999992 -> truncated to COST_SCALE=6.
        // The OLD raw path asserted avg_cost_before == avg_cost_after == 4.761904.
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
            ->where('product_id', $product->id)
            ->where('movement_type', MovementType::Adjustment)
            ->firstOrFail();
        $this->assertSame('0.0000', (string) $adjustment->quantity);
        $this->assertSame('4.761904', (string) $adjustment->avg_cost_before);
        $this->assertSame('4.999999', (string) $adjustment->avg_cost_after);
        $this->assertSame(
            StockMovementReferenceType::SupplierGoodsReturnNote->value,
            $adjustment->reference_type,
        );
        $this->assertSame($note->id, $adjustment->reference_id);

        /** @var SupplierGoodsReturnNoteLine $line */
        $line = $note->lines->firstOrFail();
        $this->assertSame($issue->id, $line->movement_id);
        $this->assertSame($adjustment->id, $line->cost_adjustment_movement_id);
    }

    // -------------------------------------------------------------------------
    // 4. ONE note carries BOTH line kinds ("one CN reason, two lane behaviors" ends)
    // -------------------------------------------------------------------------

    public function test_a_single_note_carries_both_ordinary_and_bonus_lines(): void
    {
        $paid = $this->stockedProduct('5.000000', '10.0000', 'V8 Paid');
        $bonus = $this->stockedProduct('4.761904', '21.0000', 'V8 Bonus');

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
    // 5. Idempotency
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

    public function test_drafting_twice_for_the_same_credit_note_returns_the_same_note(): void
    {
        $product = $this->stockedProduct('5.000000', '10.0000');
        $creditNoteId = Str::uuid()->toString();

        $first = $this->draft(
            [$this->lineData($product, SupplierGoodsReturnLineKind::Ordinary, '2.0000')],
            $creditNoteId,
        );
        $second = $this->draft(
            [$this->lineData($product, SupplierGoodsReturnLineKind::Ordinary, '2.0000')],
            $creditNoteId,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SupplierGoodsReturnNote::query()->count());
        $this->assertSame(1, SupplierGoodsReturnNoteLine::query()->count());
    }

    // -------------------------------------------------------------------------
    // 6. Refusals are explicit and roll back
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
        /** @var Product $product */
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'V8 No Stock Row',
            'cost_price' => '5.000000',
            'is_active' => true,
            'is_physical' => true,
        ]);

        $note = $this->draft([new SupplierGoodsReturnLineData(
            poLineId: Str::uuid()->toString(),
            productId: (string) $product->id,
            variantId: null,
            kind: SupplierGoodsReturnLineKind::Ordinary,
            quantity: '1.0000',
            goodsReceiptId: null,
            goodsReceiptLineId: null,
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
    // 7. The new reference type is renderable (S0 gate finding I-1's contract)
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
