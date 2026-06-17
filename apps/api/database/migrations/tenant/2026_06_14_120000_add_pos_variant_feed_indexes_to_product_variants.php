<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Covering indexes for the offline-POS variant feed (GET /pos/variants).
 *
 * The feed runs a company-scoped delta/snapshot query bounded by updated_at,
 * plus a soft-delete tombstone query bounded by deleted_at. The existing
 * (tenant_id, company_id, is_active) index does not cover the updated_at
 * range, and there is no index on deleted_at. These composites make both
 * feed queries index-only ranges.
 *
 * SQLite-safe (plain composite indexes — no PARTIAL predicate, no constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', static function (Blueprint $table): void {
            $table->index(
                ['tenant_id', 'company_id', 'is_active', 'updated_at'],
                'pv_feed_active_updated_idx',
            );
            $table->index(
                ['tenant_id', 'company_id', 'deleted_at'],
                'pv_feed_deleted_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', static function (Blueprint $table): void {
            $table->dropIndex('pv_feed_active_updated_idx');
            $table->dropIndex('pv_feed_deleted_idx');
        });
    }
};
