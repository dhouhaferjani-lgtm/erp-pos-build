<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Company\Domain\Company;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Create tenant first if needed
        $tenant = Tenant::first();
        if (! $tenant) {
            $tenant = Tenant::factory()->create();
        }

        // Get or create company with tenant
        $company = Company::first();
        if (! $company) {
            $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        }

        // Get or create partner
        $partner = Partner::first();
        if (! $partner) {
            $partner = Partner::factory()->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);
        }

        // Get or create payment method
        $paymentMethod = PaymentMethod::first();
        if (! $paymentMethod) {
            $paymentMethod = PaymentMethod::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'name' => 'Cash',
                'code' => 'CASH',
                'is_active' => true,
                'is_physical' => true,
                'has_maturity' => false,
                'requires_third_party' => false,
                'is_push' => false,
                'has_deducted_fees' => false,
                'is_restricted' => false,
            ]);
        }

        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $paymentMethod->id,
            'amount' => '100.00',
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PAY-'.$this->faker->unique()->numberBetween(1000, 9999),
        ];
    }

    /**
     * Indicate that the payment is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Pending,
        ]);
    }

    /**
     * Indicate that the payment is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Completed,
        ]);
    }

    /**
     * Set custom amount.
     */
    public function withAmount(string $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amount,
        ]);
    }
}
