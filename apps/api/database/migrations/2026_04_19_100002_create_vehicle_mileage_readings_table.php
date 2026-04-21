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
        Schema::create('vehicle_mileage_readings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained()->cascadeOnDelete();
            $table->integer('mileage');
            $table->timestampTz('recorded_at');
            $table->string('source', 32);
            $table->foreignUuid('context_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->uuid('context_work_order_id')->nullable(); // FK added in Spec B migration
            $table->foreignUuid('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['vehicle_id', 'recorded_at'], 'idx_vmr_vehicle_time');
        });

        // PostgreSQL-specific check constraint (SQLite doesn't enforce named CHECKs portably).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vehicle_mileage_readings ADD CONSTRAINT chk_mileage_non_negative CHECK (mileage >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_mileage_readings');
    }
};
