<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduling — Bays (aggregate root of the bay-as-resource calendar).
 *
 * Per Spec D §5.1. One bay row per physical lift / ramp / flat-ground slot.
 * `operating_hours` is a JSONB weekly-schedule shape; casts in the Eloquent
 * model decode it. Partial unique index enforces one active `(company_id,
 * location_id, code)` without blocking soft-deletes (PG only; fallback to a
 * plain unique on SQLite in tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_bays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name', 100);
            $table->string('bay_type', 24);
            $table->integer('display_order')->default(0);

            $table->json('operating_hours');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['location_id', 'is_active'], 'idx_sb_location_active');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX uq_sb_tenant_company_location_code ON scheduling_bays '.
                '(tenant_id, company_id, location_id, code) WHERE deleted_at IS NULL'
            );
            DB::statement(
                'ALTER TABLE scheduling_bays ADD CONSTRAINT chk_sb_bay_type CHECK '.
                "(bay_type IN ('general','quick_service','alignment','heavy','specialist','flat','other'))"
            );
        } else {
            Schema::table('scheduling_bays', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'company_id', 'location_id', 'code'], 'uq_sb_tenant_company_location_code');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_bays');
    }
};
