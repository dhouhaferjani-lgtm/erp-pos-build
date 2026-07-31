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
 * caller forgets to set the column explicitly. `TerminalController`'s three
 * creation paths ALSO set `fiscal_schema_version => 3` explicitly (spec
 * §"Lane D1" task 2b) so the API contract is visible in code; this migration
 * is the belt-and-braces DB-level guarantee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table): void {
            $table->smallInteger('fiscal_schema_version')->default(3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table): void {
            $table->smallInteger('fiscal_schema_version')->default(2)->change();
        });
    }
};
