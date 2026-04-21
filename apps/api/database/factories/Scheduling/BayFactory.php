<?php

declare(strict_types=1);

namespace Database\Factories\Scheduling;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Domain\Enums\BayType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Bay>
 */
final class BayFactory extends Factory
{
    /** @var class-string<Bay> */
    protected $model = Bay::class;

    /**
     * Default state: active general-purpose bay with a standard 5-day
     * operating-hours schedule.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenant = Tenant::first() ?? Tenant::factory()->create();
        $company = Company::where('tenant_id', $tenant->id)->first()
            ?? Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::where('company_id', $company->id)->first()
            ?? Location::factory()->create(['company_id' => $company->id]);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'code' => 'BAY-'.$this->faker->unique()->numberBetween(1, 9999),
            'name' => 'Bay '.$this->faker->numberBetween(1, 20),
            'bay_type' => BayType::General->value,
            'display_order' => $this->faker->numberBetween(0, 10),
            'operating_hours' => [
                'mon' => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']],
                'tue' => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']],
                'wed' => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']],
                'thu' => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']],
                'fri' => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']],
                'sat' => [],
                'sun' => [],
            ],
            'notes' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function ofType(BayType $type): self
    {
        return $this->state(fn (): array => ['bay_type' => $type->value]);
    }
}
