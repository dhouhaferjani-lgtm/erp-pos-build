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
     * Adds cashier-level discount permissions for Phase 1 of discount system.
     * These settings determine individual cashier discount authorization limits.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Cashier Discount Permissions
            $table->boolean('can_discount')->default(false)
                ->after('preferences')
                ->comment('Whether this cashier is authorized to apply discounts');

            $table->decimal('max_discount_percent', 5, 2)->nullable()
                ->after('can_discount')
                ->comment('Maximum discount percentage this cashier can apply (NULL = no individual limit)');
        });

        // PostgreSQL-specific check constraint (only on PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_discount_percent_range CHECK (max_discount_percent IS NULL OR max_discount_percent BETWEEN 0 AND 100)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop constraint first (PostgreSQL only)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_discount_percent_range');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'can_discount',
                'max_discount_percent',
            ]);
        });
    }
};
