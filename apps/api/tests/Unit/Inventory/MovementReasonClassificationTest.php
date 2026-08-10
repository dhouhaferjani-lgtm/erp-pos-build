<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Domain\Enums\MovementReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DPA Wave 3 · sub-wave 3A · **T6 — pin the classification exhaustively**.
 *
 * `MovementReason::affectsCOGS()` and `::requiresGLEntry()` become THE single
 * classification authority for the Wave-3 exit seam (D-2): the seam posts for a
 * movement iff its reason says so. Both methods `match` on `$this` with a
 * `default` arm, so a NEW case silently inherits `false`/`false` and is silently
 * excluded from the ledger — the failure mode a chart of accounts cannot
 * detect after the fact.
 *
 * This suite makes that impossible. Every enum case must have an explicit,
 * hand-written answer in `expectations()`; the exhaustiveness test walks
 * `MovementReason::cases()` and fails when a case has no entry. Adding a case
 * therefore fails CI until someone decides, in writing, whether it is
 * COGS-bearing and whether it needs a journal entry.
 *
 * EXTENDS, does not duplicate, `AdjustmentReasonSignPartitionTest` — that suite
 * owns `getMovementType()` (the SIGN invariant, DPA V7 / D6a). This one owns the
 * two GL predicates. Neither restates the other's lists.
 */
final class MovementReasonClassificationTest extends TestCase
{
    /**
     * The frozen answer for EVERY case: [affectsCOGS, requiresGLEntry].
     *
     * @return array<string, array{0: MovementReason, 1: bool, 2: bool}>
     */
    public static function expectations(): array
    {
        return [
            // --- inbound -------------------------------------------------
            // A purchase receipt does not relieve COGS (it capitalises), but it
            // does need GR-IR.
            'goods_receipt' => [MovementReason::GoodsReceipt, false, true],
            // A customer return REVERSES a sale: COGS-bearing (the credit leg).
            'customer_return' => [MovementReason::CustomerReturn, true, true],
            'adjustment_positive' => [MovementReason::AdjustmentPositive, false, true],
            'production_output' => [MovementReason::ProductionOutput, false, false],
            'opening_balance' => [MovementReason::OpeningBalance, false, false],

            // --- outbound ------------------------------------------------
            'delivery' => [MovementReason::Delivery, true, true],
            'supplier_return' => [MovementReason::SupplierReturn, false, true],
            'adjustment_negative' => [MovementReason::AdjustmentNegative, false, true],
            'count_correction' => [MovementReason::CountCorrection, false, true],
            'damage' => [MovementReason::Damage, true, true],
            'expiry' => [MovementReason::Expiry, true, true],
            'write_off' => [MovementReason::WriteOff, true, true],

            // --- POS -----------------------------------------------------
            'pos_sale' => [MovementReason::POSSale, true, true],
            'pos_return' => [MovementReason::POSReturn, true, true],

            // --- deliberate falses ---------------------------------------
            // TRANSFERS move stock between the company's OWN locations. Nothing
            // leaves the entity, so there is no COGS and no journal entry — the
            // inventory account balance is unchanged. Both directions, both
            // predicates, false. (A transfer that crossed a legal entity would
            // be a sale + a purchase, i.e. different reasons entirely.)
            'transfer_in' => [MovementReason::TransferIn, false, false],
            'transfer_out' => [MovementReason::TransferOut, false, false],

            // CONSUMPTION is false/false BY DECISION, not by oversight —
            // **plan OQ-1**. Under perpetual valuation, internal consumption of
            // a stock-tracked good genuinely DOES relieve inventory, so this
            // pair is arguably wrong today. The owner ruling on OQ-1 is:
            // "Consumables lane. Leave both false in Wave 3." Whoever flips it
            // must flip it in the consumables lane (sub-wave 3G's shape), with
            // the expense account decided, not here as a drive-by.
            'consumption' => [MovementReason::Consumption, false, false],
        ];
    }

    #[DataProvider('expectations')]
    public function test_the_frozen_classification_holds(MovementReason $reason, bool $affectsCogs, bool $requiresGl): void
    {
        self::assertSame($affectsCogs, $reason->affectsCOGS(), "affectsCOGS() for {$reason->value}");
        self::assertSame($requiresGl, $reason->requiresGLEntry(), "requiresGLEntry() for {$reason->value}");
    }

    /**
     * THE guard that makes the rest of this suite worth having: a new case with
     * no explicit answer fails here rather than inheriting the `default => false`
     * arm and silently vanishing from the ledger.
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
            'Every MovementReason case must carry an explicit affectsCOGS()/requiresGLEntry() '
            .'answer in expectations(). Both methods have a `default => false` arm, so an '
            .'unanswered case is silently excluded from every Wave-3 journal entry.',
        );
    }

    /**
     * `affectsCOGS()` is a strict subset of `requiresGLEntry()`: a movement that
     * relieves COGS necessarily needs a journal entry. The converse does not
     * hold (a goods receipt needs GR-IR but relieves no COGS).
     */
    public function test_cogs_bearing_implies_gl_bearing(): void
    {
        foreach (MovementReason::cases() as $reason) {
            if ($reason->affectsCOGS()) {
                self::assertTrue(
                    $reason->requiresGLEntry(),
                    "{$reason->value} affects COGS but claims to need no journal entry",
                );
            }
        }
    }
}
