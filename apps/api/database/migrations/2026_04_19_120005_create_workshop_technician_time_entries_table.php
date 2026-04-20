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
     * Records technician clock-in/out entries. Populated by WorkOrder events or manual.
     * `work_order_id` is a plain nullable UUID column — no FK, the work_order row
     * may not exist yet. Plan B schema is independent of Plan C.
     */
    public function up(): void
    {
        Schema::create('workshop_technician_time_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('technician_profile_id')
                ->constrained('workshop_technician_profiles')
                ->cascadeOnDelete();

            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->integer('duration_minutes')->nullable();

            $table->string('entry_type', 16);
            $table->uuid('work_order_id')->nullable();

            $table->string('source', 16);
            $table->foreignUuid('recorded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->index(['technician_profile_id', 'started_at'], 'idx_wtte_tech_range');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX idx_wtte_wo ON workshop_technician_time_entries(work_order_id) '.
                'WHERE work_order_id IS NOT NULL'
            );
            DB::statement(
                'CREATE UNIQUE INDEX uq_wtte_open ON workshop_technician_time_entries '.
                '(technician_profile_id) WHERE ended_at IS NULL'
            );
            DB::statement(
                'ALTER TABLE workshop_technician_time_entries ADD CONSTRAINT chk_time_entry_end_after_start '.
                'CHECK (ended_at IS NULL OR ended_at >= started_at)'
            );
        } else {
            Schema::table('workshop_technician_time_entries', function (Blueprint $table): void {
                $table->index('work_order_id', 'idx_wtte_wo');
                // SQLite does not support partial indexes; open-entry uniqueness is enforced in domain code for non-pgsql.
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workshop_technician_time_entries');
    }
};
