<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3-refund-chain-integration spec §6.3 — per-terminal backfill-completion
 * signal for `pos_receipts.sealed_hash_algorithm`'s NULL-handling gate.
 *
 * §6.3 fixes the CONTRACT ("before backfill: NULL means legacy_pipe_v1;
 * after backfill: NULL is a genuine anomaly") but explicitly delegates the
 * exact storage shape to the code phase ("a single row/setting, or a
 * `terminals.sealed_hash_algorithm_backfill_completed_at` timestamp —
 * implementation detail for the code phase"). This migration implements
 * that delegated choice: a nullable timestamp on `pos_terminals`, set by
 * `BackfillSealedHashAlgorithmCommand` once it has processed every legacy
 * (`fiscal_event_id IS NULL`) row for that terminal.
 *
 * Not itself enumerated in the spec's §17 frozen manifest migration list —
 * §6.3's own text authorizes this as a code-phase implementation decision,
 * not a design decision this migration is re-litigating.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_terminals') || Schema::hasColumn('pos_terminals', 'sealed_hash_algorithm_backfill_completed_at')) {
            return;
        }

        Schema::table('pos_terminals', function (Blueprint $table): void {
            $table->timestamp('sealed_hash_algorithm_backfill_completed_at')->nullable()->after('fiscal_schema_version');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_terminals') || ! Schema::hasColumn('pos_terminals', 'sealed_hash_algorithm_backfill_completed_at')) {
            return;
        }

        Schema::table('pos_terminals', function (Blueprint $table): void {
            $table->dropColumn('sealed_hash_algorithm_backfill_completed_at');
        });
    }
};
