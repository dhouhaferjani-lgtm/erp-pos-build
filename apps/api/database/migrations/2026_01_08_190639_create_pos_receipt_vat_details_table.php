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
     * Creates pos_receipt_vat_details table for NF525-compliant VAT breakdown.
     * Each receipt must have detailed VAT breakdown per tax rate for hash chain integrity.
     */
    public function up(): void
    {
        Schema::create('pos_receipt_vat_details', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary();

            // Parent Receipt
            $table->foreignUuid('receipt_id')
                ->constrained('pos_receipts')
                ->cascadeOnDelete();

            // Tax Category (optional classification)
            $table->string('tax_category', 50)->nullable();

            // Tax Rate (NF525 Critical)
            $table->decimal('tax_rate', 5, 2);

            // Amounts (NF525 Critical)
            $table->decimal('net_amount', 12, 2);
            $table->decimal('vat_amount', 12, 2);
            $table->decimal('gross_amount', 12, 2);

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index('receipt_id');
            $table->index(['receipt_id', 'tax_rate']);
        });

        // PostgreSQL-specific constraints and comments (only on PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Check Constraints
            DB::statement('ALTER TABLE pos_receipt_vat_details ADD CONSTRAINT pos_receipt_vat_details_amounts CHECK (
                net_amount >= 0 AND
                vat_amount >= 0 AND
                gross_amount >= 0
            )');
            DB::statement('ALTER TABLE pos_receipt_vat_details ADD CONSTRAINT pos_receipt_vat_details_gross_calc CHECK (
                gross_amount = net_amount + vat_amount
            )');
            DB::statement('ALTER TABLE pos_receipt_vat_details ADD CONSTRAINT pos_receipt_vat_details_vat_calc CHECK (
                vat_amount = round((net_amount * tax_rate / 100)::numeric, 2)
            )');

            // Table Comments
            DB::statement('COMMENT ON TABLE pos_receipt_vat_details IS \'NF525-compliant VAT breakdown per receipt (feeds vat_breakdown_hash)\'');
            DB::statement('COMMENT ON COLUMN pos_receipt_vat_details.tax_rate IS \'VAT rate percentage (e.g., 19.00 for 19% TVA in Tunisia)\'');
            DB::statement('COMMENT ON COLUMN pos_receipt_vat_details.net_amount IS \'Total net amount (HT) for this VAT rate\'');
            DB::statement('COMMENT ON COLUMN pos_receipt_vat_details.vat_amount IS \'Total VAT amount for this rate\'');
            DB::statement('COMMENT ON COLUMN pos_receipt_vat_details.gross_amount IS \'Total gross amount (TTC) = net + VAT\'');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_receipt_vat_details');
    }
};
