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
     * Tracks approved/pending time off per technician. Availability service
     * intersects requested windows with this table.
     */
    public function up(): void
    {
        Schema::create('workshop_technician_time_off', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('technician_profile_id')
                ->constrained('workshop_technician_profiles')
                ->cascadeOnDelete();

            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason_code', 32);
            $table->boolean('is_full_day')->default(true);
            $table->boolean('is_approved')->default(false);
            $table->foreignUuid('approved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->index(
                ['technician_profile_id', 'starts_at', 'ends_at'],
                'idx_wtto_tech_range'
            );
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE workshop_technician_time_off ADD CONSTRAINT chk_time_off_range '.
                'CHECK (ends_at > starts_at)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workshop_technician_time_off');
    }
};
