<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0.1.3: Create locations table.
 *
 * Location = Physical place (shop, warehouse, office, mobile).
 * Each company can have multiple locations. Stock is tracked per location.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            // Primary key
            $table->uuid('id')->primary();
            $table->uuid('company_id');

            // Basic Info
            $table->string('name', 100);
            $table->string('code', 20)->nullable();
            $table->string('type', 20); // 'shop', 'warehouse', 'office', 'mobile'

            // Contact
            $table->string('phone', 30)->nullable();
            $table->string('email', 255)->nullable();

            // Address (can differ from company)
            $table->string('address_street', 255)->nullable();
            $table->string('address_city', 100)->nullable();
            $table->string('address_postal_code', 20)->nullable();
            $table->char('address_country', 2)->nullable();

            // Geo (for mobile/delivery)
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            // Settings
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            // For shops: POS settings
            $table->boolean('pos_enabled')->default(false);
            $table->text('receipt_header')->nullable();
            $table->text('receipt_footer')->nullable();

            // Timestamps
            $table->timestamps();

            // Foreign keys
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');

            // Indexes
            $table->index('company_id', 'idx_locations_company');
            $table->index('type', 'idx_locations_type');
        });

        // Unique constraint with partial index for nullable code
        DB::statement('CREATE UNIQUE INDEX idx_locations_company_code ON locations (company_id, code) WHERE code IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
