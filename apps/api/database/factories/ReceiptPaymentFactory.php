<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\POS\Domain\ReceiptPayment;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceiptPayment>
 */
class ReceiptPaymentFactory extends Factory
{
    protected $model = ReceiptPayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'receipt_id' => null,
            'payment_method_id' => null,
            'payment_type' => 'Cash',
            // amount is cast decimal:3 — canonical 3dp numeric string, no float.
            'amount' => CurrencyScale::bcformat($this->faker->randomFloat(2, 10, 500), 3),
        ];
    }

    /**
     * Re-scale the payment amount to the decimal scale of the given currency.
     */
    public function currency(string $currencyCode): static
    {
        $scale = CurrencyScale::for($currencyCode);

        return $this->state(fn (array $attributes): array => [
            'amount' => CurrencyScale::bcformat($attributes['amount'] ?? '0', $scale),
        ]);
    }
}
