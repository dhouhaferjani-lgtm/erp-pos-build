<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Resources\TerminalResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 6.2: TerminalResource must preserve the decimal:2 string form of
 * max_discount_percent (e.g. '15.00') rather than laundering it through a
 * (float) cast that would drop trailing zeros (15.0).
 */
final class TerminalResourcePrecisionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_preserves_max_discount_percent_decimal_string(): void
    {
        $terminal = Terminal::factory()->make([
            'max_discount_percent' => '15.00',
            'is_training_mode' => false,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);
        // Provide the aggregate counts the resource reads via null-coalescing so
        // it does not fall through to a DB count() on an unpersisted model.
        $terminal->forceFill(['receipts_count' => 0, 'shifts_count' => 0]);
        $terminal->setCreatedAt(now());
        $terminal->setUpdatedAt(now());

        $array = (new TerminalResource($terminal))->toArray(Request::create('/'));

        // Asserting the exact string '15.00' (not 15.0) proves the (float) cast
        // is gone and the decimal:2 scale is preserved end-to-end.
        $this->assertSame('15.00', $array['max_discount_percent']);
    }
}
