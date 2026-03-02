<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\POS\Domain\ReceiptPayment;
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
            'amount' => $this->faker->randomFloat(2, 10, 500),
        ];
    }
}
