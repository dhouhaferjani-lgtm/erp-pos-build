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
        Schema::create('workshop_service_bundle_vehicle_applicabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('bundle_id')->constrained('workshop_service_bundles')->cascadeOnDelete();

            // UUID to match automotive_product_vehicles.platform_vehicle_id (not BIGINT).
            $table->uuid('platform_vehicle_id')->nullable();
            $table->string('vehicle_type', 16)->nullable();
            $table->string('vehicle_display', 200)->nullable();
            $table->integer('year_from')->nullable();
            $table->integer('year_to')->nullable();

            $table->timestamps();

            $table->index('bundle_id', 'idx_wsbva_bundle');
            $table->index(['platform_vehicle_id', 'vehicle_type'], 'idx_wsbva_vehicle');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE workshop_service_bundle_vehicle_applicabilities ADD CONSTRAINT chk_wsbva_vehicle_type CHECK (vehicle_type IS NULL OR vehicle_type IN ('pc','cv','mtb','eng','axl','universal'))");
            DB::statement('ALTER TABLE workshop_service_bundle_vehicle_applicabilities ADD CONSTRAINT chk_wsbva_universal_or_pair CHECK (
                (platform_vehicle_id IS NULL AND vehicle_type IS NULL)
                OR (platform_vehicle_id IS NOT NULL AND vehicle_type IS NOT NULL)
            )');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_service_bundle_vehicle_applicabilities');
    }
};
