<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\StockAdjustmentStatus;
use PHPUnit\Framework\TestCase;

/**
 * DPA V7 / T17 (plan D6a, re-review N-5).
 *
 * The `stock_adjustment_lines_reason_sign` CHECK freezes two literal reason
 * lists. That is deliberate: `tenants:migrate` runs the migration file at each
 * tenant's PROVISIONING time, so a migration that derived its predicate from a
 * mutable Domain constant would silently emit a DIFFERENT constraint for tenants
 * provisioned after a later enum change — a tenant-divergent schema no migration
 * records.
 *
 * The derivation lives HERE instead. If a future lane adds a manual reason (G1 +
 * `Consumption` is a named follow-up), this test fails loudly and the lane must
 * ship a migration that alters the CHECK, rather than forking the schema between
 * tenant cohorts.
 */
final class AdjustmentReasonSignPartitionTest extends TestCase
{
    /**
     * The EXACT strings frozen in
     * database/migrations/tenant/2026_08_08_120000_create_stock_adjustments_tables.php.
     *
     * @var array<string, list<string>>
     */
    private const FROZEN_MIGRATION_LISTS = [
        'in' => ['adjustment_positive'],
        'out' => ['adjustment_negative', 'damage', 'write_off'],
    ];

    public function test_partitioning_the_manual_reasons_on_movement_type_equals_the_frozen_check_lists(): void
    {
        $partition = ['in' => [], 'out' => []];

        foreach (MovementReason::manualAdjustmentCases() as $reason) {
            $partition[$reason->getMovementType()][] = $reason->value;
        }

        $this->assertSame(
            self::FROZEN_MIGRATION_LISTS,
            $partition,
            'MovementReason::manualAdjustmentCases() partitioned on getMovementType() has drifted '
            .'from the frozen CHECK lists in 2026_08_08_120000_create_stock_adjustments_tables.php. '
            .'Ship a migration that ALTERs stock_adjustment_lines_reason_sign — do NOT make the '
            .'existing migration derive its predicate, or tenants provisioned before and after the '
            .'change get different constraints.'
        );
    }

    public function test_the_manual_vocabulary_is_exactly_the_four_document_reasons(): void
    {
        $this->assertSame(
            [
                MovementReason::AdjustmentPositive,
                MovementReason::AdjustmentNegative,
                MovementReason::Damage,
                MovementReason::WriteOff,
            ],
            MovementReason::manualAdjustmentCases(),
        );

        $this->assertSame(
            ['adjustment_positive', 'adjustment_negative', 'damage', 'write_off'],
            MovementReason::manualAdjustmentValues(),
        );
    }

    public function test_opening_balance_expiry_consumption_and_count_correction_are_excluded(): void
    {
        foreach ([
            MovementReason::OpeningBalance,
            MovementReason::Expiry,
            MovementReason::Consumption,
            MovementReason::CountCorrection,
        ] as $excluded) {
            $this->assertNotContains(
                $excluded,
                MovementReason::manualAdjustmentCases(),
                "{$excluded->value} must not be selectable on a stock-adjustment line (D7 / D7a / D7b)."
            );
        }
    }

    public function test_every_manual_reason_requires_a_gl_entry_so_g1_can_pick_the_document_up(): void
    {
        foreach (MovementReason::manualAdjustmentCases() as $reason) {
            $this->assertTrue($reason->requiresGLEntry(), "{$reason->value} must require a GL entry.");
        }
    }

    public function test_adjustment_negative_is_the_cost_neutral_representation_and_write_off_is_not(): void
    {
        // D7b / G1 hand-off item 3: internal consumption is recorded as
        // adjustment_negative precisely because it does NOT affect COGS.
        $this->assertFalse(MovementReason::AdjustmentNegative->affectsCOGS());
        $this->assertTrue(MovementReason::WriteOff->affectsCOGS());
    }

    public function test_posted_and_cancelled_are_terminal_and_draft_reaches_both(): void
    {
        $this->assertSame([], StockAdjustmentStatus::Posted->allowedTransitions());
        $this->assertSame([], StockAdjustmentStatus::Cancelled->allowedTransitions());
        $this->assertSame(
            [StockAdjustmentStatus::Posted, StockAdjustmentStatus::Cancelled],
            StockAdjustmentStatus::Draft->allowedTransitions(),
        );

        $this->assertTrue(StockAdjustmentStatus::Draft->canTransitionTo(StockAdjustmentStatus::Posted));
        $this->assertTrue(StockAdjustmentStatus::Draft->canTransitionTo(StockAdjustmentStatus::Cancelled));
        $this->assertFalse(StockAdjustmentStatus::Draft->canTransitionTo(StockAdjustmentStatus::Draft));
        $this->assertFalse(StockAdjustmentStatus::Posted->canTransitionTo(StockAdjustmentStatus::Cancelled));
        $this->assertTrue(StockAdjustmentStatus::Posted->isTerminal());
        $this->assertFalse(StockAdjustmentStatus::Draft->isTerminal());
    }
}
