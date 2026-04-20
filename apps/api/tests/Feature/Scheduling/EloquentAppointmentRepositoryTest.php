<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Company\Domain\Company;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Infrastructure\Persistence\EloquentAppointmentRepository;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for the EloquentAppointmentRepository implementation.
 *
 * Covers:
 *  - findForUpdate acquires a row lock inside a transaction (+ throws when missing).
 *  - findOverlapping excludes cancelled / no_show appointments.
 *  - findOverlapping honours excludeAppointmentId (reschedule self-overlap safe).
 *  - findByWorkOrderId returns the correct appointment.
 *  - paginate applies status filter.
 */
final class EloquentAppointmentRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private AppointmentRepositoryInterface $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = $this->app->make(AppointmentRepositoryInterface::class);
    }

    public function test_binding_is_eloquent_impl(): void
    {
        $this->assertInstanceOf(EloquentAppointmentRepository::class, $this->repo);
    }

    public function test_find_by_id_returns_appointment(): void
    {
        $appt = Appointment::factory()->create();

        $found = $this->repo->findById($appt->id);

        $this->assertNotNull($found);
        $this->assertSame($appt->id, $found->id);
    }

    public function test_find_by_id_returns_null_when_missing(): void
    {
        $this->assertNull($this->repo->findById('00000000-0000-0000-0000-000000000000'));
    }

    public function test_find_for_update_returns_appointment_inside_transaction(): void
    {
        $appt = Appointment::factory()->create();

        $found = DB::transaction(fn () => $this->repo->findForUpdate($appt->id));

        $this->assertSame($appt->id, $found->id);
    }

    public function test_find_for_update_throws_when_missing(): void
    {
        $this->expectException(ModelNotFoundException::class);
        DB::transaction(fn () => $this->repo->findForUpdate('00000000-0000-0000-0000-000000000000'));
    }

    public function test_find_overlapping_returns_overlapping_appointments(): void
    {
        $bay = Bay::factory()->create();
        $start = new \DateTimeImmutable('2026-05-01 10:00:00');
        $end = new \DateTimeImmutable('2026-05-01 11:00:00');

        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-01 10:30:00'),
            new \DateTimeImmutable('2026-05-01 11:30:00'),
        )->create();

        $overlap = $this->repo->findOverlapping($bay->id, $start, $end, null);

        $this->assertCount(1, $overlap);
    }

    public function test_find_overlapping_excludes_cancelled_and_no_show(): void
    {
        $bay = Bay::factory()->create();
        $start = new \DateTimeImmutable('2026-05-01 10:00:00');
        $end = new \DateTimeImmutable('2026-05-01 11:00:00');

        // Cancelled appointment on the same window — must not count as a conflict
        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-01 10:00:00'),
            new \DateTimeImmutable('2026-05-01 11:00:00'),
        )->cancelled()->create();

        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-01 10:15:00'),
            new \DateTimeImmutable('2026-05-01 10:45:00'),
        )->state(fn () => ['status' => AppointmentStatus::NoShow->value])->create();

        $overlap = $this->repo->findOverlapping($bay->id, $start, $end, null);

        $this->assertCount(0, $overlap);
    }

    public function test_find_overlapping_respects_exclude_appointment_id(): void
    {
        $bay = Bay::factory()->create();
        $existing = Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-01 10:00:00'),
            new \DateTimeImmutable('2026-05-01 11:00:00'),
        )->create();

        // Caller passes its own id (reschedule) — must not conflict with itself.
        $overlap = $this->repo->findOverlapping(
            $bay->id,
            new \DateTimeImmutable('2026-05-01 10:00:00'),
            new \DateTimeImmutable('2026-05-01 11:00:00'),
            $existing->id,
        );

        $this->assertCount(0, $overlap);
    }

    public function test_find_overlapping_boundary_half_open(): void
    {
        $bay = Bay::factory()->create();
        // Existing 10:00-11:00
        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-01 10:00:00'),
            new \DateTimeImmutable('2026-05-01 11:00:00'),
        )->create();

        // New 11:00-12:00 — touches boundary, half-open means no overlap.
        $overlap = $this->repo->findOverlapping(
            $bay->id,
            new \DateTimeImmutable('2026-05-01 11:00:00'),
            new \DateTimeImmutable('2026-05-01 12:00:00'),
            null,
        );

        $this->assertCount(0, $overlap);
    }

    public function test_find_by_work_order_id_returns_appointment(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $appt = Appointment::factory()->create(['work_order_id' => $workOrder->id]);

        $found = $this->repo->findByWorkOrderId($workOrder->id);

        $this->assertNotNull($found);
        $this->assertSame($appt->id, $found->id);
    }

    public function test_find_by_work_order_id_returns_null_when_missing(): void
    {
        $this->assertNull($this->repo->findByWorkOrderId('22222222-2222-2222-2222-222222222222'));
    }

    public function test_paginate_filters_by_status(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        Appointment::factory()->count(2)->create(['company_id' => $company->id]);
        Appointment::factory()->count(3)->cancelled()->create(['company_id' => $company->id]);

        $page = $this->repo->paginate(
            $company->id,
            ['status' => AppointmentStatus::Scheduled],
            perPage: 10,
        );

        $this->assertSame(2, $page->total());
    }

    public function test_save_persists_appointment(): void
    {
        $appt = Appointment::factory()->make();

        $this->repo->save($appt);

        $this->assertDatabaseHas('scheduling_appointments', ['id' => $appt->id]);
    }

    public function test_find_by_company_in_date_range(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        Appointment::factory()->create([
            'company_id' => $company->id,
            'scheduled_start' => new \DateTimeImmutable('2026-05-01 10:00:00'),
            'scheduled_end' => new \DateTimeImmutable('2026-05-01 11:00:00'),
        ]);
        Appointment::factory()->create([
            'company_id' => $company->id,
            'scheduled_start' => new \DateTimeImmutable('2026-05-10 10:00:00'),
            'scheduled_end' => new \DateTimeImmutable('2026-05-10 11:00:00'),
        ]);

        $in = $this->repo->findByCompanyInDateRange(
            $company->id,
            new \DateTimeImmutable('2026-05-01 00:00:00'),
            new \DateTimeImmutable('2026-05-02 00:00:00'),
        );

        $this->assertCount(1, $in);
    }
}
