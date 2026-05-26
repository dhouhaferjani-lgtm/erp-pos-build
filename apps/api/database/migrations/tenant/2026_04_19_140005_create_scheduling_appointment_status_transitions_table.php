<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduling — immutable per-transition journal. Per Spec D §5.1.
 *
 * Every state change (public or system-mirrored) writes one row here so the
 * lifecycle is auditable and event-sourced regardless of whether an
 * AppointmentTransitionService call or a Mirror* listener performed the
 * write. context JSONB carries reason strings, overrides, or "sourced from
 * WorkOrder <id>" metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_appointment_status_transitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('appointment_id')
                ->constrained('scheduling_appointments')
                ->cascadeOnDelete();

            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('reason_code', 32)->nullable();

            $table->foreignUuid('triggered_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampTz('triggered_at')->useCurrent();
            $table->json('context')->nullable();

            $table->index(['appointment_id', 'triggered_at'], 'idx_sast_appt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_appointment_status_transitions');
    }
};
