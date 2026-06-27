<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C1 — add reverses_movement_id self-FK + double-reverse partial unique guard.
 *
 * Adds a nullable uuid column `reverses_movement_id` to stock_movements.
 * When populated it points to the movement that this row reverses/corrects.
 *
 * DB-level guards:
 *  - Self-FK ON DELETE SET NULL (nullOnDelete): if the original movement is
 *    ever deleted, the pointer is nulled rather than raising a FK violation.
 *  - Partial unique index WHERE reverses_movement_id IS NOT NULL: ensures a
 *    given original movement can be reversed at most once.
 *
 * SQLite / test notes:
 *  - The partial unique index is also created on SQLite (for test migrations to
 *    run cleanly), but constraint enforcement in `:memory:` SQLite is not
 *    guaranteed across driver versions — production protection lives in PG.
 *  - The self-FK is PG-only (SQLite does not enforce FKs by default, so we skip
 *    the ALTER TABLE CONSTRAINT statement for that driver).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->uuid('reverses_movement_id')->nullable()->after('is_historical');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            // SQLite (tests): create the partial unique index without CONCURRENTLY.
            DB::statement(
                'CREATE UNIQUE INDEX stock_movements_reverses_movement_id_unique
                    ON stock_movements (reverses_movement_id)
                    WHERE reverses_movement_id IS NOT NULL'
            );

            return;
        }

        // PostgreSQL: add self-FK with nullOnDelete + partial unique guard.
        DB::statement(
            'ALTER TABLE stock_movements
                ADD CONSTRAINT stock_movements_reverses_movement_id_foreign
                FOREIGN KEY (reverses_movement_id)
                REFERENCES stock_movements (id)
                ON DELETE SET NULL
                NOT VALID'
        );

        DB::statement(
            'CREATE UNIQUE INDEX CONCURRENTLY stock_movements_reverses_movement_id_unique
                ON stock_movements (reverses_movement_id)
                WHERE reverses_movement_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_movements_reverses_movement_id_unique');
            DB::statement(
                'ALTER TABLE stock_movements
                    DROP CONSTRAINT IF EXISTS stock_movements_reverses_movement_id_foreign'
            );
        } else {
            DB::statement('DROP INDEX IF EXISTS stock_movements_reverses_movement_id_unique');
        }

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropColumn('reverses_movement_id');
        });
    }
};
