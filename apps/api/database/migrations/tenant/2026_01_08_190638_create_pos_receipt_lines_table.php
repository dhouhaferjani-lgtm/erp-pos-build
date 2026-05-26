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
     * Creates pos_receipt_lines table for individual line items on receipts.
     * Tracks products/menu items sold with quantities, prices, and modifications.
     */
    public function up(): void
    {
        Schema::create('pos_receipt_lines', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary();

            // Parent Receipt
            $table->foreignUuid('receipt_id')
                ->constrained('pos_receipts')
                ->cascadeOnDelete();

            // Line Number (display order)
            $table->integer('line_number');

            // Product Reference (current: products table, future: menu_items)
            $table->foreignUuid('product_id')
                ->nullable()
                ->constrained('products')
                ->restrictOnDelete();

            // Product Snapshot (immutable at time of sale)
            $table->string('product_code', 50);
            $table->string('product_name', 200);
            $table->text('product_description')->nullable();

            // Quantity and Pricing
            $table->decimal('quantity', 10, 3);
            $table->string('unit', 20)->default('unit');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);

            // Tax Information
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('tax_amount', 12, 2);

            // Modifiers and Customizations (JSONB for future menu items)
            $table->jsonb('modifiers')->nullable();

            // Discount Information
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('discount_reason', 100)->nullable();

            // Notes
            $table->text('notes')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index('receipt_id');
            $table->index('product_id');
            $table->index(['receipt_id', 'line_number']);
        });

        // PostgreSQL-specific constraints and comments (only on PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Check Constraints
            DB::statement('ALTER TABLE pos_receipt_lines ADD CONSTRAINT pos_receipt_lines_quantity CHECK (quantity > 0)');
            DB::statement('ALTER TABLE pos_receipt_lines ADD CONSTRAINT pos_receipt_lines_positive_amounts CHECK (
                unit_price >= 0 AND
                line_total >= 0 AND
                tax_amount >= 0 AND
                discount_amount >= 0
            )');
            DB::statement('ALTER TABLE pos_receipt_lines ADD CONSTRAINT pos_receipt_lines_line_total_calc CHECK (
                line_total = (unit_price * quantity) - discount_amount
            )');

            // Table Comments
            DB::statement('COMMENT ON TABLE pos_receipt_lines IS \'Individual line items on POS receipts (immutable after receipt creation)\'');
            DB::statement('COMMENT ON COLUMN pos_receipt_lines.modifiers IS \'JSONB: Future menu item modifiers (e.g., size, extras, substitutions)\'');
            DB::statement('COMMENT ON COLUMN pos_receipt_lines.product_code IS \'Immutable snapshot: Product code at time of sale\'');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_receipt_lines');
    }
};
