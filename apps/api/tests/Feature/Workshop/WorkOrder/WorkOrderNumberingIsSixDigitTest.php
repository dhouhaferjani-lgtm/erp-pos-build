<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderSequenceInterface;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Closes audit finding 🟠-2 — Work-order numbering width inconsistency.
 *
 * Seeded/factory work-orders use the 6-digit padding (WO-YYYY-NNNNNN),
 * but the live sequence generator was emitting 4-digit numbers
 * (WO-YYYY-NNNN). Both conventions coexisted in production data.
 *
 * This test locks the sequence generator to the 6-digit canonical format
 * so every new work-order number matches the seeded demo data shape.
 */
final class WorkOrderNumberingIsSixDigitTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequence_generator_emits_six_digit_numbers(): void
    {
        $wo = WorkOrder::factory()->create();
        $seq = $this->app->make(WorkOrderSequenceInterface::class);

        for ($i = 1; $i <= 10; $i++) {
            $number = $seq->next($wo->company_id);

            $this->assertMatchesRegularExpression(
                '/^WO-\d{4}-\d{6}$/',
                $number,
                "Generated WO number '{$number}' must match WO-YYYY-NNNNNN (6-digit padding)."
            );
        }
    }

    public function test_sequence_pads_small_numbers_to_six_digits(): void
    {
        $wo = WorkOrder::factory()->create();
        $seq = $this->app->make(WorkOrderSequenceInterface::class);

        $year = date('Y');

        $this->assertSame("WO-{$year}-000001", $seq->next($wo->company_id));
        $this->assertSame("WO-{$year}-000002", $seq->next($wo->company_id));
        $this->assertSame("WO-{$year}-000003", $seq->next($wo->company_id));
    }
}
