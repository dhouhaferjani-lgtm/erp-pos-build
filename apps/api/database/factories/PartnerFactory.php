<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    protected $model = Partner::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $companyTypes = ['SARL', 'SAS', 'SA', 'EURL', 'SNC', 'Auto-Entrepreneur', ''];
        $companyType = $this->faker->randomElement($companyTypes);
        $companyName = $this->faker->company();
        $fullName = $companyType ? "{$companyName} {$companyType}" : $companyName;

        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => null, // Will be set by relationships
            'company_id' => null, // Will be set by seeder or relationships
            // W4-3 fix round r1: the DEFAULT is dual-role, not a random draw.
            //
            // This used to be `randomElement(['customer', 'supplier', 'both'])`, so
            // roughly one factory partner in three was supplier-ONLY — and any test
            // that gave such a partner a customer Invoice was relying on the draw.
            // That was already non-determinism (two identical runs of the same five
            // Treasury classes produced 13 and 16 failures), and the W4-3 direction
            // guard — which refuses to settle a document whose type its partner's
            // role cannot own — turns it into a visible 1-in-3 flake.
            //
            // `both` is the neutral default: it satisfies every role predicate, so a
            // test that does not care about the type is unaffected by it. Every test
            // that DOES care already says so explicitly, via the `customer()`,
            // `supplier()` and `both()` states below or an inline `'type' =>`.
            'type' => PartnerType::Both->value,
            'code' => strtoupper(Str::random(3)).$this->faker->unique()->numberBetween(100, 999),
            'name' => $fullName,
            'email' => $this->faker->unique()->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'vat_number' => $this->faker->optional(0.8)->regexify('FR[0-9]{11}'),
            'country_code' => 'FR',
            'notes' => $this->faker->optional(0.3)->paragraph(),
            'is_active' => $this->faker->boolean(95),
            'account_status' => CustomerAccountStatus::Active,
            'account_status_version' => 1,
            'tax_status' => PartnerTaxStatus::REGISTERED,
            'withholding_exempt' => false,
        ];
    }

    /**
     * Indicate that the partner is a customer.
     */
    public function customer(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'customer',
            'code' => 'CUST'.$this->faker->unique()->numberBetween(1000, 9999),
        ]);
    }

    /**
     * Indicate that the partner is a supplier.
     */
    public function supplier(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'supplier',
            'code' => 'SUPP'.$this->faker->unique()->numberBetween(1000, 9999),
        ]);
    }

    /**
     * Indicate that the partner is both customer and supplier.
     */
    public function both(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'both',
        ]);
    }

    /**
     * Indicate that the partner is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the partner is from Tunisia.
     */
    public function tunisia(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'TN',
            'vat_number' => $this->faker->optional(0.8)->regexify('TN[0-9]{13}'),
        ]);
    }

    /**
     * Indicate that the partner is from France.
     */
    public function france(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'FR',
            'vat_number' => $this->faker->optional(0.8)->regexify('FR[0-9]{11}'),
        ]);
    }
}
