<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Company\Domain\Company;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Taxation\Domain\Entities\VatPeriod>
 */
class VatPeriodFactory extends Factory
{
    protected $model = VatPeriod::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::create($this->faker->numberBetween(2024, 2026), $this->faker->numberBetween(1, 12), 1);

        return [
            'company_id' => Company::factory()->for(Tenant::factory()),
            'country_code' => tap('TN', fn (string $code) => DB::table('countries')
                ->insertOrIgnore(['code' => $code, 'name' => 'Tunisia', 'currency_code' => 'TND', 'is_active' => true])),
            'period_type' => VatPeriodType::Monthly,
            'label' => $start->format('F Y'),
            'period_start' => $start->toDateString(),
            'period_end' => $start->endOfMonth()->toDateString(),
            'status' => VatPeriodStatus::Open,
        ];
    }

    /**
     * Indicate that the period is open.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VatPeriodStatus::Open,
        ]);
    }

    /**
     * Indicate that the period is closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VatPeriodStatus::Closed,
            'closed_at' => now(),
            'total_output_vat' => '1900.000',
            'total_input_vat' => '500.000',
            'net_vat' => '1400.000',
            'amount_payable' => '1400.000',
        ]);
    }

    /**
     * Indicate that the period is filed.
     */
    public function filed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VatPeriodStatus::Filed,
            'closed_at' => now()->subDay(),
            'filed_at' => now(),
            'total_output_vat' => '1900.000',
            'total_input_vat' => '500.000',
            'net_vat' => '1400.000',
            'amount_payable' => '1400.000',
            'filing_reference' => 'REF-'.$this->faker->unique()->numberBetween(10000, 99999),
        ]);
    }
}
