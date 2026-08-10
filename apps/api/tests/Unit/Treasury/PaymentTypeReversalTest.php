<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\ReversalSupport;
use PHPUnit\Framework\TestCase;

/**
 * DPA V4 / T1 — `PaymentType::Reversal` and the `reversalSupport()` authority.
 *
 * Plan D-2: `PaymentType` carries SIX branch blocks. Only two are exhaustive
 * (`label()`, `isIncoming()`) and therefore fail loudly for a new case; the
 * other four carry `default =>` and would SILENTLY absorb `Reversal` with the
 * wrong answer. `increasesReceivable()` and `isOutgoing()` are the two
 * silent-wrong ones that matter — this test is their only guard.
 */
final class PaymentTypeReversalTest extends TestCase
{
    public function test_label_names_the_reversal_case(): void
    {
        self::assertSame('Payment Reversal', PaymentType::Reversal->label());
    }

    public function test_a_reversal_is_not_an_incoming_payment(): void
    {
        // DashboardController builds its "payments received" whitelist from
        // isIncoming(); a reversal must never be counted there.
        self::assertFalse(PaymentType::Reversal->isIncoming());
    }

    /**
     * D-2 blocks 2 and 6 — the SILENT-WRONG pair. Both live behind a
     * `default => false` arm, so forgetting them produces no compiler error.
     */
    public function test_a_reversal_reopens_the_receivable_and_moves_money_out(): void
    {
        self::assertTrue(
            PaymentType::Reversal->increasesReceivable(),
            'a reversal re-opens the receivable — same economic effect as a refund (D-2 block 2)',
        );
        self::assertTrue(
            PaymentType::Reversal->isOutgoing(),
            'the cash branch of a reversal physically moves money out (D-2 block 6)',
        );
    }

    /** D-2 blocks 3 and 4 — deliberate no-ops, pinned so they stay no-ops. */
    public function test_a_reversal_neither_decreases_the_receivable_nor_mints_credit(): void
    {
        self::assertFalse(PaymentType::Reversal->decreasesReceivable());
        self::assertFalse(PaymentType::Reversal->createsCredit());
    }

    /**
     * D-6 matrix. The `match` inside `reversalSupport()` is exhaustive (no
     * `default`), so a future `PaymentType` case is a compile-time decision.
     *
     * **DPA `DPA-REV2-A` (A7): `Advance` moved from `Unsupported` to
     * `CashReversal`.** This is the test of record for that matrix, and it is the
     * one the fix-round-0 lane failed to update (code-gate finding I-A) — the
     * declared regression sweep omitted `tests/Unit/`, so a red suite was
     * reported as green.
     *
     * `Advance` shares `CashReversal` with `DocumentPayment` rather than gaining
     * a fourth case because, under A-D2, the two have identical BEHAVIOUR: a cash
     * movement is emitted, and the ACCOUNTS come from the payment's posted ledger
     * footprint, not from the type. `ReversalSupport` answers *whether*, never
     * *which account*.
     */
    public function test_reversal_support_matches_the_d6_matrix(): void
    {
        self::assertSame(ReversalSupport::CashReversal, PaymentType::DocumentPayment->reversalSupport());
        self::assertSame(ReversalSupport::NoCashLeg, PaymentType::CreditApplication->reversalSupport());
        self::assertSame(
            ReversalSupport::CashReversal,
            PaymentType::Advance->reversalSupport(),
            'A7: a customer advance is reversible — the customer-advance reversing entry exists '
            .'(reverseCustomerAdvanceJournalEntry) and the partition picks the accounts',
        );
        self::assertSame(ReversalSupport::Unsupported, PaymentType::SupplierPayment->reversalSupport());
        self::assertSame(ReversalSupport::Unsupported, PaymentType::POS->reversalSupport());
        self::assertSame(ReversalSupport::Unsupported, PaymentType::Refund->reversalSupport());
        self::assertSame(ReversalSupport::Unsupported, PaymentType::Reversal->reversalSupport());
    }

    /**
     * Future-proofs the two exhaustive blocks: every case must answer every
     * question without an `UnhandledMatchError`.
     */
    public function test_every_payment_type_answers_every_branch_block(): void
    {
        self::assertCount(7, PaymentType::cases());

        foreach (PaymentType::cases() as $case) {
            self::assertNotSame('', $case->label(), $case->value.' must have a label');
            $case->increasesReceivable();
            $case->decreasesReceivable();
            $case->createsCredit();
            $case->isIncoming();
            $case->isOutgoing();
            $case->reversalSupport();
        }
    }

    /** A reversal is never simultaneously incoming and outgoing. */
    public function test_reversal_direction_is_unambiguous(): void
    {
        self::assertNotSame(
            PaymentType::Reversal->isIncoming(),
            PaymentType::Reversal->isOutgoing(),
        );
    }
}
