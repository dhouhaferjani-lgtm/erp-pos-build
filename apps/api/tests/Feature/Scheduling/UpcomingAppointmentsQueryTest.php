<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Company\Domain\Company;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Scheduling\Application\Queries\UpcomingAppointmentsQuery;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UpcomingAppointmentsQueryTest extends TestCase
{
    use RefreshDatabase;

    private function makePartner(): Partner
    {
        $tenant = Tenant::first() ?? Tenant::factory()->create();
        $company = Company::where('tenant_id', $tenant->id)->first()
            ?? Company::factory()->create(['tenant_id' => $tenant->id]);

        return Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
    }

    public function test_returns_future_active_appointments_ordered_by_start(): void
    {
        $partner = $this->makePartner();
        $now = new \DateTimeImmutable('2026-05-01 12:00:00');

        $later = Appointment::factory()->create([
            'customer_partner_id' => $partner->id,
            'scheduled_start' => $now->modify('+2 days'),
            'scheduled_end' => $now->modify('+2 days +1 hour'),
            'status' => AppointmentStatus::Scheduled->value,
        ]);
        $soon = Appointment::factory()->create([
            'customer_partner_id' => $partner->id,
            'scheduled_start' => $now->modify('+1 day'),
            'scheduled_end' => $now->modify('+1 day +1 hour'),
            'status' => AppointmentStatus::Confirmed->value,
        ]);

        /** @var UpcomingAppointmentsQuery $query */
        $query = $this->app->make(UpcomingAppointmentsQuery::class);

        $result = $query->forCustomer($partner->id, 5, $now);

        $this->assertCount(2, $result);
        $first = $result->get(0);
        $second = $result->get(1);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($soon->id, $first->id);
        $this->assertSame($later->id, $second->id);
    }

    public function test_excludes_past_appointments(): void
    {
        $partner = $this->makePartner();
        $now = new \DateTimeImmutable('2026-05-01 12:00:00');

        Appointment::factory()->create([
            'customer_partner_id' => $partner->id,
            'scheduled_start' => $now->modify('-1 day'),
            'scheduled_end' => $now->modify('-1 day +1 hour'),
            'status' => AppointmentStatus::Scheduled->value,
        ]);

        /** @var UpcomingAppointmentsQuery $query */
        $query = $this->app->make(UpcomingAppointmentsQuery::class);

        $result = $query->forCustomer($partner->id, 5, $now);

        $this->assertCount(0, $result);
    }

    public function test_excludes_cancelled_no_show_closed_completed(): void
    {
        $partner = $this->makePartner();
        $now = new \DateTimeImmutable('2026-05-01 12:00:00');

        foreach ([
            AppointmentStatus::Cancelled,
            AppointmentStatus::NoShow,
            AppointmentStatus::Closed,
            AppointmentStatus::Completed,
        ] as $status) {
            Appointment::factory()->create([
                'customer_partner_id' => $partner->id,
                'scheduled_start' => $now->modify('+1 day'),
                'scheduled_end' => $now->modify('+1 day +1 hour'),
                'status' => $status->value,
            ]);
        }

        /** @var UpcomingAppointmentsQuery $query */
        $query = $this->app->make(UpcomingAppointmentsQuery::class);

        $result = $query->forCustomer($partner->id, 5, $now);

        $this->assertCount(0, $result);
    }

    public function test_respects_limit(): void
    {
        $partner = $this->makePartner();
        $now = new \DateTimeImmutable('2026-05-01 12:00:00');

        for ($i = 1; $i <= 5; $i++) {
            Appointment::factory()->create([
                'customer_partner_id' => $partner->id,
                'scheduled_start' => $now->modify("+{$i} days"),
                'scheduled_end' => $now->modify("+{$i} days +1 hour"),
                'status' => AppointmentStatus::Scheduled->value,
            ]);
        }

        /** @var UpcomingAppointmentsQuery $query */
        $query = $this->app->make(UpcomingAppointmentsQuery::class);

        $result = $query->forCustomer($partner->id, 3, $now);

        $this->assertCount(3, $result);
    }

    public function test_rejects_non_positive_limit(): void
    {
        $partner = $this->makePartner();
        /** @var UpcomingAppointmentsQuery $query */
        $query = $this->app->make(UpcomingAppointmentsQuery::class);

        $this->expectException(\InvalidArgumentException::class);
        $query->forCustomer($partner->id, 0);
    }
}
