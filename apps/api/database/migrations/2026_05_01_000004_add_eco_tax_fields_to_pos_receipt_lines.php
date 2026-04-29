<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 9 — Phase 2 eco-tax forward compatibility.
 *
 * Adds eco_tax_amount, eco_tax_rate, eco_tax_category to pos_receipt_lines.
 * All three columns are nullable with no defaults.
 *
 * Phase 1 ships columns + fillable only — the writer integration and tax engine
 * wiring are deferred to Phase 2.  Every row written in Phase 1 will have null
 * eco-tax values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipt_lines', static function (Blueprint $table): void {
            // Eco-contribution amount (e.g. WEEE levy per unit × quantity)
            $table->decimal('eco_tax_amount', 20, 5)->nullable()->after('discount_reason');

            // Eco-tax rate as a decimal fraction (e.g. 0.00500 for 0.5%)
            $table->decimal('eco_tax_rate', 8, 4)->nullable()->after('eco_tax_amount');

            // Eco-tax category code (e.g. 'WEEE', 'PACKAGING', 'BATTERY')
            $table->string('eco_tax_category', 64)->nullable()->after('eco_tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipt_lines', static function (Blueprint $table): void {
            $table->dropColumn(['eco_tax_amount', 'eco_tax_rate', 'eco_tax_category']);
        });
    }
};
