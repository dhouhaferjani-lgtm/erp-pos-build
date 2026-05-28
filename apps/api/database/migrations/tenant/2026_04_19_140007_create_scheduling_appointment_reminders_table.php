<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduling — Appointment Reminders (Task 14).
 *
 * Per-channel idempotent reminder queue. The unique
 * (appointment_id, channel, scheduled_for) index is the *real* idempotency
 * guarantee — the reminder-scheduling service uses an upsert so calling
 * `scheduleFor($appointment)` twice never produces two rows for the same
 * channel + slot.
 *
 * `delivery_status` is a free-form status string:
 *   pending  — row created; not yet dispatched.
 *   sent     — queueable job finished successfully (sent_at set).
 *   failed   — transport error; sent_at null, error captured in external_id.
 *
 * Status values are wrapped in a PostgreSQL CHECK constraint so invalid
 * values cannot land in the column (mirrors the rest of the module's
 * enum-as-string-with-check pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_appointment_reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('appointment_id')->constrained('scheduling_appointments')->cascadeOnDelete();

            $table->string('channel', 16); // 'email' | 'sms'
            $table->timestampTz('scheduled_for');

            $table->string('delivery_status', 16)->default('pending');
            $table->timestampTz('sent_at')->nullable();
            $table->string('external_id', 191)->nullable();

            $table->timestampsTz();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE scheduling_appointment_reminders ADD CONSTRAINT chk_sar_channel CHECK '.
                "(channel IN ('email','sms'))"
            );
            DB::statement(
                'ALTER TABLE scheduling_appointment_reminders ADD CONSTRAINT chk_sar_status CHECK '.
                "(delivery_status IN ('pending','sent','failed','skipped'))"
            );
            DB::statement(
                'CREATE UNIQUE INDEX uq_sar_appt_channel_scheduled ON scheduling_appointment_reminders '.
                '(appointment_id, channel, scheduled_for)'
            );
            DB::statement(
                'CREATE INDEX idx_sar_pending_due ON scheduling_appointment_reminders (scheduled_for) '.
                "WHERE delivery_status = 'pending'"
            );
        } else {
            Schema::table('scheduling_appointment_reminders', function (Blueprint $table): void {
                $table->unique(['appointment_id', 'channel', 'scheduled_for'], 'uq_sar_appt_channel_scheduled');
                $table->index('scheduled_for', 'idx_sar_pending_due');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_appointment_reminders');
    }
};
