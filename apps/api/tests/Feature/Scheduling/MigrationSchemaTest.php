<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Validates Task 2's migrations: 5 tables (`scheduling_bays`, `scheduling_configs`,
 * `scheduling_appointments`, `scheduling_appointment_services`,
 * `scheduling_appointment_status_transitions`), plus the PostgreSQL-only
 * `btree_gist` extension + GiST exclusion constraint on
 * `scheduling_appointments(bay_id, scheduled_start/end range)`.
 *
 * Portable assertions (Schema::hasColumn) run on SQLite + Postgres; the
 * GiST / CHECK constraint assertions are skipped when the driver is not
 * Postgres (SQLite CI runs).
 */
final class MigrationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduling_bays_table_exists_with_all_columns(): void
    {
        $this->assertTrue(Schema::hasTable('scheduling_bays'));

        foreach ([
            'id', 'tenant_id', 'company_id', 'location_id',
            'code', 'name', 'bay_type', 'display_order',
            'operating_hours', 'notes', 'is_active',
            'created_at', 'updated_at', 'deleted_at',
        ] as $c) {
            $this->assertTrue(
                Schema::hasColumn('scheduling_bays', $c),
                "scheduling_bays missing column {$c}"
            );
        }
    }

    public function test_scheduling_configs_table_exists_with_all_columns(): void
    {
        $this->assertTrue(Schema::hasTable('scheduling_configs'));

        foreach ([
            'id', 'tenant_id', 'company_id', 'location_id',
            'time_slot_minutes', 'default_appointment_duration_minutes',
            'walk_in_buffer_hours_per_day', 'overbooking_threshold_percent',
            'online_booking_enabled', 'online_booking_advance_days',
            'online_booking_min_notice_hours', 'online_booking_auto_confirm',
            'reminder_sms_hours_before', 'reminder_email_hours_before',
            'created_at', 'updated_at',
        ] as $c) {
            $this->assertTrue(
                Schema::hasColumn('scheduling_configs', $c),
                "scheduling_configs missing column {$c}"
            );
        }
    }

    public function test_scheduling_appointments_table_exists_with_all_columns(): void
    {
        $this->assertTrue(Schema::hasTable('scheduling_appointments'));

        foreach ([
            'id', 'tenant_id', 'company_id', 'location_id',
            'appointment_number',
            'bay_id', 'primary_technician_profile_id',
            'customer_partner_id', 'vehicle_id',
            'customer_name', 'customer_phone', 'customer_email',
            'vehicle_plate', 'vehicle_description',
            'appointment_type', 'wait_type', 'status',
            'scheduled_start', 'scheduled_end', 'estimated_duration_minutes',
            'actual_arrival_at', 'actual_start_at', 'actual_end_at',
            'services_summary', 'customer_notes', 'internal_notes', 'color_label',
            'source', 'online_booking_token', 'is_auto_confirmed',
            'work_order_id',
            'last_reminder_sms_sent_at', 'last_reminder_email_sent_at',
            'created_at', 'updated_at', 'deleted_at',
        ] as $c) {
            $this->assertTrue(
                Schema::hasColumn('scheduling_appointments', $c),
                "scheduling_appointments missing column {$c}"
            );
        }
    }

    public function test_scheduling_appointment_services_table_exists_with_all_columns(): void
    {
        $this->assertTrue(Schema::hasTable('scheduling_appointment_services'));

        foreach ([
            'id', 'tenant_id', 'appointment_id',
            'service_ref_type', 'service_ref_id',
            'display_name', 'estimated_duration_minutes', 'estimated_price',
            'display_order', 'created_at',
        ] as $c) {
            $this->assertTrue(
                Schema::hasColumn('scheduling_appointment_services', $c),
                "scheduling_appointment_services missing column {$c}"
            );
        }
    }

    public function test_scheduling_appointment_status_transitions_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('scheduling_appointment_status_transitions'));

        foreach ([
            'id', 'tenant_id', 'appointment_id',
            'from_status', 'to_status', 'reason_code',
            'triggered_by_user_id', 'triggered_at', 'context',
        ] as $c) {
            $this->assertTrue(
                Schema::hasColumn('scheduling_appointment_status_transitions', $c),
                "scheduling_appointment_status_transitions missing column {$c}"
            );
        }
    }

    public function test_btree_gist_extension_enabled_on_postgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('btree_gist is a PostgreSQL-only extension.');
        }

        $row = DB::selectOne("SELECT COUNT(*) AS n FROM pg_extension WHERE extname = 'btree_gist'");
        $this->assertSame(1, (int) $row->n, 'btree_gist extension must be enabled before the GiST exclusion constraint is created.');
    }

    public function test_no_bay_overlap_exclusion_constraint_exists_on_postgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('GiST exclusion constraints are PostgreSQL-only.');
        }

        $row = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS n
            FROM pg_constraint
            WHERE conname = 'no_bay_overlap'
              AND contype = 'x'
        SQL);

        $this->assertSame(1, (int) $row->n, 'no_bay_overlap exclusion constraint (GiST) must exist on scheduling_appointments.');
    }

    public function test_chk_appt_range_check_constraint_exists_on_postgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint introspection is PostgreSQL-only.');
        }

        $row = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS n
            FROM pg_constraint
            WHERE conname = 'chk_appt_range'
              AND contype = 'c'
        SQL);

        $this->assertSame(1, (int) $row->n, 'chk_appt_range CHECK constraint must exist on scheduling_appointments.');
    }

    public function test_scheduling_appointment_services_service_ref_type_check_constraint_exists_on_postgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint introspection is PostgreSQL-only.');
        }

        $row = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS n
            FROM pg_constraint
            WHERE conname = 'chk_sas_service_ref_type'
              AND contype = 'c'
        SQL);

        $this->assertSame(1, (int) $row->n, 'chk_sas_service_ref_type CHECK constraint must exist on scheduling_appointment_services.');
    }
}
