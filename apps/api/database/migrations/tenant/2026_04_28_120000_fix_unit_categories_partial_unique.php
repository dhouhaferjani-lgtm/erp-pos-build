<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M4 — Fix `unit_categories` partial-unique-index gap.
 *
 * Original constraint `unique(['tenant_id', 'code'])` is broken on
 * PostgreSQL because PG treats each NULL as distinct in a unique index,
 * meaning system-level rows (`tenant_id IS NULL`) can have duplicate
 * codes. This migration:
 *
 *   1. Deletes duplicate `(NULL, code)` system rows, keeping the
 *      earliest `created_at` per code.
 *   2. Drops the existing combined unique index.
 *   3. Creates two partial unique indexes:
 *        - `WHERE tenant_id IS NULL`     (system rows)
 *        - `WHERE tenant_id IS NOT NULL` (tenant-scoped rows)
 *
 * The two-index split is a PostgreSQL idiom for "unique within
 * partition," and is safe across re-runs of the UoM seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Delete duplicate system rows, keeping the earliest by created_at.
        //
        // Use a window function to rank rows within each (NULL tenant_id, code)
        // group and delete everything but rank=1.
        DB::statement(<<<'SQL'
            DELETE FROM unit_categories
            WHERE id IN (
                SELECT id
                FROM (
                    SELECT
                        id,
                        ROW_NUMBER() OVER (
                            PARTITION BY code
                            ORDER BY created_at ASC, id ASC
                        ) AS rn
                    FROM unit_categories
                    WHERE tenant_id IS NULL
                ) ranked
                WHERE ranked.rn > 1
            )
        SQL);

        // 2. Drop the combined unique index that misbehaves on NULL tenant_id.
        //
        // Laravel's default name is `<table>_<col1>_<col2>_unique` →
        // `unit_categories_tenant_id_code_unique`.
        Schema::table('unit_categories', function ($table): void {
            $table->dropUnique(['tenant_id', 'code']);
        });

        // 3. Create two partial unique indexes — one per partition.
        //
        // PostgreSQL partial unique indexes correctly enforce uniqueness
        // for the rows matching their predicate. NULL tenant_id rows get
        // their own index where each (NULL, code) pair is unique;
        // non-NULL tenant_id rows get the standard composite uniqueness.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX unit_categories_system_code_unique
            ON unit_categories (code)
            WHERE tenant_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX unit_categories_tenant_code_unique
            ON unit_categories (tenant_id, code)
            WHERE tenant_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // Drop the two partial unique indexes.
        DB::statement('DROP INDEX IF EXISTS unit_categories_system_code_unique');
        DB::statement('DROP INDEX IF EXISTS unit_categories_tenant_code_unique');

        // Recreate the original (broken) combined unique index so the
        // schema matches the prior migration when rolling back.
        Schema::table('unit_categories', function ($table): void {
            $table->unique(['tenant_id', 'code']);
        });
    }
};
