<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherKind;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Services\VoucherCodeGenerator;
use App\Modules\Voucher\Domain\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Voucher>
 */
class VoucherFactory extends Factory
{
    protected $model = Voucher::class;

    public function definition(): array
    {
        $generator = new VoucherCodeGenerator;
        $tenant = Tenant::factory()->create();

        return [
            'tenant_id' => $tenant->id,
            'company_id' => Company::factory()->create(['tenant_id' => $tenant->id])->id,
            'code' => $generator->generate('TEST'),
            'initial_balance' => '50.00000',
            'current_balance' => '50.00000',
            'currency' => 'EUR',
            'status' => VoucherStatus::Issued,
            'redemption_mode' => RedemptionMode::Bearer,
            'voucher_kind' => VoucherKind::MPV,
            'source' => VoucherSource::Refund,
            'issued_at' => now(),
            'expires_at' => null,
            'partner_id' => null,
            'issued_to_partner_id' => null,
            'source_receipt_id' => null,
            'source_loyalty_transaction_id' => null,
            'source_promotional_campaign_id' => null,
            'issued_by_user_id' => User::factory(),
            'issued_at_terminal_id' => null,
            'redeemable_at_terminal_id' => null,
            'notes' => null,
            'authorized_by_user_id' => null,
            'override_reason' => null,
            'policy_trigger' => null,
        ];
    }

    /**
     * Voucher has expired.
     */
    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => VoucherStatus::Expired,
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Voucher has been voided.
     */
    public function voided(): static
    {
        return $this->state(fn () => [
            'status' => VoucherStatus::Voided,
            'current_balance' => '0.00000',
        ]);
    }

    /**
     * Voucher is partially redeemed with a specific remaining balance.
     *
     * @param  string|int|float  $remaining  Remaining balance (will be cast to string with 5 dp)
     */
    public function partiallyRedeemed(string|int|float $remaining): static
    {
        $balance = number_format((float) $remaining, 5, '.', '');

        return $this->state(fn () => [
            'status' => VoucherStatus::PartiallyRedeemed,
            'current_balance' => $balance,
        ]);
    }

    /**
     * Voucher is fully redeemed (zero balance).
     */
    public function fullyRedeemed(): static
    {
        return $this->state(fn () => [
            'status' => VoucherStatus::FullyRedeemed,
            'current_balance' => '0.00000',
        ]);
    }

    /**
     * Voucher is customer-bound to the given partner.
     */
    public function customerBound(Partner $partner): static
    {
        return $this->state(fn () => [
            'redemption_mode' => RedemptionMode::CustomerBound,
            'partner_id' => $partner->id,
            'issued_to_partner_id' => $partner->id,
        ]);
    }

    /**
     * Voucher is bound to a specific terminal (Phase 1 single-terminal scope).
     */
    public function forTerminal(Terminal $terminal): static
    {
        return $this->state(fn () => [
            'issued_at_terminal_id' => $terminal->id,
            'redeemable_at_terminal_id' => $terminal->id,
        ]);
    }

    /**
     * Goodwill voucher (not tied to a receipt).
     */
    public function goodwill(): static
    {
        return $this->state(fn () => [
            'source' => VoucherSource::Goodwill,
            'source_receipt_id' => null,
        ]);
    }

    /**
     * Voucher with a specific source.
     */
    public function forSource(VoucherSource $source): static
    {
        return $this->state(fn () => [
            'source' => $source,
        ]);
    }
}
