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
     * Adds discount control settings to POS terminals for Phase 1 of discount system.
     * These settings determine terminal-level discount limits and permissions.
     */
    public function up(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            // Discount Control Settings
            $table->decimal('max_discount_percent', 5, 2)->default(0.00)
                ->after('pos_software_version')
                ->comment('Maximum allowed discount percentage for this terminal (0-100)');

            $table->boolean('allow_line_discounts')->default(true)
                ->after('max_discount_percent')
                ->comment('Allow line-level discounts on individual items');

            $table->boolean('allow_transaction_discounts')->default(true)
                ->after('allow_line_discounts')
                ->comment('Allow transaction-level discounts on entire receipt');
        });

        // PostgreSQL-specific check constraint (only on PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pos_terminals ADD CONSTRAINT pos_terminals_discount_percent_range CHECK (max_discount_percent BETWEEN 0 AND 100)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop constraint first (PostgreSQL only)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pos_terminals DROP CONSTRAINT IF EXISTS pos_terminals_discount_percent_range');
        }

        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->dropColumn([
                'max_discount_percent',
                'allow_line_discounts',
                'allow_transaction_discounts',
            ]);
        });
    }
};
