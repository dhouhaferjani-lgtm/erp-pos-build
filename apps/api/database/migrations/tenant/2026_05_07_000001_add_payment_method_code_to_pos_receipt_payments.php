<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Codex review B2: bind payment-instrument identity into the v3 fiscal hash.
 *
 * Adds `payment_method_code` to `pos_receipt_payments` as an immutable snapshot
 * of `payment_methods.code` taken at receipt-creation time.
 *
 * Why a snapshot (and not a live join):
 *   The v3 canonical hash is supposed to bind the receipt's full state at finalize
 *   time. Joining `payment_methods.code` live would let a rename of a payment method
 *   retroactively change the canonical input shape — even though we don't currently
 *   rename codes, the door must be closed for tamper-evidence purposes.
 *
 * Backfill:
 *   For any pre-existing rows we copy `payment_methods.code` via UPDATE..FROM on
 *   PostgreSQL or a correlated subquery on SQLite. There is no production data on
 *   this branch (Phase 1 has not shipped); the backfill exists for synthetic seed
 *   data and integration-test artefacts that pre-date this column.
 *
 * NOT NULL:
 *   Once the backfill completes, the column is altered to NOT NULL. PostgreSQL
 *   uses a raw ALTER; SQLite (test driver) uses Schema::table()->change() which
 *   L12 supports natively without doctrine/dbal.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add the column nullable so we can backfill before tightening to NOT NULL.
        Schema::table('pos_receipt_payments', function (Blueprint $table) {
            $table->string('payment_method_code', 64)->nullable()->after('payment_type');
        });

        // 2. Backfill from payment_methods.code via the FK.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                UPDATE pos_receipt_payments AS prp
                   SET payment_method_code = pm.code
                  FROM payment_methods AS pm
                 WHERE prp.payment_method_id = pm.id
                   AND prp.payment_method_code IS NULL
            SQL);
        } else {
            // SQLite + others: UPDATE..FROM is pgsql-specific, use a correlated subquery.
            DB::statement(<<<'SQL'
                UPDATE pos_receipt_payments
                   SET payment_method_code = (
                           SELECT pm.code
                             FROM payment_methods AS pm
                            WHERE pm.id = pos_receipt_payments.payment_method_id
                       )
                 WHERE payment_method_code IS NULL
            SQL);
        }

        // 3. Tighten to NOT NULL now that every row carries a snapshot.
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE pos_receipt_payments ALTER COLUMN payment_method_code SET NOT NULL');
            DB::statement(
                'COMMENT ON COLUMN pos_receipt_payments.payment_method_code IS '
                ."'Immutable snapshot of payment_methods.code at receipt-creation time. "
                .'Bound into the v3 canonical fiscal hash so a rename of '
                ."payment_methods.code cannot retroactively change a sealed receipt''s canonical input.'"
            );
        } else {
            Schema::table('pos_receipt_payments', function (Blueprint $table) {
                $table->string('payment_method_code', 64)->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('pos_receipt_payments', function (Blueprint $table) {
            $table->dropColumn('payment_method_code');
        });
    }
};
