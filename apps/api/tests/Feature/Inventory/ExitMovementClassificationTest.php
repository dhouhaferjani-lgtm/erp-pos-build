<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Services\FirstCountDetector;
use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\BuildsWave3ExitFixtures;

/**
 * DPA Wave 3 · sub-wave 3A · **T2 — classify the DN/RN exit and entry**.
 *
 * The seam Wave 3 builds keys on `MovementReason::affectsCOGS()`. Today the two
 * WAC document writers leave `reason` NULL (pinned by T1(b)), so the exit is
 * literally unclassifiable — `affectsCOGS()` cannot be asked. This suite pins
 * the post-T2 truth:
 *
 *  - a DN confirm writes `reason = delivery`, and `Delivery->affectsCOGS()` is true;
 *  - an RN confirm writes `reason = customer_return`, and it too affects COGS;
 *  - the PERSISTED `reference_type` is byte-identical to the pre-T2 literal
 *    `'Document'`. That is the regression that matters: `LinkedCostApplicationService`
 *    and `BackfillGoodsReceiptsCommand` both query
 *    `->where('reference_type', 'Document')` literally
 *    (`StockMovementReferenceType.php:43-46`), so typing the parameter must not
 *    change one byte on disk.
 */
final class ExitMovementClassificationTest extends TestCase
{
    use BuildsWave3ExitFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootWave3ExitFixtures();
    }

    public function test_a_delivery_note_confirm_classifies_its_exit_movement_as_a_delivery(): void
    {
        $product = $this->physicalProduct(costPrice: '50.000000');
        $this->seedStock($product->id, '100.0000');

        $deliveryNote = $this->confirmedDeliveryNoteFor($product, quantity: '10.0000');

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->where('reference_id', $deliveryNote->id)->firstOrFail();

        self::assertSame(MovementReason::Delivery, $movement->reason);
        self::assertTrue(
            $movement->reason->affectsCOGS(),
            'The exit seam keys on affectsCOGS(); a NULL or non-COGS reason makes it unreachable.',
        );
        self::assertTrue($movement->reason->requiresGLEntry());
    }

    public function test_a_return_note_confirm_classifies_its_entry_movement_as_a_customer_return(): void
    {
        $product = $this->physicalProduct(costPrice: '50.000000');
        $this->seedStock($product->id, '100.0000');

        $returnNote = $this->confirmedReturnNoteFor($product, quantity: '4.0000');

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->where('reference_id', $returnNote->id)->firstOrFail();

        self::assertSame(MovementReason::CustomerReturn, $movement->reason);
        self::assertTrue($movement->reason->affectsCOGS());
        self::assertTrue($movement->reason->requiresGLEntry());
    }

    /**
     * DISCLOSED CONSEQUENCE of T2 (not a side effect — the completion of an
     * intent that was already written down).
     *
     * `FirstCountDetector`'s supply-side baseline is
     * `receipt AND (reason IS NULL OR reason NOT IN ('pos_return','customer_return'))`,
     * and its docblock states "POS returns (receipt with reason pos_return /
     * customer_return) never establish a baseline". Before T2 the RN entry wrote
     * `reason IS NULL`, so a customer return DID establish a baseline and
     * suppressed the onboarding opening count — the exact case the detector was
     * written to exclude but could never see. T2 makes the row say what it is.
     */
    public function test_a_customer_return_no_longer_establishes_the_onboarding_supply_baseline(): void
    {
        $product = $this->physicalProduct(costPrice: '50.000000');
        $this->seedStock($product->id, '100.0000');

        $detector = $this->app->make(FirstCountDetector::class);
        self::assertTrue($detector->isFirstCount($product->id, $this->locationId, null));

        $this->confirmedReturnNoteFor($product, quantity: '2.0000');

        self::assertTrue(
            $detector->isFirstCount($product->id, $this->locationId, null),
            'A customer return is not a supply event; it must not suppress the first count.',
        );
    }

    public function test_the_persisted_reference_type_string_is_byte_identical_to_the_pre_t2_literal(): void
    {
        $product = $this->physicalProduct(costPrice: '50.000000');
        $this->seedStock($product->id, '100.0000');

        $deliveryNote = $this->confirmedDeliveryNoteFor($product, quantity: '3.0000');
        $returnNote = $this->confirmedReturnNoteFor($product, quantity: '1.0000');

        // Read the RAW column, not the model — a cast would hide a changed byte.
        $rows = DB::table('stock_movements')
            ->whereIn('reference_id', [$deliveryNote->id, $returnNote->id])
            ->pluck('reference_type')
            ->all();

        self::assertCount(2, $rows);
        foreach ($rows as $referenceType) {
            self::assertSame('Document', $referenceType);
        }

        self::assertSame(
            'Document',
            StockMovementReferenceType::Document->value,
            'The enum backing value IS the legacy literal — typing the parameter is a no-op on disk.',
        );

        // The literal readers that would break if it were not.
        self::assertSame(
            2,
            DB::table('stock_movements')->where('reference_type', 'Document')->count(),
            'LinkedCostApplicationService / BackfillGoodsReceiptsCommand query this literal.',
        );
    }
}
