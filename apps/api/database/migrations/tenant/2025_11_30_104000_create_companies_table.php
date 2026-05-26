<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0.1.2: Create companies table.
 *
 * Company = Legal entity with tax_id, country_code, compliance profile.
 * A tenant (account holder) can have multiple companies.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            // Primary key
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // Basic Info
            $table->string('name', 255);
            $table->string('legal_name', 255)->nullable();
            $table->string('code', 20)->nullable();

            // COUNTRY (determines legal/fiscal system) - CRITICAL!
            $table->char('country_code', 2);

            // Legal/Tax Info (validated per country rules)
            $table->string('tax_id', 50)->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->string('vat_number', 50)->nullable();
            $table->jsonb('legal_identifiers')->default('{}');

            // Contact
            $table->string('email', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website', 255)->nullable();

            // Address
            $table->string('address_street', 255)->nullable();
            $table->string('address_street_2', 255)->nullable();
            $table->string('address_city', 100)->nullable();
            $table->string('address_state', 100)->nullable();
            $table->string('address_postal_code', 20)->nullable();

            // Branding
            $table->string('logo_path', 500)->nullable();
            $table->string('primary_color', 7)->default('#2563EB');

            // Regional Settings (derived from country, can override)
            $table->char('currency', 3);
            $table->string('locale', 10);
            $table->string('timezone', 50);
            $table->string('date_format', 20)->default('DD/MM/YYYY');

            // Fiscal Settings
            $table->smallInteger('fiscal_year_start_month')->default(1);

            // Document Sequences
            $table->string('invoice_prefix', 20)->default('FAC-');
            $table->integer('invoice_next_number')->default(1);
            $table->string('quote_prefix', 20)->default('DEV-');
            $table->integer('quote_next_number')->default(1);
            $table->string('sales_order_prefix', 20)->default('BC-');
            $table->integer('sales_order_next_number')->default(1);
            $table->string('purchase_order_prefix', 20)->default('CF-');
            $table->integer('purchase_order_next_number')->default(1);
            $table->string('delivery_note_prefix', 20)->default('BL-');
            $table->integer('delivery_note_next_number')->default(1);
            $table->string('receipt_prefix', 20)->default('REC-');
            $table->integer('receipt_next_number')->default(1);

            // Verification (per company, because requirements differ by country)
            $table->string('verification_tier', 20)->default('basic');
            $table->string('verification_status', 20)->default('pending');
            $table->timestamp('verification_submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->uuid('verified_by')->nullable();
            $table->text('verification_notes')->nullable();

            // Compliance profile
            $table->string('compliance_profile', 50)->nullable();

            // Hierarchy (for chains/franchises)
            $table->uuid('parent_company_id')->nullable();
            $table->boolean('is_headquarters')->default(false);

            // Status
            $table->string('status', 20)->default('active');
            $table->timestamp('closed_at')->nullable();

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Foreign key to tenant (can be defined in same create)

            // Indexes
            $table->index('tenant_id', 'idx_companies_tenant');
            $table->index('country_code', 'idx_companies_country');
            $table->index('status', 'idx_companies_status');
        });

        // Self-referencing FK must be added after table exists
        Schema::table('companies', function (Blueprint $table) {
            $table->foreign('parent_company_id')->references('id')->on('companies')->onDelete('set null');
        });

        // Unique constraints with partial index for nullable columns
        // PostgreSQL requires special handling for nullable unique constraints
        DB::statement('CREATE UNIQUE INDEX idx_companies_tenant_tax_id ON companies (tenant_id, tax_id) WHERE tax_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX idx_companies_tenant_code ON companies (tenant_id, code) WHERE code IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
