<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds four audit columns and one DB-level idempotency index to `payments`.
 *
 * New columns:
 *   - original_payment_id  — FK (soft) to the Payment row that this refund
 *                            was split from. NULL on non-refund rows.
 *   - refund_request_id    — Idempotency key supplied by the caller
 *                            (UUID; matches the RefundDraft / ReceiptReturnService
 *                            request ID). NULL on non-refund rows.
 *   - authorized_by_user_id — Manager/admin who authorised the override when
 *                            a policy threshold was exceeded. NULL when no override
 *                            was required (spec §3.6 / F27).
 *   - policy_trigger       — Free-string code of the policy condition that fired the
 *                            override (e.g. "over_threshold", "out_of_window").
 *                            NULL when no override was required.
 *
 * Idempotency index (PostgreSQL only):
 *   A UNIQUE PARTIAL index on (company_id, original_payment_id, refund_request_id)
 *   WHERE payment_type = 'refund' AND original_payment_id IS NOT NULL
 *         AND refund_request_id IS NOT NULL
 *
 *   Why partial: the uniqueness constraint must only apply to refund rows that
 *   carry both FK columns. Non-refund rows (payment_type != 'refund') and
 *   refund rows without these columns filled must not be constrained, because:
 *     (a) NULL IS NOT DISTINCT FROM NULL in a unique index — if two non-refund rows
 *         both have NULL original_payment_id, a full unique index would reject the
 *         second insert.
 *     (b) Refund rows written by the legacy refundPayment/partialRefund methods
 *         (pre-Task-19) do not carry refund_request_id; they must not conflict.
 *
 *   Concurrent calls with the same (company_id, original_payment_id, refund_request_id)
 *   triplet will race on INSERT; the loser gets a UniqueConstraintViolationException
 *   which PaymentRefundService::refundReceiptPayments() converts to a
 *   RefundIdempotencyException after reading back the existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->uuid('original_payment_id')->nullable()->after('payment_type');
            $table->uuid('refund_request_id')->nullable()->after('original_payment_id');
            $table->uuid('authorized_by_user_id')->nullable()->after('refund_request_id');
            $table->string('policy_trigger', 64)->nullable()->after('authorized_by_user_id');

            // Plain B-tree indexes for FK lookups (non-unique)
            $table->index('original_payment_id', 'payments_original_payment_id_idx');
            $table->index('refund_request_id', 'payments_refund_request_id_idx');
        });

        // Unique partial index — PostgreSQL only (SQLite used in tests understands it too via raw SQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX payments_refund_idempotency_uniq '.
                'ON payments (company_id, original_payment_id, refund_request_id) '.
                "WHERE payment_type = 'refund' ".
                'AND original_payment_id IS NOT NULL '.
                'AND refund_request_id IS NOT NULL'
            );
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite supports partial unique indexes via CREATE UNIQUE INDEX … WHERE
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS payments_refund_idempotency_uniq '.
                'ON payments (company_id, original_payment_id, refund_request_id) '.
                "WHERE payment_type = 'refund' ".
                'AND original_payment_id IS NOT NULL '.
                'AND refund_request_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS payments_refund_idempotency_uniq');
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_original_payment_id_idx');
            $table->dropIndex('payments_refund_request_id_idx');
            $table->dropColumn(['original_payment_id', 'refund_request_id', 'authorized_by_user_id', 'policy_trigger']);
        });
    }
};
