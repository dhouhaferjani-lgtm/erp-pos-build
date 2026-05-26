<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sequence table for `scheduling_appointments.appointment_number` — mirrors
     * the `workshop_work_order_sequences` pattern from Plan B.
     *
     * One row per `(tenant_id, company_id, year)` carrying a monotonic
     * `last_number`. Pessimistic `FOR UPDATE` lock in
     * `EloquentAppointmentSequence::next()` (Task 10) guarantees gap-free
     * numbering under concurrent creation. Format: `APT-YYYY-NNNNNN`.
     */
    public function up(): void
    {
        Schema::create('scheduling_appointment_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->integer('year');
            $table->integer('last_number')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id', 'year'], 'uq_sas_tenant_company_year');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE scheduling_appointment_sequences ADD CONSTRAINT chk_sas_last_number_nonneg CHECK (last_number >= 0)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduling_appointment_sequences');
    }
};
