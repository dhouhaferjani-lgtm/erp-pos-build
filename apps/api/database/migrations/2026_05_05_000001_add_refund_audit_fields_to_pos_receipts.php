<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 27 — Phase E
 *
 * Adds return-receipt audit columns to pos_receipts:
 *   - authorized_by_user_id  UUID nullable — manager who approved the override
 *   - override_reason        string(255) nullable — human-readable reason text
 *   - out_of_window          bool nullable — TRUE when the return window had already expired
 *   - policy_trigger         string(64) nullable — machine-readable trigger key (e.g. "over_threshold")
 *   - refund_request_id      UUID nullable — client-supplied idempotency key
 *
 * DB-level idempotency: a unique partial index on (company_id, refund_request_id)
 * WHERE refund_request_id IS NOT NULL guarantees that concurrent callers carrying
 * the same refund_request_id produce exactly ONE return receipt row.
 *
 * The index is PostgreSQL-specific syntax; SQLite (used in testing) skips the
 * WHERE clause and uses a plain unique index on (company_id, refund_request_id)
 * with an explicit NULL workaround via the nullable column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->uuid('authorized_by_user_id')->nullable()->after('notes');
            $table->string('override_reason', 255)->nullable()->after('authorized_by_user_id');
            $table->boolean('out_of_window')->nullable()->after('override_reason');
            $table->string('policy_trigger', 64)->nullable()->after('out_of_window');
            $table->uuid('refund_request_id')->nullable()->after('policy_trigger');

            // Non-unique plain index for quick lookup by refund_request_id
            $table->index(['company_id', 'refund_request_id'], 'pos_receipts_refund_request_lookup');
        });

        // Unique partial index: only enforce uniqueness when refund_request_id IS NOT NULL.
        // This allows many rows with NULL refund_request_id (normal sale receipts).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX pos_receipts_company_refund_request_unique '.
                'ON pos_receipts (company_id, refund_request_id) '.
                'WHERE refund_request_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'DROP INDEX IF EXISTS pos_receipts_company_refund_request_unique'
            );
        }

        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->dropIndex('pos_receipts_refund_request_lookup');
            $table->dropColumn([
                'authorized_by_user_id',
                'override_reason',
                'out_of_window',
                'policy_trigger',
                'refund_request_id',
            ]);
        });
    }
};
