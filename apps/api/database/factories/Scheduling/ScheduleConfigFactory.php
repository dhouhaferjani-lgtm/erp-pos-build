<?php

declare(strict_types=1);

namespace Database\Factories\Scheduling;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Scheduling\Domain\ScheduleConfig;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ScheduleConfig>
 */
final class ScheduleConfigFactory extends Factory
{
    /** @var class-string<ScheduleConfig> */
    protected $model = ScheduleConfig::class;

    /**
     * Default: 30-min slots, 60-min default duration, online booking off,
     * SMS reminder 24h before + email 48h before (matches DB column defaults).
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
            'time_slot_minutes' => 30,
            'default_appointment_duration_minutes' => 60,
            'walk_in_buffer_hours_per_day' => '0.00',
            'overbooking_threshold_percent' => 100,
            'online_booking_enabled' => false,
            'online_booking_advance_days' => 30,
            'online_booking_min_notice_hours' => 24,
            'online_booking_auto_confirm' => false,
            'reminder_sms_hours_before' => 24,
            'reminder_email_hours_before' => 48,
        ];
    }

    public function onlineBookingEnabled(): self
    {
        return $this->state(fn (): array => [
            'online_booking_enabled' => true,
            'online_booking_auto_confirm' => true,
        ]);
    }
}
