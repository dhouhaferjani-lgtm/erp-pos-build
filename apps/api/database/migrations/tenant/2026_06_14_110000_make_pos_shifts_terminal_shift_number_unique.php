<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offline-first shifts Phase 2 (Codex F-7).
     *
     * `shift_number` is device-authored (per-terminal monotone, minted in the
     * SESSION_OPEN fiscal event). The original `(terminal_id, shift_number)`
     * plain index only sped lookups; this upgrades it to UNIQUE so a rogue
     * second device minting a colliding number fails loud rather than silently
     * projecting a duplicate. The column already exists and is already
     * populated server-side, so there is no column add and no data backfill —
     * pre-live, there are no duplicates to reconcile.
     */
    public function up(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table) {
            // Drop the plain index created in 2026_01_08_190641 and replace it
            // with a UNIQUE one over the same columns.
            $table->dropIndex(['terminal_id', 'shift_number']);
            $table->unique(['terminal_id', 'shift_number'], 'pos_shifts_terminal_shift_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->dropUnique('pos_shifts_terminal_shift_number_unique');
            $table->index(['terminal_id', 'shift_number']);
        });
    }
};
