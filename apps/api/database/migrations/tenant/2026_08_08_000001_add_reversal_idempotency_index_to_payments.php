<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DPA V4 (plan D-7 / T3) — DB-level idempotency for payment REVERSAL.
 *
 * A UNIQUE PARTIAL index on (company_id, original_payment_id)
 *   WHERE payment_type = 'reversal' AND original_payment_id IS NOT NULL
 *
 * Why this shape, and why it differs from `payments_refund_idempotency_uniq`
 * (2026_05_03_000004): the refund index is request-id-scoped because a payment
 * may legitimately be refunded MANY times. A payment may be reversed EXACTLY
 * ONCE — `PaymentStatus::canReverse()` accepts only `Completed` and the reversal
 * stamps the original `Reversed`. Dropping the request-id column therefore makes
 * this index STRICTLY STRONGER: it holds even for a caller that supplies no
 * idempotency key, which is what turns `PaymentRefundService::reversePayment()`'s
 * `UniqueConstraintViolationException` → read-back-and-return path into a real
 * safety net rather than decoration. (The in-transaction `lockForUpdate()` on the
 * original payment row is the belt; this index is the suspenders.)
 *
 * Why partial: `NULL IS NOT DISTINCT FROM NULL` in a unique index, so a full
 * unique index on (company_id, original_payment_id) would reject every second
 * ordinary payment (they all carry NULL `original_payment_id`).
 *
 * Unattended-safety (MEMORY: a push to origin/dev auto-runs `tenants:migrate`
 * against every tenant DB):
 *   - driver-guarded, no-op on anything but pgsql/sqlite;
 *   - `IF NOT EXISTS` on BOTH arms — deliberately safer than the precedent at
 *     2026_05_03_000004, whose pgsql arm uses a bare CREATE UNIQUE INDEX;
 *   - NO backfill and NO pre-flight data check needed: the value 'reversal' did
 *     not exist in `PaymentType` before this change, so the index predicate
 *     matches ZERO existing rows in every tenant DB. A plain (non-CONCURRENTLY)
 *     CREATE INDEX over an empty predicate is instant.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS payments_reversal_idempotency_uniq '.
            'ON payments (company_id, original_payment_id) '.
            "WHERE payment_type = 'reversal' AND original_payment_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS payments_reversal_idempotency_uniq');
        }
    }
};
