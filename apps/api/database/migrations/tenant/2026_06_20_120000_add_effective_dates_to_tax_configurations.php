<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restore effective-dating to tax configurations.
 *
 * The legacy `stamp_duty_rules` table carried effective_from/effective_to so a
 * rate change over time (e.g. the Tunisian invoice timbre 0.600 → 1.000, or the
 * 2026 grandes-surfaces tiers) could be expressed without mutating history and a
 * back-dated document could resolve the rate in force on its own date. The
 * `tax_configurations` engine that replaced it dropped those columns; this
 * re-adds them so the rate table is time-versioned and auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_configurations', function (Blueprint $table): void {
            $table->date('effective_from')->nullable()->after('is_active');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->index(['country_code', 'is_active', 'effective_from', 'effective_to'], 'tax_configurations_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tax_configurations', function (Blueprint $table): void {
            $table->dropIndex('tax_configurations_effective_idx');
            $table->dropColumn(['effective_from', 'effective_to']);
        });
    }
};
