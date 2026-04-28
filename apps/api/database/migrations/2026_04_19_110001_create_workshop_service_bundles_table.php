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
        Schema::create('workshop_service_bundles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 64);
            $table->string('name', 200);
            $table->text('description')->nullable();

            $table->string('pricing_mode', 16)->default('standard');
            $table->decimal('base_price', 12, 3)->nullable();
            $table->string('currency', 3);

            $table->decimal('tax_rate', 6, 3)->nullable();

            $table->decimal('estimated_labor_hours', 6, 2)->nullable();
            $table->integer('service_interval_km')->nullable();
            $table->integer('service_interval_months')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active'], 'idx_wsb_company_active');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX uq_wsb_company_code ON workshop_service_bundles(tenant_id, company_id, code) WHERE deleted_at IS NULL');
            DB::statement("ALTER TABLE workshop_service_bundles ADD CONSTRAINT chk_wsb_pricing_mode CHECK (pricing_mode IN ('standard','fixed_bundle'))");
            DB::statement('ALTER TABLE workshop_service_bundles ADD CONSTRAINT chk_wsb_fixed_bundle_has_price CHECK (pricing_mode = \'standard\' OR base_price IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_service_bundles');
    }
};
