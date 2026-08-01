<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3-refund-chain-integration spec §6 — per-row seal-discriminator column.
 *
 * `sealed_hash_algorithm` names which hash pipeline actually sealed a given
 * `pos_receipts` row (`legacy_pipe_v1` for the pre-v3 verifier arm; a new
 * value for the v4-refund-chain-aware pipeline once §17's remaining work
 * ships). NULL on every row created before this column existed and on any
 * row not yet backfilled — §6.3's verifier NULL-handling is gated on a
 * separate backfill-completion signal, not on this migration.
 *
 * Nullable, no default: this migration only adds the column. The one-time
 * NULL→value backfill transition is permitted by a SEPARATE trigger-amendment
 * migration (`2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php`)
 * that MUST run after this one (numeric ordering already guarantees this —
 * the trigger references this column).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_receipts') || Schema::hasColumn('pos_receipts', 'sealed_hash_algorithm')) {
            return;
        }

        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->string('sealed_hash_algorithm', 32)->nullable()->after('fiscal_hash');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_receipts') || ! Schema::hasColumn('pos_receipts', 'sealed_hash_algorithm')) {
            return;
        }

        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->dropColumn('sealed_hash_algorithm');
        });
    }
};
