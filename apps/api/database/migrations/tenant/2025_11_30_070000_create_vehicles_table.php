<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->string('license_plate', 20);
            $table->string('brand', 100);
            $table->string('model', 100);
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color', 50)->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->string('vin', 17)->nullable();
            $table->string('engine_code', 50)->nullable();
            $table->string('fuel_type', 30)->nullable();
            $table->string('transmission', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Unique license plate per tenant (excluding soft-deleted)
            $table->unique(['tenant_id', 'license_plate']);

            // Unique VIN per tenant (excluding soft-deleted) - VIN is globally unique but we scope to tenant
            $table->unique(['tenant_id', 'vin']);

            // Index for partner queries
            $table->index(['tenant_id', 'partner_id']);

            // Index for search
            $table->index(['tenant_id', 'brand']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
