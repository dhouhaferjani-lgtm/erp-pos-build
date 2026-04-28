<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduling — AppointmentService satellites. Per Spec D §5.1.
 *
 * Polymorphic reference to Service or ServiceBundle via (service_ref_type,
 * service_ref_id). CHECK constraint enforces service_ref_type IN
 * ('service','bundle') on PostgreSQL.
 *
 * Monetary column estimated_price uses NUMERIC(14,3) to support TND 3-decimal
 * scales; the Application DTO formats via CurrencyScale::bcformat (see
 * project_monetary_precision memo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_appointment_services', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('appointment_id')
                ->constrained('scheduling_appointments')
                ->cascadeOnDelete();

            $table->string('service_ref_type', 16);
            $table->uuid('service_ref_id');

            $table->string('display_name', 200);
            $table->integer('estimated_duration_minutes');
            $table->decimal('estimated_price', 14, 3)->nullable();
            $table->integer('display_order')->default(0);

            $table->timestampsTz();

            $table->index(['appointment_id', 'display_order'], 'idx_sas_appt');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE scheduling_appointment_services ADD CONSTRAINT chk_sas_service_ref_type CHECK '.
                "(service_ref_type IN ('service','bundle'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_appointment_services');
    }
};
