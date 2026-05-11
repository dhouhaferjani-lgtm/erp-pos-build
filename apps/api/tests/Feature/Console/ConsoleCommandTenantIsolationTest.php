<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Modules\Company\Domain\Company;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\AppointmentReminder;
use App\Modules\Scheduling\Domain\Enums\AppointmentSource;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Enums\AppointmentType;
use App\Modules\Scheduling\Domain\Enums\ReminderChannel;
use App\Modules\Scheduling\Domain\Enums\ReminderDeliveryStatus;
use App\Modules\Scheduling\Domain\Enums\WaitType;
use App\Modules\Scheduling\Infrastructure\Jobs\DispatchAppointmentReminder;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Technician\Domain\Contracts\TechnicianCertificationRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Section 14 (api.console-commands cluster) — tenant-isolation regression
 * coverage for the 3 cat-(a) Artisan commands flagged by the triage at
 * docs/superpowers/audits/2026-05-06-api-console-commands-triage.md.
 *
 * Categories:
 *   - cat-(a-singleshot): nf525:export-jet — must take --tenant + --company
 *     and reject mismatched pairs.
 *   - cat-(a-per-tenant-iter): scheduling:schedule-appointment-reminders
 *     and workshop:check-expiring-certifications — must NOT issue cross-
 *     tenant queries from the command body, and the dispatched
 *     DispatchAppointmentReminder job must rebind tenant context from the
 *     reminder's stored tenant_id BEFORE reading any DB rows.
 *
 * Cross-references the inventory at:
 *   docs/superpowers/plans/tenant-isolation-sweep-inventory.yml
 *   (api.console-commands.001 .. api.console-commands.003)
 */
final class ConsoleCommandTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = $this->makeTenant('console-tenant-a');
        $this->tenantB = $this->makeTenant('console-tenant-b');

        $this->companyA = Company::factory()->create(['tenant_id' => $this->tenantA->id]);
        $this->companyB = Company::factory()->create(['tenant_id' => $this->tenantB->id]);
    }

    // =========================================================================
    // ExportNf525JetCommand (cat-a-singleshot)
    // =========================================================================

    /**
     * After the cat-(a) wiring lands, `nf525:export-jet` must accept a
     * `--tenant=<uuid>` option that matches the parent of the supplied
     * `--company=<uuid>`. Today the option is unknown to the command's
     * signature, so Artisan throws "The '--tenant' option does not exist."
     * and exits non-zero — RED.
     *
     * Inventory: api.console-commands.001
     */
    public function test_export_jet_accepts_matching_tenant_and_company(): void
    {
        $outputPath = storage_path('app/test-jet-same-tenant-'.Str::random(8).'.xml');

        try {
            $exitCode = Artisan::call('nf525:export-jet', [
                '--tenant' => $this->tenantA->id,
                '--company' => $this->companyA->id,
                '--from' => '2026-01-01',
                '--to' => '2026-03-31',
                '--output' => $outputPath,
            ]);
            $output = (string) Artisan::output();
        } finally {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
        }

        // After cat-(a-singleshot) wiring lands: validation passes (matching
        // tenant + company), the export service may legitimately fail for
        // "no fiscal data" reasons, but the unknown-option failure mode is
        // gone. Concretely: exit must NOT be the option-unknown error.
        $this->assertStringNotContainsString(
            'option does not exist',
            strtolower($output),
            'Command must accept --tenant after wiring; got option-unknown error: '.$output,
        );
        $this->assertNotEquals(
            -1,
            $exitCode,
            'Artisan must have run the command (returned an exit code, not a thrown exception). Output: '.$output,
        );
    }

    /**
     * Cross-tenant denial: `--tenant=<tenantA.id> --company=<companyB.id>` must
     * fail with a tenant-ownership validation error. Today the command
     * silently ignores --tenant (option not registered), so the cross-tenant
     * combo would be accepted and exported anyway — RED.
     *
     * Inventory: api.console-commands.001
     */
    public function test_export_jet_rejects_cross_tenant_company(): void
    {
        $outputPath = storage_path('app/test-jet-cross-tenant-'.Str::random(8).'.xml');

        try {
            $exitCode = Artisan::call('nf525:export-jet', [
                '--tenant' => $this->tenantA->id,
                '--company' => $this->companyB->id,  // belongs to tenantB
                '--from' => '2026-01-01',
                '--to' => '2026-03-31',
                '--output' => $outputPath,
            ]);
            $output = strtolower((string) Artisan::output());
        } finally {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
        }

        $this->assertNotSame(0, $exitCode, 'Cross-tenant --company must be rejected.');
        $this->assertTrue(
            str_contains($output, 'company') && (
                str_contains($output, 'belong')
                || str_contains($output, 'tenant')
                || str_contains($output, 'invalid')
            ),
            'Cross-tenant rejection must mention company/tenant validation, not just option-unknown. Got: '.$output,
        );
    }

    // =========================================================================
    // ScheduleAppointmentReminders (cat-a-per-tenant-iter)
    // =========================================================================

    /**
     * After the per-tenant iteration refactor lands, the scheduler must NOT
     * issue a single cross-tenant `Appointment::query()` — it must wrap the
     * upcoming-appointment lookup in a per-tenant context.
     *
     * Today the scheduler at ScheduleAppointmentReminders.php:71-79 issues:
     *   select * from "scheduling_appointments" where "status" in (?, ?, ?)
     *     and "scheduled_start" between ? and ?
     * with NO tenant_id predicate — RED.
     *
     * After fix: per-tenant iteration wraps each tenant's query, so every
     * appointments select has `tenant_id` literal in the WHERE clause.
     *
     * Inventory: api.console-commands.002
     */
    public function test_schedule_appointment_reminders_query_is_tenant_scoped(): void
    {
        // Seed one upcoming appointment in each tenant. Schedule them >24h
        // out (but within the default 48h horizon) so AppointmentReminder
        // Service::scheduleFor() actually exercises its existing-reminder
        // lookup — otherwise the service returns early via its 24h-window
        // guard and the unscoped reminder read is never reached (Codex
        // round-1 finding 2).
        $this->seedUpcomingAppointment($this->tenantA, $this->companyA);
        $this->seedUpcomingAppointment($this->tenantB, $this->companyB);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            Artisan::call('scheduling:schedule-appointment-reminders');
        } catch (\Throwable) {
            // Today the command may throw or succeed — what matters is the
            // SQL shape captured in the query log.
        }

        $log = DB::getQueryLog();

        $appointmentSelects = array_values(array_filter(
            $log,
            static fn (array $q): bool => str_contains($q['query'], 'scheduling_appointments')
                && stripos($q['query'], 'select') === 0,
        ));

        $reminderSelects = array_values(array_filter(
            $log,
            static fn (array $q): bool => str_contains($q['query'], 'scheduling_appointment_reminders')
                && stripos($q['query'], 'select') === 0,
        ));

        DB::disableQueryLog();

        $this->assertNotEmpty(
            $appointmentSelects,
            'Expected at least one SELECT against scheduling_appointments during the scheduler run.',
        );

        foreach ($appointmentSelects as $q) {
            $this->assertStringContainsString(
                'tenant_id',
                $q['query'],
                'Every scheduling_appointments select must filter by tenant_id after per-tenant iteration refactor. Query: '.$q['query'],
            );
        }

        // Codex round-1 finding 1 + 2: the scheduler reaches AppointmentReminder
        // Service::scheduleFor() which previously did an unscoped existing-
        // reminder lookup. After the fix, that lookup carries tenant_id too.
        $this->assertNotEmpty(
            $reminderSelects,
            'Expected at least one SELECT against scheduling_appointment_reminders during the scheduler run (otherwise the test does not exercise the existing-reminder lookup branch).',
        );

        foreach ($reminderSelects as $q) {
            $this->assertStringContainsString(
                'tenant_id',
                $q['query'],
                'Every scheduling_appointment_reminders select must filter by tenant_id after the AppointmentReminderService fix. Query: '.$q['query'],
            );
        }
    }

    /**
     * The dispatched DispatchAppointmentReminder job must rebind tenant
     * context from the reminder's stored tenant_id BEFORE reading any DB
     * row. Today its `handle()` calls `AppointmentReminder::query()->find($id)`
     * with no tenant predicate (DispatchAppointmentReminder.php:47) — RED.
     *
     * After fix: the job's first reminder lookup must filter by tenant_id, OR
     * the job must call CompanyContext->setCompanyId(...) using the parent
     * appointment's tenant_id which then activates a global scope on the
     * AppointmentReminder model.
     *
     * Inventory: api.console-commands.002
     */
    public function test_dispatch_appointment_reminder_job_rebinds_tenant_scope(): void
    {
        $appointment = $this->seedUpcomingAppointment($this->tenantA, $this->companyA);
        $reminder = AppointmentReminder::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'appointment_id' => $appointment->id,
            'channel' => ReminderChannel::Sms->value,
            'scheduled_for' => Carbon::now()->subMinute(),
            'delivery_status' => ReminderDeliveryStatus::Pending->value,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            (new DispatchAppointmentReminder($reminder->id, $reminder->tenant_id))->handle();
        } catch (\Throwable) {
            // Today the job may succeed or throw; we only care about query shape.
        }

        $log = DB::getQueryLog();

        $reminderSelects = array_values(array_filter(
            $log,
            static fn (array $q): bool => str_contains($q['query'], 'scheduling_appointment_reminders')
                && stripos($q['query'], 'select') === 0,
        ));

        DB::disableQueryLog();

        $this->assertNotEmpty(
            $reminderSelects,
            'Expected at least one SELECT against scheduling_appointment_reminders during job handle().',
        );

        foreach ($reminderSelects as $q) {
            $this->assertStringContainsString(
                'tenant_id',
                $q['query'],
                'Every scheduling_appointment_reminders select must filter by tenant_id after the job rebinds context. Query: '.$q['query'],
            );
        }
    }

    // =========================================================================
    // CheckExpiringCertifications (cat-a-per-tenant-iter)
    // =========================================================================

    /**
     * The repository contract must accept a tenant scope arg. Today
     * `findExpiringWithin(int $days): Collection` (TechnicianCertification
     * RepositoryInterface.php:25) returns a cross-tenant collection with no
     * scope arg — RED.
     *
     * After fix: signature has at least one additional parameter (e.g.
     * `string $tenantId` or `?string $tenantId`).
     *
     * Inventory: api.console-commands.003
     */
    public function test_find_expiring_within_repository_contract_takes_scope_arg(): void
    {
        $method = new ReflectionMethod(
            TechnicianCertificationRepositoryInterface::class,
            'findExpiringWithin',
        );

        $params = $method->getParameters();
        $this->assertGreaterThan(
            1,
            count($params),
            'TechnicianCertificationRepositoryInterface::findExpiringWithin must accept a tenant/company scope arg in addition to $days. Today the contract is unscoped, which makes the entire CheckExpiringCertifications command cross-tenant by accident.',
        );

        // Defense-in-depth: at least one of the additional params should be
        // tenant-scoped by name (tenantId / companyId / scope).
        $paramNames = array_map(static fn ($p) => strtolower($p->getName()), $params);
        $scopeNames = array_filter(
            $paramNames,
            static fn (string $n): bool => str_contains($n, 'tenant') || str_contains($n, 'company') || str_contains($n, 'scope'),
        );
        $this->assertNotEmpty(
            $scopeNames,
            'At least one parameter of findExpiringWithin must mention tenant/company/scope. Got: '.implode(', ', $paramNames),
        );
    }

    /**
     * After the per-tenant iteration refactor lands, the command must NOT
     * surface certs across tenants in a single repository call. Structural
     * SQL-log invariant: every certifications query during the command run
     * has `tenant_id` in the WHERE clause.
     *
     * Today the repository's `findExpiringWithin` (whatever its
     * implementation does) is contractually unscoped, so the SQL it emits
     * has no tenant_id predicate — RED.
     *
     * Inventory: api.console-commands.003
     */
    public function test_check_expiring_certifications_query_is_tenant_scoped(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            Artisan::call('workshop:check-expiring-certifications');
        } catch (\Throwable) {
            // Today the command may run without rows; we only care about SQL shape.
        }

        $log = DB::getQueryLog();

        $certSelects = array_values(array_filter(
            $log,
            static fn (array $q): bool => str_contains($q['query'], 'workshop_technician_certifications')
                && stripos($q['query'], 'select') === 0,
        ));

        DB::disableQueryLog();

        // After fix: the per-tenant iteration always emits a tenant_id-scoped
        // query, even when there are zero rows for any tenant. Today: nothing
        // emits a tenant-scoped query, so this assertion is RED in two ways
        // (either no select happens at all, or the select has no tenant_id).
        $this->assertNotEmpty(
            $certSelects,
            'Expected at least one SELECT against workshop_technician_certifications after per-tenant iteration refactor.',
        );

        foreach ($certSelects as $q) {
            $this->assertStringContainsString(
                'tenant_id',
                $q['query'],
                'Every workshop_technician_certifications select must filter by tenant_id after refactor. Query: '.$q['query'],
            );
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => "Tenant {$slug}",
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function seedUpcomingAppointment(Tenant $tenant, Company $company): Appointment
    {
        // Reach a location row for this company; the FK is required.
        // Note: `locations` table is keyed by company_id only (no tenant_id).
        $locationId = (string) DB::table('locations')->where('company_id', $company->id)->value('id');
        if ($locationId === '') {
            $locationId = (string) Str::uuid();
            DB::table('locations')->insert([
                'id' => $locationId,
                'company_id' => $company->id,
                'code' => strtoupper(Str::random(4)),
                'name' => 'Test Location',
                'type' => 'shop',
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Pick a start >24h out (so AppointmentReminderService::scheduleFor()'s
        // 24h-before-start guard does NOT short-circuit) AND <48h out (so
        // it falls within the scheduler's default horizon).
        $start = Carbon::now()->addHours(36);
        $end = $start->copy()->addHour();

        $appointment = new Appointment;
        $appointment->id = (string) Str::uuid();
        $appointment->tenant_id = $tenant->id;
        $appointment->company_id = $company->id;
        $appointment->location_id = $locationId;
        $appointment->appointment_number = 'APPT-'.strtoupper(Str::random(6));
        $appointment->customer_name = 'Test Customer';
        $appointment->customer_phone = '+33000000000';
        $appointment->customer_email = 'customer@example.com';
        $appointment->appointment_type = AppointmentType::StandardRepair;
        $appointment->wait_type = WaitType::DropOff;
        $appointment->status = AppointmentStatus::Scheduled;
        $appointment->scheduled_start = $start;
        $appointment->scheduled_end = $end;
        $appointment->estimated_duration_minutes = 60;
        $appointment->source = AppointmentSource::Manual;
        $appointment->is_auto_confirmed = false;
        $appointment->save();

        return $appointment;
    }
}
