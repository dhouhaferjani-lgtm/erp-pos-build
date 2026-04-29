<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: re-type legacy negative payments from 'document_payment'
 * to 'refund'.
 *
 * ## Why this backfill exists
 *
 * Until Task 19 of the refund-flow implementation (2026-05-03), the
 * PaymentRefundService methods (`refundPayment`, `partialRefund`) did not
 * set `payment_type` explicitly. The column defaulted to `'document_payment'`,
 * so every negative (refund) payment row created before Task 19 carries the
 * wrong type. Codex review 2 flagged this as an additional finding (§4.3
 * of the refund-flow design spec, 2026-04-28-pos-refund-flow-design.md).
 *
 * ## Predicate rationale — why each WHERE clause condition is required
 *
 *   1. payment_type = 'document_payment'
 *      Only rows that still carry the incorrect default are candidates.
 *      Rows already correctly typed (advance, refund, supplier_payment, …)
 *      must not be touched.
 *
 *   2. amount < 0
 *      Legitimate `document_payment` rows always have a positive amount
 *      (money received from the customer). A negative amount on a
 *      `document_payment` row is the fingerprint of the bug: the service
 *      inserted a refund but forgot to set the type. This predicate keeps
 *      false positives to zero for any positive-amount rows.
 *
 *   3. EXISTS (SELECT 1 FROM payment_allocations pa
 *              JOIN documents d ON d.id = pa.document_id
 *              WHERE pa.payment_id = payments.id
 *              AND d.type = 'credit_note')
 *      The legacy refund path always created a negative PaymentAllocation
 *      pointing at the source document. For a credit-note-based refund that
 *      document is of type 'credit_note'. This predicate tightens the match:
 *      a negative payment that is NOT linked to a credit note document is
 *      suspicious in a different way (data integrity issue) and must NOT be
 *      re-typed without human review. We leave those untouched.
 *
 * ## Idempotency
 *
 * The predicate already includes `payment_type = 'document_payment'`, so
 * re-running the migration after rows have been updated to 'refund' will
 * match zero rows and produce no changes.
 *
 * ## Performance
 *
 * The `payments` table has an index on `payment_type` (added by
 * 2025_12_06_100002_add_payment_type_to_payments.php). The `amount < 0`
 * filter further narrows the row set. `payment_allocations.payment_id` has
 * a foreign-key index. The EXISTS sub-select is index-efficient on both sides.
 * For large tables the statement will lock rows briefly; run during a low-
 * traffic window or wrap in a chunked loop if the table is very large.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            UPDATE payments
            SET payment_type = 'refund'
            WHERE payment_type = 'document_payment'
              AND CAST(amount AS NUMERIC) < 0
              AND EXISTS (
                  SELECT 1
                  FROM payment_allocations pa
                  JOIN documents d ON d.id = pa.document_id
                  WHERE pa.payment_id = payments.id
                    AND d.type = 'credit_note'
              )
            SQL
        );
    }

    public function down(): void
    {
        // Intentionally not reversible: re-typing back to 'document_payment' would
        // re-introduce the bug. If you need to rollback, restore from a backup or
        // manually inspect the affected rows using the same WHERE clause with the
        // type condition inverted (payment_type = 'refund').
    }
};
