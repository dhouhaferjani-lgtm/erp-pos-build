<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes audit finding 🟠-1: adds the reverse link
 * `workshop_work_orders.appointment_id` so the bidirectional
 * appointment↔work_order relationship is queryable from either side.
 *
 * Before this migration, AppointmentConversionService wrote only
 * `scheduling_appointments.work_order_id`; downstream analytics
 * correlating WO throughput with appointment channel (storefront vs walk-in)
 * had to join back through scheduling_appointments on every query.
 *
 * Additive & nullable — pre-existing WOs keep `appointment_id = NULL`
 * (i.e. they were opened directly at intake, not from an appointment). FK
 * is `ON DELETE SET NULL`: a WO is a fiscal record and must outlive its
 * source appointment (matching the convention already used on the
 * reverse side `scheduling_appointments.work_order_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_work_orders', function (Blueprint $table): void {
            $table->foreignUuid('appointment_id')
                ->nullable()
                ->after('primary_technician_profile_id')
                ->constrained('scheduling_appointments')
                ->nullOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX idx_wwo_appointment ON workshop_work_orders (appointment_id) '.
                'WHERE appointment_id IS NOT NULL'
            );
        } else {
            Schema::table('workshop_work_orders', function (Blueprint $table): void {
                $table->index('appointment_id', 'idx_wwo_appointment');
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_wwo_appointment');
        }

        Schema::table('workshop_work_orders', function (Blueprint $table): void {
            if (DB::connection()->getDriverName() !== 'pgsql') {
                $table->dropIndex('idx_wwo_appointment');
            }
            $table->dropForeign(['appointment_id']);
            $table->dropColumn('appointment_id');
        });
    }
};
