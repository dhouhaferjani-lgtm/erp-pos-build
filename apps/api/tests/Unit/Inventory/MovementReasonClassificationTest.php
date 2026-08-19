<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Domain\Enums\MovementGlCounterFamily;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DPA Wave 3 · sub-wave 3A · **T6 — pin the classification exhaustively**.
 *
 * `MovementReason::glCounterFamily()` and `::requiresGLEntry()` are the single
 * classification authority for the Wave-3 seam. The counter-family match is
 * deliberately exhaustive, so a new enum case cannot silently inherit a P&L
 * policy.
 *
 * This suite makes that impossible. Every enum case must have an explicit,
 * hand-written answer in `expectations()`; the exhaustiveness test walks
 * `MovementReason::cases()` and fails when a case has no entry. Adding a case
 * therefore fails CI until someone decides, in writing, which counter family
 * it uses and whether it needs a journal entry.
 *
 * EXTENDS, does not duplicate, `AdjustmentReasonSignPartitionTest` — that suite
 * owns `getMovementType()` (the SIGN invariant, DPA V7 / D6a). This one owns the
 * GL counter-family classification. Neither restates the other's lists.
 */
final class MovementReasonClassificationTest extends TestCase
{
    /**
     * The frozen answer for EVERY case: [counter family, requiresGLEntry].
     *
     * @return array<string, array{0: MovementReason, 1: MovementGlCounterFamily, 2: bool}>
     */
    public static function expectations(): array
    {
        return [
            // --- inbound -------------------------------------------------
            // A purchase receipt does not relieve COGS (it capitalises), but it
            // does need GR-IR.
            'goods_receipt' => [MovementReason::GoodsReceipt, MovementGlCounterFamily::Neither, true],
            // A customer return REVERSES a sale: COGS-bearing (the credit leg).
            'customer_return' => [MovementReason::CustomerReturn, MovementGlCounterFamily::Cogs, true],
            'adjustment_positive' => [MovementReason::AdjustmentPositive, MovementGlCounterFamily::Neither, true],
            'production_output' => [MovementReason::ProductionOutput, MovementGlCounterFamily::Neither, false],
            'opening_balance' => [MovementReason::OpeningBalance, MovementGlCounterFamily::Neither, false],

            // --- outbound ------------------------------------------------
            'delivery' => [MovementReason::Delivery, MovementGlCounterFamily::Cogs, true],
            'supplier_return' => [MovementReason::SupplierReturn, MovementGlCounterFamily::Neither, true],
            'adjustment_negative' => [MovementReason::AdjustmentNegative, MovementGlCounterFamily::Neither, true],
            'count_correction' => [MovementReason::CountCorrection, MovementGlCounterFamily::DirectionalVariance, true],
            'damage' => [MovementReason::Damage, MovementGlCounterFamily::Shrinkage, true],
            'expiry' => [MovementReason::Expiry, MovementGlCounterFamily::Shrinkage, true],
            'write_off' => [MovementReason::WriteOff, MovementGlCounterFamily::Shrinkage, true],

            // --- POS -----------------------------------------------------
            'pos_sale' => [MovementReason::POSSale, MovementGlCounterFamily::Cogs, true],
            'pos_return' => [MovementReason::POSReturn, MovementGlCounterFamily::Cogs, true],

            // --- deliberate falses ---------------------------------------
            // TRANSFERS move stock between the company's OWN locations. Nothing
            // leaves the entity, so there is no COGS and no journal entry — the
            // inventory account balance is unchanged. Both directions, both
            // predicates, false. (A transfer that crossed a legal entity would
            // be a sale + a purchase, i.e. different reasons entirely.)
            'transfer_in' => [MovementReason::TransferIn, MovementGlCounterFamily::Neither, false],
            'transfer_out' => [MovementReason::TransferOut, MovementGlCounterFamily::Neither, false],

            // CONSUMPTION is false/false BY DECISION, not by oversight —
            // **plan OQ-1**. Under perpetual valuation, internal consumption of
            // a stock-tracked good genuinely DOES relieve inventory, so this
            // pair is arguably wrong today. The owner ruling on OQ-1 is:
            // "Consumables lane. Leave both false in Wave 3." Whoever flips it
            // must flip it in the consumables lane (sub-wave 3G's shape), with
            // the expense account decided, not here as a drive-by.
            'consumption' => [MovementReason::Consumption, MovementGlCounterFamily::Neither, false],
        ];
    }

    #[DataProvider('expectations')]
    public function test_the_frozen_classification_holds(MovementReason $reason, MovementGlCounterFamily $family, bool $requiresGl): void
    {
        self::assertSame($family, $reason->glCounterFamily(), "glCounterFamily() for {$reason->value}");
        self::assertSame($family === MovementGlCounterFamily::Cogs, $reason->affectsCOGS(), "affectsCOGS() for {$reason->value}");
        self::assertSame($family === MovementGlCounterFamily::Shrinkage, $reason->affectsShrinkage(), "affectsShrinkage() for {$reason->value}");
        self::assertSame($requiresGl, $reason->requiresGLEntry(), "requiresGLEntry() for {$reason->value}");
    }

    /**
     * THE guard that makes the rest of this suite worth having: a new case with
     * no explicit expectation fails here in addition to the production match
     * being exhaustive.
     */
    public function test_every_enum_case_has_an_explicit_answer(): void
    {
        $answered = array_map(
            static fn (array $row): string => $row[0]->value,
            self::expectations(),
        );

        $all = array_map(
            static fn (MovementReason $reason): string => $reason->value,
            MovementReason::cases(),
        );

        sort($answered);
        sort($all);

        self::assertSame(
            $all,
            $answered,
            'Every MovementReason case must carry an explicit counter-family/requiresGLEntry() '
            .'answer in expectations().',
        );
    }

    /**
     * `affectsCOGS()` is a strict subset of `requiresGLEntry()`: a movement that
     * relieves COGS necessarily needs a journal entry. The converse does not
     * hold (a goods receipt needs GR-IR but relieves no COGS).
     */
    public function test_a_p_and_l_counter_family_implies_gl_bearing(): void
    {
        foreach (MovementReason::cases() as $reason) {
            if ($reason->glCounterFamily() !== MovementGlCounterFamily::Neither) {
                self::assertTrue(
                    $reason->requiresGLEntry(),
                    "{$reason->value} has a P&L counter family but claims to need no journal entry",
                );
            }
        }
    }
}
