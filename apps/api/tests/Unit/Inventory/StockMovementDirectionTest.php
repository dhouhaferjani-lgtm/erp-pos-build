<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Domain\StockMovement;
use PHPUnit\Framework\TestCase;

/**
 * A3: row-level movement direction is derived from the signed
 * quantity_before -> quantity_after delta, NOT from MovementType (Adjustment is
 * direction-ambiguous) nor from the `quantity` magnitude (whose sign convention
 * is inconsistent across writers). The third state 'flat' covers zero-delta rows
 * such as WAC cost adjustments (quantity 0, before == after).
 */
final class StockMovementDirectionTest extends TestCase
{
    public function test_increase_is_inbound(): void
    {
        $m = new StockMovement;
        $m->quantity_before = '10.0000';
        $m->quantity_after = '12.5000';

        $this->assertSame('in', $m->directionForRow());
    }

    public function test_decrease_is_outbound(): void
    {
        $m = new StockMovement;
        $m->quantity_before = '12.5000';
        $m->quantity_after = '10.0000';

        $this->assertSame('out', $m->directionForRow());
    }

    public function test_zero_delta_is_flat(): void
    {
        $m = new StockMovement;
        $m->quantity_before = '10.0000';
        $m->quantity_after = '10.0000';

        $this->assertSame('flat', $m->directionForRow());
    }

    public function test_absolute_delta_uses_the_row_at_canonical_quantity_scale(): void
    {
        $in = new StockMovement;
        $in->setRawAttributes([
            'quantity_before' => '10.0000',
            'quantity_after' => '12.3456',
        ]);

        $out = new StockMovement;
        $out->setRawAttributes([
            'quantity_before' => '12.3456',
            'quantity_after' => '10.0000',
        ]);

        $this->assertSame('2.3456', $in->absoluteDeltaForRow());
        $this->assertSame('2.3456', $out->absoluteDeltaForRow());
    }
}
