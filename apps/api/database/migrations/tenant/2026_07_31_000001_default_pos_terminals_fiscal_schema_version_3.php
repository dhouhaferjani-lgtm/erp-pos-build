<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provision-at-v3 (first-tenant launch, Lane D1): flip the DEFAULT on
 * `pos_terminals.fiscal_schema_version` from 2 to 3.
 *
 * A DEFAULT only affects INSERTs that omit the column — it never rewrites
 * existing rows. Self-guarding by construction: no `UPDATE` statement here,
 * so terminals created before this migration (schema 2) are untouched, and
 * every terminal inserted from this point on (across every tenant this
 * auto-runs against on promotion) defaults to schema 3 even if a future
 * caller forgets to set the column explicitly. Of `TerminalController`'s
 * three creation paths, the two DEVICE paths (`store`, `requestTerminal`)
 * ALSO set `fiscal_schema_version => 3` explicitly (spec §"Lane D1" task 2b)
 * so the API contract is visible in code; the web terminal path
 * (`getOrCreateWebTerminal`) is the deliberate exception and pins an explicit
 * 2 (round-2 fiscal-pos review fix — web terminals are server-authoritative,
 * no device exists to author SESSION_OPEN/SESSION_CLOSE). This migration is
 * the belt-and-braces DB-level guarantee for the two device paths, not a
 * claim that every terminal in the system is v3.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A tenant database that somehow lacks this column (e.g. an
        // out-of-order/partial migration history) would otherwise abort
        // `Schema::table(...)->change()` with a missing-column error — and
        // because `tenants:migrate` runs this across every tenant database in
        // one batch, ONE such tenant would abort the WHOLE promotion batch.
        // Skip rather than fail; a tenant missing the column entirely has a
        // bigger problem than this migration can fix.
        if (! Schema::hasColumn('pos_terminals', 'fiscal_schema_version')) {
            return;
        }

        Schema::table('pos_terminals', function (Blueprint $table): void {
            $table->smallInteger('fiscal_schema_version')->default(3)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pos_terminals', 'fiscal_schema_version')) {
            return;
        }

        // Reverts the COLUMN DEFAULT only — exactly mirrors up()'s contract.
        // Terminals already created at schema 3 (under the up() default, or
        // explicitly by TerminalController's device paths) stay at 3; this
        // does NOT rewrite them back to 2. Only the default applied to FUTURE
        // inserts made after a rollback changes.
        Schema::table('pos_terminals', function (Blueprint $table): void {
            $table->smallInteger('fiscal_schema_version')->default(2)->change();
        });
    }
};
