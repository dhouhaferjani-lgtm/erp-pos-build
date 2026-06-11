<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plain index on stock_transfer_lines.transfer_id.
 *
 * The 2026-06-09 variant migration replaced the legacy unique index on
 * transfer_id with two PARTIAL unique indexes (one for null variant_id,
 * one for non-null). A join on transfer_id alone (used by the
 * LocationStockQueryService incoming-transfers query on every page-1 pull)
 * no longer has a usable index. This adds a plain non-unique index.
 *
 * SQLite-safe (plain index — no PARTIAL predicate, no constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfer_lines', static function (Blueprint $table): void {
            $table->index('transfer_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_lines', static function (Blueprint $table): void {
            $table->dropIndex(['transfer_id']);
        });
    }
};
