<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_ownership_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('owner_partner_id')->constrained('partners')->restrictOnDelete();
            $table->timestampTz('acquired_at');
            $table->timestampTz('released_at')->nullable();
            $table->string('reason_code', 32);
            $table->text('notes')->nullable();
            $table->foreignUuid('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('owner_partner_id', 'idx_voh_owner');
            $table->index(['vehicle_id', 'acquired_at'], 'idx_voh_vehicle_range');
        });

        // PostgreSQL-specific constraints (not supported by SQLite test DB).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vehicle_ownership_history ADD CONSTRAINT chk_release_after_acquire CHECK (released_at IS NULL OR released_at >= acquired_at)');
            DB::statement('CREATE UNIQUE INDEX uq_vehicle_open_ownership ON vehicle_ownership_history(vehicle_id) WHERE released_at IS NULL');
            DB::statement('CREATE INDEX idx_voh_tenant_owner_open ON vehicle_ownership_history(tenant_id, owner_partner_id) WHERE released_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_ownership_history');
    }
};
