<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Document;

use App\Modules\Document\Domain\DocumentLine;
use PHPUnit\Framework\TestCase;

final class DocumentLineModelTest extends TestCase
{
    public function test_designation_default_snapshot_is_fillable(): void
    {
        $line = new DocumentLine;

        $this->assertContains('designation_default_snapshot', $line->getFillable());
    }

    public function test_designation_default_snapshot_round_trips_through_fill(): void
    {
        $line = new DocumentLine;

        $line->fill(['designation_default_snapshot' => 'Original Product Name']);

        $this->assertSame('Original Product Name', $line->designation_default_snapshot);
    }

    public function test_designation_default_snapshot_accepts_null(): void
    {
        $line = new DocumentLine;

        $line->fill(['designation_default_snapshot' => null]);

        $this->assertNull($line->designation_default_snapshot);
    }

    // ── W-3 negative-net domain floor (2026-08-03 ticket) ───────────────────
    //
    // computeLineTotal() must never return a negative net, even if a bad
    // discount_amount reaches it (stale row, import, or a future write path
    // that bypasses the request-layer validation guard). Defence-in-depth:
    // the 422 at the boundary is the primary guard, this is the backstop.

    public function test_compute_line_total_floors_an_over_discount_amount_at_zero(): void
    {
        // qty 10 x 12.500 = 125.000 gross, flat 200.000 off — must floor at
        // 0.000, never go negative (this is the exact MTP-DSC-04 payload).
        $result = DocumentLine::computeLineTotal('10', '12.500', null, '200.000', 3);

        $this->assertSame('0.000', $result);
    }

    public function test_compute_line_total_floors_at_zero_for_a_smaller_scale(): void
    {
        // Same over-discount, but at a 2-decimal (EUR-like) scale.
        $result = DocumentLine::computeLineTotal('10', '12.500', null, '200.000', 2);

        $this->assertSame('0.00', $result);
    }

    public function test_compute_line_total_is_unaffected_by_the_floor_when_discount_is_within_gross(): void
    {
        // qty 10 x 12.500 = 125.000 gross, flat 25.000 off -> net 100.000.
        // The floor must be a no-op on the normal path.
        $result = DocumentLine::computeLineTotal('10', '12.500', null, '25.000', 3);

        $this->assertSame('100.000', $result);
    }

    public function test_compute_line_total_at_exactly_zero_stays_zero(): void
    {
        // qty 1 x 10.000 = 10.000 gross, flat 10.000 off -> net exactly 0.
        $result = DocumentLine::computeLineTotal('1', '10.000', null, '10.000', 3);

        $this->assertSame('0.000', $result);
    }
}
