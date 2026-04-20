<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduling — per-location scheduling configuration. Per Spec D §5.1.
 *
 * One row per (tenant, company, location). Controls time-slot granularity,
 * walk-in buffer, overbooking threshold, online-booking toggles, and
 * per-channel reminder windows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();

            $table->integer('time_slot_minutes')->default(30);
            $table->integer('default_appointment_duration_minutes')->default(60);
            $table->decimal('walk_in_buffer_hours_per_day', 5, 2)->default(0);
            $table->integer('overbooking_threshold_percent')->default(100);

            $table->boolean('online_booking_enabled')->default(false);
            $table->integer('online_booking_advance_days')->default(30);
            $table->integer('online_booking_min_notice_hours')->default(24);
            $table->boolean('online_booking_auto_confirm')->default(false);

            $table->integer('reminder_sms_hours_before')->nullable()->default(24);
            $table->integer('reminder_email_hours_before')->nullable()->default(48);

            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id', 'location_id'], 'uq_sc_tenant_company_location');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE scheduling_configs ADD CONSTRAINT chk_sc_time_slot_minutes CHECK '.
                '(time_slot_minutes IN (15, 30, 60))'
            );
            DB::statement(
                'ALTER TABLE scheduling_configs ADD CONSTRAINT chk_sc_overbooking_threshold CHECK '.
                '(overbooking_threshold_percent BETWEEN 50 AND 200)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_configs');
    }
};
