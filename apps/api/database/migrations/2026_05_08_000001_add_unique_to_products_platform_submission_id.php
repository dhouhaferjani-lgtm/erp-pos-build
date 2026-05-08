<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * api.platform-integration cluster — Finding A closure.
 *
 * Adds a DB UNIQUE constraint on `products.platform_submission_id` so
 * cross-tenant collisions on the platform's submission id are
 * structurally impossible. Pairs with the
 * ProcessEnrichmentEventListener `->first()` → `->sole()` defense-in-
 * depth change at
 * apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php.
 *
 * The platform-side `barcode_submissions.id` is a UUID PK
 * (apps/platform/database/migrations/2026_03_26_000003_create_barcode_submissions_table.php:14),
 * so cross-tenant collision is mathematically negligible — this is
 * defense-in-depth, not closure of a live exploit. The pre-migration
 * COUNT check below fails loud if any duplicates exist (which would
 * indicate a data-integrity emergency, not a normal migration: legacy
 * data, manual corrections, restore conflicts, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pre-migration safety check: fail LOUD if duplicates exist.
        // The constraint cannot be applied with violating rows; rather
        // than letting the DB engine error out cryptically mid-migration,
        // surface a clear triage message naming exactly what to do.
        $duplicates = DB::selectOne(
            'SELECT COUNT(*) - COUNT(DISTINCT platform_submission_id) AS dup
             FROM products
             WHERE platform_submission_id IS NOT NULL'
        );
        $dup = (int) ($duplicates->dup ?? 0);

        if ($dup !== 0) {
            throw new \RuntimeException(
                "Cannot apply UNIQUE constraint to products.platform_submission_id: "
                .$dup." duplicate value(s) exist. "
                ."Triage required: identify whether these are legitimate test/dev duplicates, "
                ."real cross-tenant collisions, or legacy data, and remediate before re-running this migration. "
                ."Query to inspect: SELECT platform_submission_id, COUNT(*) FROM products WHERE platform_submission_id IS NOT NULL GROUP BY 1 HAVING COUNT(*) > 1;"
            );
        }

        Schema::table('products', function (Blueprint $table) {
            $table->unique('platform_submission_id', 'products_platform_submission_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_platform_submission_id_unique');
        });
    }
};
