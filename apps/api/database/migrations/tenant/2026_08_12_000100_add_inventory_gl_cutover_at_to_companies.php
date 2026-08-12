<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company COGS-at-exit cutover watermark. Existing companies receive the
 * deploy instant; companies provisioned later receive their creation instant
 * from the database default. Detectors ignore movements below this boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'inventory_gl_cutover_at')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->timestampTz('inventory_gl_cutover_at')
                    ->useCurrent()
                    ->after('inventory_valuation_mode');
            });
        }

        DB::table('companies')->whereNull('inventory_gl_cutover_at')->update([
            'inventory_gl_cutover_at' => now()->utc(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'inventory_gl_cutover_at')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn('inventory_gl_cutover_at');
            });
        }
    }
};
