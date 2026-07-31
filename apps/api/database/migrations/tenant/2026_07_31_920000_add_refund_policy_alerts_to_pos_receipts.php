<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3-refund-chain-integration spec §3.5/§3.6 — server-advisory refund
 * policy accept-and-flag column, modeled on the existing
 * `PosCoreReceiptProjection.php:1051-1071` late-sale-flag precedent.
 *
 * The projector appends entries here (never rejects a signed event on
 * their account) whenever it observes a policy discrepancy it can compute
 * purely from data it already has AFTER a v4 REFUND event is signed —
 * timestamp-only return-window questions (§3.6) and the §3.5 non-zero-
 * original-discount detection flag. Nullable JSONB; NULL means "no alerts
 * raised", not "not yet evaluated" (the projector always writes either NULL
 * or a non-empty array on every v4 refund it applies).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_receipts') || Schema::hasColumn('pos_receipts', 'refund_policy_alerts')) {
            return;
        }

        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->jsonb('refund_policy_alerts')->nullable()->after('sealed_hash_algorithm');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_receipts') || ! Schema::hasColumn('pos_receipts', 'refund_policy_alerts')) {
            return;
        }

        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->dropColumn('refund_policy_alerts');
        });
    }
};
