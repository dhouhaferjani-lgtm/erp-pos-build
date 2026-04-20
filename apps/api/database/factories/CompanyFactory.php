<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\VerificationStatus;
use App\Modules\Company\Domain\Enums\VerificationTier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $companyTypes = ['SARL', 'SAS', 'SA', 'EURL'];
        $companyType = $this->faker->randomElement($companyTypes);
        $companyName = $this->faker->company();

        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => null, // Will be set when creating
            'name' => $companyName,
            'legal_name' => "{$companyName} {$companyType}",
            'code' => strtoupper(Str::random(4)),
            'country_code' => 'FR',
            'tax_id' => $this->faker->regexify('[0-9]{3} [0-9]{3} [0-9]{3} [0-9]{5}'),
            'registration_number' => $this->faker->regexify('[0-9]{9} RCS Paris'),
            'vat_number' => $this->faker->regexify('FR[0-9]{11}'),
            'email' => $this->faker->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'address_street' => $this->faker->streetAddress(),
            'address_city' => $this->faker->city(),
            'address_state' => $this->faker->state(),
            'address_postal_code' => $this->faker->postcode(),
            'primary_color' => '#2563eb',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'invoice_prefix' => 'INV-',
            'invoice_next_number' => 1,
            'quote_prefix' => 'QUO-',
            'quote_next_number' => 1,
            'sales_order_prefix' => 'SO-',
            'sales_order_next_number' => 1,
            'purchase_order_prefix' => 'PO-',
            'purchase_order_next_number' => 1,
            'delivery_note_prefix' => 'DN-',
            'delivery_note_next_number' => 1,
            'receipt_prefix' => 'REC-',
            'receipt_next_number' => 1,
            'verification_tier' => VerificationTier::Basic,
            'verification_status' => VerificationStatus::Pending,
            'is_headquarters' => true,
            'status' => CompanyStatus::Active,
            'inventory_costing_method' => 'weighted_average',
            'default_target_margin' => '30.00',
            'default_minimum_margin' => '15.00',
            'allow_below_cost_sales' => false,
            'fiscal_chain_seed' => bin2hex(random_bytes(32)), // 256-bit random seed for fiscal chain
        ];
    }

    /**
     * Indicate that the company is verified.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => VerificationStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    /**
     * Indicate that the company is for Tunisia.
     */
    public function tunisia(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);
    }

    /**
     * Indicate that the company is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CompanyStatus::Suspended,
        ]);
    }
}
