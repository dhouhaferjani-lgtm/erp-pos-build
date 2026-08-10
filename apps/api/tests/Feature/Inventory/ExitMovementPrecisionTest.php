<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\ReturnNoteService;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\BuildsWave3ExitFixtures;

/**
 * DPA Wave 3 · sub-wave 3A · **T3 — no float on the exit/entry quantity and cost path**.
 *
 * `recordSale(float $quantity)`, `recordReturn(float $quantity, float $originalCost)`
 * and `ReturnNoteService::getOriginalCost(): float` funnel a `decimal(15,4)`
 * quantity and a `decimal(19,6)` cost through a binary float. `CurrencyScale::bcformat()`
 * salvages what it can (`number_format($value, max($scale, 14))`), but a float
 * carries ~15-16 significant decimal digits and those columns carry up to 19 —
 * so the damage is silent and *inside* the stored scale, not beyond it.
 *
 * The two witnesses below are ordinary-magnitude values, not synthetic extremes:
 *
 *   quantity `1234567.8901` → via float `1234567.8900` (a whole unit at the 4th dp)
 *   cost     `123456.789012` → via float `123456.789011`
 *
 * Both are comfortably inside `numeric(15,4)` / `numeric(19,6)`.
 *
 * House rule 19: never let a float touch money or quantity.
 */
final class ExitMovementPrecisionTest extends TestCase
{
    use BuildsWave3ExitFixtures;
    use RefreshDatabase;

    /**
     * A quantity whose 4th decimal does NOT survive a float round-trip through
     * `CurrencyScale::bcformat((float) $q, 4)`.
     */
    private const FLOAT_HOSTILE_QUANTITY = '1234567.8901';

    /**
     * A unit cost whose 6th decimal does NOT survive a float round-trip through
     * `CurrencyScale::bcformat((float) $c, 6)`.
     */
    private const FLOAT_HOSTILE_COST = '123456.789012';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootWave3ExitFixtures();
    }

    /**
     * Guards the witnesses themselves: if a future PHP/bcmath change made these
     * values float-safe, the two tests below would pass for the wrong reason.
     */
    public function test_the_witness_values_really_are_float_hostile(): void
    {
        self::assertNotSame(
            CurrencyScale::bcformat(self::FLOAT_HOSTILE_QUANTITY, 4),
            CurrencyScale::bcformat((float) self::FLOAT_HOSTILE_QUANTITY, 4),
            'quantity witness must diverge under a float round-trip',
        );
        self::assertNotSame(
            CurrencyScale::bcformat(self::FLOAT_HOSTILE_COST, 6),
            CurrencyScale::bcformat((float) self::FLOAT_HOSTILE_COST, 6),
            'cost witness must diverge under a float round-trip',
        );
    }

    public function test_a_delivery_note_quantity_keeps_its_fourth_decimal_end_to_end(): void
    {
        $product = $this->physicalProduct(costPrice: '1.000000');
        $stock = $this->seedStock($product->id, '2000000.0000');

        $deliveryNote = $this->confirmedDeliveryNoteFor($product, quantity: self::FLOAT_HOSTILE_QUANTITY);

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->where('reference_id', $deliveryNote->id)->firstOrFail();

        self::assertSame(
            '-'.self::FLOAT_HOSTILE_QUANTITY,
            $this->numericString($movement->quantity),
            'the exit magnitude must be the document quantity, exactly',
        );
        self::assertSame(
            bcsub('2000000.0000', self::FLOAT_HOSTILE_QUANTITY, 4),
            $this->numericString($movement->quantity_after),
        );

        // …and it propagates into the cost ledger: qty x 1.000000.
        self::assertSame(
            bcmul(self::FLOAT_HOSTILE_QUANTITY, '1.000000', 6),
            $this->numericString($movement->total_cost),
            'total_cost is the exact bcmul of a 4-dp quantity and a 6-dp cost',
        );

        $stock->refresh();
        self::assertSame(
            bcsub('2000000.0000', self::FLOAT_HOSTILE_QUANTITY, 4),
            $this->numericString($stock->quantity),
        );
    }

    public function test_a_return_note_original_cost_keeps_all_six_decimals(): void
    {
        $product = $this->physicalProduct(costPrice: '1.000000');
        $this->seedStock($product->id, '100.0000');

        // The source document's line carries the landed cost `getOriginalCost()`
        // reads. NOTE (§0b.3): on production data this branch is dead — no sales
        // invoice or DN line is ever written with `landed_unit_cost` — so this
        // fixture writes it deliberately to exercise the arm T3 changes. The
        // dead-branch problem itself belongs to D-24/T15a, not here.
        $source = $this->draftDocument(DocumentType::Invoice, $product, '10.0000', 'SRC');
        DocumentLine::query()
            ->where('document_id', $source->id)
            ->update(['landed_unit_cost' => self::FLOAT_HOSTILE_COST]);

        $returnNote = $this->returnNoteAgainst($source, $product, '1.0000');
        $this->app->make(ReturnNoteService::class)->confirm($returnNote);

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->where('reference_id', $returnNote->id)->firstOrFail();

        self::assertSame(
            self::FLOAT_HOSTILE_COST,
            $this->numericString($movement->unit_cost),
            'the returned units must re-enter at the exact landed cost, all 6 dp',
        );
        self::assertSame(
            bcmul('1.0000', self::FLOAT_HOSTILE_COST, 6),
            $this->numericString($movement->total_cost),
        );
    }

    public function test_a_sub_unit_return_quantity_survives_the_entry_path(): void
    {
        $product = $this->physicalProduct(costPrice: '1.000000');
        $stock = $this->seedStock($product->id, '0.0000');

        $returnNote = $this->confirmedReturnNoteFor($product, quantity: '0.0001');

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->where('reference_id', $returnNote->id)->firstOrFail();

        self::assertSame('0.0001', $this->numericString($movement->quantity));

        $stock->refresh();
        self::assertSame('0.0001', $this->numericString($stock->quantity));
    }

    /**
     * A draft return note whose `source_document_id` points at `$source`, so
     * `ReturnNoteService::getOriginalCost()` takes the landed-cost branch.
     */
    private function returnNoteAgainst(Document $source, Product $product, string $quantity): Document
    {
        /** @var numeric-string $quantity */
        $lineTotal = bcmul($quantity, '100.00', 2);

        $returnNote = Document::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->companyId,
            'tenant_id' => $this->tenantId,
            'type' => DocumentType::ReturnNote,
            'status' => DocumentStatus::Draft,
            'partner_id' => $this->customer->id,
            'location_id' => $this->locationId,
            'source_document_id' => $source->id,
            'document_number' => 'RN-W3A-'.Str::upper(Str::random(6)),
            'document_date' => now(),
            'currency' => 'EUR',
            'total' => $lineTotal,
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $returnNote->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'location_id' => $this->locationId,
            'description' => $product->name,
            'quantity' => $quantity,
            'unit_price' => '100.00',
            'tax_rate' => 0,
            'line_total' => $lineTotal,
        ]);

        // Sanity: the landed cost really is on the source line the resolver reads.
        self::assertSame(
            self::FLOAT_HOSTILE_COST,
            $this->numericString(
                DB::table('document_lines')->where('document_id', $source->id)->value('landed_unit_cost'),
            ),
        );

        /** @var Document $fresh */
        $fresh = $returnNote->fresh(['lines']);

        return $fresh;
    }
}
