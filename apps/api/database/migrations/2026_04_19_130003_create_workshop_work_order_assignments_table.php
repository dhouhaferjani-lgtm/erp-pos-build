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
     * Creates `workshop_work_order_assignments`. Partial unique index
     * enforces exactly one active lead technician per WO (per Spec §5.1).
     */
    public function up(): void
    {
        Schema::create('workshop_work_order_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('work_order_id')->constrained('workshop_work_orders')->cascadeOnDelete();
            $table->foreignUuid('technician_profile_id')
                ->constrained('workshop_technician_profiles')
                ->restrictOnDelete();
            $table->boolean('is_lead')->default(false);
            $table->timestampTz('assigned_at')->useCurrent();
            $table->timestampTz('unassigned_at')->nullable();
            $table->foreignUuid('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();

            $table->index('technician_profile_id', 'idx_wwoa_tech');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX uq_wwoa_active_lead ON workshop_work_order_assignments '.
                '(work_order_id) WHERE is_lead AND unassigned_at IS NULL'
            );
        } else {
            // Non-Postgres fallback: best-effort unique via composite; true
            // partial-unique semantics only hold on Postgres.
            Schema::table('workshop_work_order_assignments', function (Blueprint $table): void {
                $table->unique(['work_order_id', 'is_lead', 'unassigned_at'], 'uq_wwoa_active_lead');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workshop_work_order_assignments');
    }
};
