<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Domain\User;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VoucherLedger>
 */
class VoucherLedgerFactory extends Factory
{
    protected $model = VoucherLedger::class;

    public function definition(): array
    {
        $voucher = Voucher::factory()->create();

        return [
            'tenant_id' => $voucher->tenant_id,
            'company_id' => $voucher->company_id,
            'voucher_id' => $voucher->id,
            'event' => VoucherEvent::Issued,
            'amount' => '50.00000',
            'currency' => 'EUR',
            'receipt_id' => null,
            'terminal_id' => null,
            'user_id' => User::factory(),
            'gl_journal_entry_id' => null,
            'authorized_by_user_id' => null,
            'policy_trigger' => null,
            'reverses_voucher_ledger_id' => null,
            'occurred_at' => now(),
        ];
    }

    /**
     * Issuance event — positive amount.
     */
    public function issued(): static
    {
        return $this->state(fn () => [
            'event' => VoucherEvent::Issued,
            'amount' => '50.00000',
        ]);
    }

    /**
     * Redemption event — negative amount.
     *
     * @param  string|int|float  $amount  Absolute value; stored as negative
     */
    public function redeemed(string|int|float $amount): static
    {
        $negative = '-'.ltrim(number_format(abs((float) $amount), 5, '.', ''), '-');

        return $this->state(fn () => [
            'event' => VoucherEvent::Redeemed,
            'amount' => $negative,
        ]);
    }

    /**
     * Voided event — full reversal, negative amount.
     */
    public function voided(): static
    {
        return $this->state(fn () => [
            'event' => VoucherEvent::Voided,
            'amount' => '-50.00000',
        ]);
    }

    /**
     * Expired event — zero amount (status change, no monetary movement).
     */
    public function expired(): static
    {
        return $this->state(fn () => [
            'event' => VoucherEvent::Expired,
            'amount' => '0.00000',
        ]);
    }

    /**
     * Rounding adjustment event — typically a small residual sub-minor amount.
     *
     * @param  string|int|float  $residual  The dust amount to write off
     */
    public function roundingAdjustment(string|int|float $residual): static
    {
        $val = number_format((float) $residual, 5, '.', '');

        return $this->state(fn () => [
            'event' => VoucherEvent::RoundingAdjustment,
            'amount' => $val,
        ]);
    }
}
