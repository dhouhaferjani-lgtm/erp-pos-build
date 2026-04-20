<?php

declare(strict_types=1);

namespace Database\Factories\Scheduling;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Domain\Enums\AppointmentSource;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Enums\AppointmentType;
use App\Modules\Scheduling\Domain\Enums\WaitType;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Appointment>
 */
final class AppointmentFactory extends Factory
{
    /** @var class-string<Appointment> */
    protected $model = Appointment::class;

    /**
     * Default state: Scheduled appointment tomorrow 10:00–11:00 local time,
     * standard repair, drop-off, manual source. Customer + vehicle are
     * optional at the aggregate level but seeded here so the happy-path
     * conversion test has the data it needs.
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
        $bay = Bay::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);
        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $start = $this->faker->dateTimeBetween('+1 day 09:00', '+5 day 16:00');
        $startImmutable = \DateTimeImmutable::createFromMutable($start);
        $durationMinutes = 60;
        $endImmutable = $startImmutable->modify("+{$durationMinutes} minutes");

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'appointment_number' => 'APT-'.date('Y').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'bay_id' => $bay->id,
            'primary_technician_profile_id' => null,
            'customer_partner_id' => $partner->id,
            'vehicle_id' => $vehicle->id,
            'customer_name' => null,
            'customer_phone' => null,
            'customer_email' => null,
            'vehicle_plate' => null,
            'vehicle_description' => null,
            'appointment_type' => AppointmentType::StandardRepair->value,
            'wait_type' => WaitType::DropOff->value,
            'status' => AppointmentStatus::Scheduled->value,
            'scheduled_start' => $startImmutable,
            'scheduled_end' => $endImmutable,
            'estimated_duration_minutes' => $durationMinutes,
            'actual_arrival_at' => null,
            'actual_start_at' => null,
            'actual_end_at' => null,
            'services_summary' => null,
            'customer_notes' => null,
            'internal_notes' => null,
            'color_label' => null,
            'source' => AppointmentSource::Manual->value,
            'online_booking_token' => null,
            'is_auto_confirmed' => false,
            'work_order_id' => null,
            'last_reminder_sms_sent_at' => null,
            'last_reminder_email_sent_at' => null,
        ];
    }

    public function confirmed(): self
    {
        return $this->state(fn (): array => ['status' => AppointmentStatus::Confirmed->value]);
    }

    public function checkedIn(): self
    {
        return $this->state(fn (): array => [
            'status' => AppointmentStatus::CheckedIn->value,
            'actual_arrival_at' => new \DateTimeImmutable,
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (): array => ['status' => AppointmentStatus::Cancelled->value]);
    }

    public function online(): self
    {
        return $this->state(fn (): array => [
            'source' => AppointmentSource::Online->value,
            'online_booking_token' => Str::random(64),
        ]);
    }

    /**
     * Use a specific bay and window — the primary way to set up scenarios
     * where the GiST exclusion must be exercised.
     */
    public function onBay(string $bayId, \DateTimeImmutable $start, \DateTimeImmutable $end): self
    {
        return $this->state(fn (): array => [
            'bay_id' => $bayId,
            'scheduled_start' => $start,
            'scheduled_end' => $end,
            'estimated_duration_minutes' => (int) (($end->getTimestamp() - $start->getTimestamp()) / 60),
        ]);
    }
}
