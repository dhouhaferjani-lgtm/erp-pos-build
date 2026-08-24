<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Session B lane Q-10 (b)+(c): per-period transition audit columns on `fiscal_periods`.
 *
 * WHY
 * ---
 * `FiscalPeriodAutoLockService` moved fiscal periods Open -> Closed -> Locked with
 * three blind bulk `->update(['status' => ...])` calls: no actor, no from-state, no
 * per-row timestamp — only an aggregate `Log::info` count for the whole fleet-night.
 * The table already carried `closed_at` / `closed_by` (a USER fk, so it cannot record
 * the nightly scheduler as an actor), and nothing at all for the Locked edge or for
 * the new permissioned reopen path.
 *
 * WHAT
 * ----
 * Seven nullable, additive columns. Nothing is backfilled, nothing is made NOT NULL,
 * no unique index and no CHECK is added, and no existing column changes type.
 *
 *   locked_at            when the period was moved to Locked
 *   locked_by            USER who locked it (null for the nightly system lock)
 *   reopened_at          when the period was moved Closed -> Open again
 *   reopened_by          USER who reopened it (the reopen path is permissioned, so
 *                        this is always populated on that edge)
 *   reopen_reason        operator-supplied justification for the reopen (required by
 *                        ReopenFiscalPeriodRequest, so never null once reopened)
 *   status_actor         actor label for the LAST status transition — 'system:auto-lock'
 *                        for the scheduler, 'user:<uuid>' for a human action. This is the
 *                        column `closed_by`/`locked_by` cannot be, because they are user FKs.
 *   status_changed_from  the from-state of the LAST transition ('open' | 'closed' | 'locked')
 *
 * The last two are the "light idiom" deliberately chosen over an append-only
 * `fiscal_period_status_transitions` table: the full PeriodStatus machine (adjacency map,
 * transitions table, CHECK constraint) is program scope, not this lane's. They record the
 * LAST transition only; a period cycled Closed -> Open -> Closed overwrites them.
 *
 * WHY THIS CANNOT FAIL ON EXISTING ROWS
 * -------------------------------------
 * Every column is added nullable with no default and no backfill, so every pre-existing
 * `fiscal_periods` row satisfies it trivially — an `ALTER TABLE ... ADD COLUMN <t> NULL`
 * is a catalog-only operation on PostgreSQL 11+ and cannot reject a row. The two FKs
 * (`locked_by`, `reopened_by` -> `users.id`) are added on columns that are NULL for every
 * existing row, and a NULL FK value is never checked, so the constraint is satisfied
 * fleet-wide by construction — there is no pre-flight census to run and no per-tenant
 * abort path. `users` is created long before this migration in the tenant stack
 * (2025_11_30_* vs 2026_08_24_*), matching the existing `closed_by` FK on the same table.
 *
 * The FK clauses are pgsql-guarded: SQLite (the default unit-test connection) cannot add
 * a foreign key through ALTER TABLE. The columns themselves are created on both drivers,
 * so behaviour and assertions are identical; only the referential constraint is PG-only,
 * exactly as the tenant stack already treats PG-only DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_periods', function (Blueprint $table): void {
            $table->timestamp('locked_at')->nullable()->after('closed_by');
            $table->uuid('locked_by')->nullable()->after('locked_at');
            $table->timestamp('reopened_at')->nullable()->after('locked_by');
            $table->uuid('reopened_by')->nullable()->after('reopened_at');
            $table->string('reopen_reason', 500)->nullable()->after('reopened_by');
            $table->string('status_actor', 64)->nullable()->after('reopen_reason');
            $table->string('status_changed_from', 20)->nullable()->after('status_actor');
        });

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('fiscal_periods', function (Blueprint $table): void {
                $table->foreign('locked_by')->references('id')->on('users')->onDelete('set null');
                $table->foreign('reopened_by')->references('id')->on('users')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('fiscal_periods', function (Blueprint $table): void {
                $table->dropForeign(['locked_by']);
                $table->dropForeign(['reopened_by']);
            });
        }

        Schema::table('fiscal_periods', function (Blueprint $table): void {
            $table->dropColumn([
                'locked_at',
                'locked_by',
                'reopened_at',
                'reopened_by',
                'reopen_reason',
                'status_actor',
                'status_changed_from',
            ]);
        });
    }
};
