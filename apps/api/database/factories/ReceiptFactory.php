<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    protected $model = Receipt::class;

    /**
     * @return array<model-property<Receipt>, mixed>
     */
    public function definition(): array
    {
        $subtotal = $this->faker->randomFloat(2, 10, 500);
        $taxAmount = round($subtotal * 0.19, 2);
        $total = round($subtotal + $taxAmount, 2);

        return [
            'tenant_id' => Tenant::factory(),
            'company_id' => Company::factory(),
            'location_id' => Location::factory(),
            'terminal_id' => Terminal::factory(),
            'receipt_number' => 'T001-C001-L01-POS01-'.date('Y').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 99999999), 8, '0', STR_PAD_LEFT),
            'chain_sequence' => $this->faker->unique()->numberBetween(1, 99999),
            'receipt_year' => (int) date('Y'),
            'fiscal_hash' => hash('sha256', $this->faker->uuid()),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', $this->faker->uuid()),
            'payment_methods_hash' => hash('sha256', $this->faker->uuid()),
            'posted_at' => now(),
            'cashier_id' => User::factory(),
            'cashier_name' => $this->faker->name(),
            'subtotal' => number_format($subtotal, 3, '.', ''),
            'tax_amount' => number_format($taxAmount, 3, '.', ''),
            'total' => number_format($total, 3, '.', ''),
            'currency' => 'EUR',
            'is_voided' => false,
        ];
    }

    /**
     * Receipt in pending_seal state: fiscal_hash and chain_sequence are null,
     * fiscal_status is pending_seal. Use when testing ReceiptFinalizationService.
     */
    public function pendingSeal(): static
    {
        return $this->state(fn () => [
            'fiscal_status' => FiscalStatus::PendingSeal,
            'fiscal_hash' => null,
            'chain_sequence' => null,
        ]);
    }

    /**
     * Configure receipt with consistent totals.
     * Use when overriding total to ensure CHECK constraint passes.
     *
     * @param  numeric-string  $total
     * @param  numeric-string  $taxAmount
     */
    public function withTotal(string $total, string $taxAmount = '0.000'): static
    {
        /** @var numeric-string $numericTotal */
        $numericTotal = $total;
        /** @var numeric-string $numericTaxAmount */
        $numericTaxAmount = $taxAmount;
        $subtotal = bcsub($numericTotal, $numericTaxAmount, 3);

        return $this->state(fn () => [
            'total' => $total,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
        ]);
    }

    /**
     * Configure receipt as part of an exchange group (Phase F).
     * Both the return half and the sale half must share the same exchange_group_id.
     */
    public function inExchangeGroup(string $exchangeGroupId): static
    {
        return $this->state(fn () => [
            'exchange_group_id' => $exchangeGroupId,
        ]);
    }
}
