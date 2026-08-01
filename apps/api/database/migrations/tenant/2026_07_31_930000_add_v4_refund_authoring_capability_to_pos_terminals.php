<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3-refund-chain-integration spec §9.3 — two-phase enable/acknowledge
 * capability flag.
 *
 * `v4_refund_authoring_enabled` (Phase 1 — server offers): operator action
 * flips this server-side; does NOT yet activate `LegacyCorrectionGuard` for
 * the terminal.
 *
 * `v4_refund_authoring_acknowledged_at` (Phase 2 — device acknowledges): set
 * when the device's next successful pull round-trips an explicit
 * acknowledgement signal. `LegacyCorrectionGuard` fires only when this is
 * NOT NULL — never on the raw enabled flag alone (§9.3's stated trade-off:
 * a flagged-but-unacknowledged terminal keeps using the legacy endpoint).
 *
 * Both default to the "off"/unacknowledged state so every existing terminal
 * (and every terminal created before Phase 1 is ever triggered for its
 * tenant) is unaffected by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_terminals') || Schema::hasColumn('pos_terminals', 'v4_refund_authoring_enabled')) {
            return;
        }

        Schema::table('pos_terminals', function (Blueprint $table): void {
            $table->boolean('v4_refund_authoring_enabled')->default(false)->after('fiscal_schema_version');
            $table->timestamp('v4_refund_authoring_acknowledged_at')->nullable()->after('v4_refund_authoring_enabled');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_terminals') || ! Schema::hasColumn('pos_terminals', 'v4_refund_authoring_enabled')) {
            return;
        }

        Schema::table('pos_terminals', function (Blueprint $table): void {
            $table->dropColumn(['v4_refund_authoring_enabled', 'v4_refund_authoring_acknowledged_at']);
        });
    }
};
