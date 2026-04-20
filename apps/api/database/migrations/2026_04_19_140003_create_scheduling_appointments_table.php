<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduling — Appointments (aggregate root). Per Spec D §5.1.
 *
 * Enables the `btree_gist` extension (idempotent), then declares two
 * PG-only integrity guards via raw DDL:
 *
 *   - chk_appt_range: scheduled_end > scheduled_start.
 *   - no_bay_overlap: EXCLUDE USING gist (bay_id, tstzrange(start,end,'[)'))
 *     WHERE bay_id IS NOT NULL AND status NOT IN ('cancelled','no_show')
 *     AND deleted_at IS NULL. This is the DB-level backstop against
 *     double-booking the same bay. Predicate excludes `cancelled` so a
 *     cancelled appointment releases its slot without requiring a hard delete.
 *
 * chk_appt_range is declared ONLY via DB::statement — do NOT add a Blueprint
 * ->check(...) call (duplicate-constraint error).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        }

        Schema::create('scheduling_appointments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();

            $table->string('appointment_number', 32);

            $table->foreignUuid('bay_id')->nullable()->constrained('scheduling_bays')->nullOnDelete();
            $table->foreignUuid('primary_technician_profile_id')
                ->nullable()
                ->constrained('workshop_technician_profiles')
                ->nullOnDelete();

            $table->foreignUuid('customer_partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->foreignUuid('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();

            // Denormalized customer/vehicle fields (online-booking may not have
            // resolved to real Partner/Vehicle rows yet)
            $table->string('customer_name', 200)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('customer_email', 200)->nullable();
            $table->string('vehicle_plate', 30)->nullable();
            $table->string('vehicle_description', 200)->nullable();

            $table->string('appointment_type', 32);
            $table->string('wait_type', 16)->default('drop_off');
            $table->string('status', 16)->default('scheduled');

            $table->timestampTz('scheduled_start');
            $table->timestampTz('scheduled_end');
            $table->integer('estimated_duration_minutes');

            $table->timestampTz('actual_arrival_at')->nullable();
            $table->timestampTz('actual_start_at')->nullable();
            $table->timestampTz('actual_end_at')->nullable();

            $table->text('services_summary')->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('color_label', 16)->nullable();

            $table->string('source', 16)->default('manual');
            $table->string('online_booking_token', 64)->nullable();
            $table->boolean('is_auto_confirmed')->default(false);

            $table->foreignUuid('work_order_id')
                ->nullable()
                ->constrained('workshop_work_orders')
                ->nullOnDelete();

            // Reminder idempotency — per-channel "last dispatched" stamp so the
            // hourly reminder command does not double-send. See Task 14.
            $table->timestampTz('last_reminder_sms_sent_at')->nullable();
            $table->timestampTz('last_reminder_email_sent_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['company_id', 'status', 'scheduled_start'], 'idx_sa_company_status');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE scheduling_appointments ADD CONSTRAINT chk_appt_range '.
                'CHECK (scheduled_end > scheduled_start)'
            );
            DB::statement(
                'ALTER TABLE scheduling_appointments ADD CONSTRAINT chk_sa_status CHECK '.
                "(status IN ('scheduled','confirmed','checked_in','in_progress','completed','closed','no_show','cancelled'))"
            );
            DB::statement(
                'ALTER TABLE scheduling_appointments ADD CONSTRAINT chk_sa_appointment_type CHECK '.
                "(appointment_type IN ('quick_service','inspection','diagnostic','standard_repair',".
                "'major_repair','maintenance','tire_service','bodywork','other'))"
            );
            DB::statement(
                'ALTER TABLE scheduling_appointments ADD CONSTRAINT chk_sa_wait_type CHECK '.
                "(wait_type IN ('waiter','drop_off','pickup_scheduled'))"
            );
            DB::statement(
                'ALTER TABLE scheduling_appointments ADD CONSTRAINT chk_sa_source CHECK '.
                "(source IN ('manual','phone','online','walkin'))"
            );

            DB::statement(
                'CREATE UNIQUE INDEX uq_sa_tenant_company_location_number ON scheduling_appointments '.
                '(tenant_id, company_id, location_id, appointment_number) WHERE deleted_at IS NULL'
            );
            DB::statement(
                'CREATE INDEX idx_sa_schedule ON scheduling_appointments (bay_id, scheduled_start, scheduled_end) '.
                "WHERE status NOT IN ('cancelled','no_show')"
            );
            DB::statement(
                'CREATE INDEX idx_sa_customer ON scheduling_appointments (customer_partner_id) '.
                'WHERE customer_partner_id IS NOT NULL'
            );
            DB::statement(
                'CREATE INDEX idx_sa_vehicle ON scheduling_appointments (vehicle_id) '.
                'WHERE vehicle_id IS NOT NULL'
            );
            DB::statement(
                'CREATE INDEX idx_sa_tech ON scheduling_appointments (primary_technician_profile_id) '.
                'WHERE primary_technician_profile_id IS NOT NULL'
            );

            // GiST exclusion constraint: primary invariant. Cancelled / no-show /
            // soft-deleted rows are excluded so they release their slot.
            DB::statement(
                "ALTER TABLE scheduling_appointments ADD CONSTRAINT no_bay_overlap\n".
                "EXCLUDE USING gist (\n".
                "    bay_id WITH =,\n".
                "    tstzrange(scheduled_start, scheduled_end, '[)') WITH &&\n".
                ")\n".
                "WHERE (bay_id IS NOT NULL AND status NOT IN ('cancelled','no_show') AND deleted_at IS NULL)"
            );
        } else {
            Schema::table('scheduling_appointments', function (Blueprint $table): void {
                $table->unique(
                    ['tenant_id', 'company_id', 'location_id', 'appointment_number'],
                    'uq_sa_tenant_company_location_number'
                );
                $table->index(['bay_id', 'scheduled_start', 'scheduled_end'], 'idx_sa_schedule');
                $table->index('customer_partner_id', 'idx_sa_customer');
                $table->index('vehicle_id', 'idx_sa_vehicle');
                $table->index('primary_technician_profile_id', 'idx_sa_tech');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_appointments');
        // btree_gist extension intentionally NOT dropped — other modules may depend on it.
    }
};
