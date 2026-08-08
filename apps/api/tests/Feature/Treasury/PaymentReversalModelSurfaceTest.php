<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DPA V4 / T2 — `Payment` model surface for the new `Reversal` type.
 *
 * `scopeOutgoing()` has zero callers repo-wide today (plan M4), so admitting
 * `Reversal` there is hygiene rather than a behaviour change — but it must be
 * correct hygiene: a reversal's cash branch moves money OUT, so any future
 * caller of the scope must see it.
 */
final class PaymentReversalModelSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_outgoing_scope_includes_reversal_rows(): void
    {
        $reversal = Payment::factory()->create([
            'payment_type' => PaymentType::Reversal,
            'amount' => '-100.00',
        ]);
        $incoming = Payment::factory()->create([
            'payment_type' => PaymentType::DocumentPayment,
        ]);

        $ids = Payment::query()->outgoing()->pluck('id')->all();

        self::assertContains($reversal->id, $ids, 'a reversal row is an outgoing payment');
        self::assertNotContains($incoming->id, $ids);
    }

    public function test_incoming_scope_still_excludes_reversal_rows(): void
    {
        $reversal = Payment::factory()->create([
            'payment_type' => PaymentType::Reversal,
            'amount' => '-100.00',
        ]);

        self::assertNotContains(
            $reversal->id,
            Payment::query()->incoming()->pluck('id')->all(),
        );
    }

    public function test_is_reversal_discriminates_the_new_type(): void
    {
        $reversal = Payment::factory()->create([
            'payment_type' => PaymentType::Reversal,
            'amount' => '-100.00',
        ]);
        $refund = Payment::factory()->create([
            'payment_type' => PaymentType::Refund,
            'amount' => '-100.00',
        ]);

        self::assertTrue($reversal->isReversal());
        self::assertFalse($reversal->isRefund());
        self::assertFalse($refund->isReversal());
        self::assertTrue($refund->isRefund());
    }
}
