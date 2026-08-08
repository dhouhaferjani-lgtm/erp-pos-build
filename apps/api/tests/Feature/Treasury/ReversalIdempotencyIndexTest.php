<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DPA V4 / T3 — `payments_reversal_idempotency_uniq`.
 *
 * Domain invariant (plan D-7): a payment may be reversed AT MOST ONCE, ever.
 * `PaymentStatus::canReverse()` is `Completed`-only and the reversal stamps the
 * original `Reversed`, so — unlike the refund index — the reversal index needs
 * no request-id plumbing. It is deliberately STRONGER: it holds even for a
 * caller that supplies no id, which is what makes the
 * `UniqueConstraintViolationException` → read-back pattern in
 * `reversePayment()` a genuine safety net rather than decoration.
 */
final class ReversalIdempotencyIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_reversal_of_the_same_original_payment_is_rejected(): void
    {
        $original = Payment::factory()->create();

        $this->insertReversalRow($original);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertReversalRow($original);
    }

    public function test_two_reversals_of_two_distinct_originals_both_insert(): void
    {
        $first = Payment::factory()->create();
        $second = Payment::factory()->create([
            'tenant_id' => $first->tenant_id,
            'company_id' => $first->company_id,
            'partner_id' => $first->partner_id,
            'payment_method_id' => $first->payment_method_id,
        ]);

        $this->insertReversalRow($first);
        $this->insertReversalRow($second);

        self::assertSame(
            2,
            Payment::query()->where('payment_type', PaymentType::Reversal->value)->count(),
        );
    }

    /**
     * The two partial indexes must not collide: the refund index is
     * request-id-scoped (a payment can be refunded many times), so a second
     * refund row with a DIFFERENT `refund_request_id` still inserts even though
     * `(company_id, original_payment_id)` repeats.
     */
    public function test_the_reversal_index_does_not_constrain_refund_rows(): void
    {
        $original = Payment::factory()->create();

        foreach ([Str::uuid()->toString(), Str::uuid()->toString()] as $requestId) {
            Payment::query()->create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $original->tenant_id,
                'company_id' => $original->company_id,
                'partner_id' => $original->partner_id,
                'payment_method_id' => $original->payment_method_id,
                'amount' => '-10.000',
                'currency' => $original->currency,
                'payment_date' => now(),
                'status' => $original->status,
                'payment_type' => PaymentType::Refund,
                'original_payment_id' => $original->id,
                'refund_request_id' => $requestId,
                'reference' => 'REF-'.Str::random(6),
            ]);
        }

        self::assertSame(
            2,
            Payment::query()->where('payment_type', PaymentType::Refund->value)->count(),
        );
    }

    /**
     * The index predicate requires a non-NULL `original_payment_id`, so ordinary
     * payments (which all carry NULL there) are never constrained.
     */
    public function test_ordinary_payments_with_null_original_payment_id_are_unconstrained(): void
    {
        Payment::factory()->count(3)->create();

        self::assertSame(3, Payment::query()->whereNull('original_payment_id')->count());
    }

    private function insertReversalRow(Payment $original): void
    {
        Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $original->tenant_id,
            'company_id' => $original->company_id,
            'partner_id' => $original->partner_id,
            'payment_method_id' => $original->payment_method_id,
            'amount' => '-100.000',
            'currency' => $original->currency,
            'payment_date' => now(),
            'status' => $original->status,
            'payment_type' => PaymentType::Reversal,
            'original_payment_id' => $original->id,
            'reference' => 'REV-'.Str::random(6),
        ]);
    }
}
