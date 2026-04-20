<?php

declare(strict_types=1);

namespace Database\Factories\Workshop;

use App\Modules\Workshop\Technician\Domain\Enums\TimeEntrySource;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntryType;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TechnicianTimeEntry>
 */
final class TechnicianTimeEntryFactory extends Factory
{
    /** @var class-string<TechnicianTimeEntry> */
    protected $model = TechnicianTimeEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('-7 days', 'now');
        $end = (clone $start)->modify('+90 minutes');
        $durationMin = (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);

        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => fn (array $attrs): string => (string) TechnicianProfile::query()
                ->whereKey($attrs['technician_profile_id'])
                ->value('tenant_id'),
            'company_id' => fn (array $attrs): string => (string) TechnicianProfile::query()
                ->whereKey($attrs['technician_profile_id'])
                ->value('company_id'),
            'technician_profile_id' => TechnicianProfile::factory(),
            'started_at' => $start,
            'ended_at' => $end,
            'duration_minutes' => $durationMin,
            'entry_type' => TimeEntryType::WorkOrder->value,
            'work_order_id' => Str::uuid()->toString(),
            'source' => TimeEntrySource::Manual->value,
            'recorded_by_user_id' => null,
            'notes' => null,
        ];
    }

    public function open(): self
    {
        return $this->state(fn (): array => [
            'ended_at' => null,
            'duration_minutes' => null,
        ]);
    }
}
