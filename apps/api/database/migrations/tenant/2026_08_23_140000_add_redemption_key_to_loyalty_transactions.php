<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Session B lane Q-3 — redemption idempotency.
     *
     * The earn side was hardened in July with `loyalty_txn_earn_source_unique`
     * (2026_07_06_100000_add_source_columns_to_loyalty_transactions.php:89-93)
     * because two queued earn paths could race the same receipt into a double
     * credit. The redeem side never got the equivalent backstop: a cashier
     * double-tapping "Redeem reward", or a POS retry after a timeout, wrote two
     * `redeem` rows against one debit. This adds the mirror-image key + index.
     *
     * MIGRATION-BEARING — pre-flight analysis
     * ---------------------------------------
     * The index is `(enrollment_id, redemption_key) WHERE transaction_type =
     * 'redeem' AND redemption_key IS NOT NULL`. `redemption_key` is introduced
     * BY THIS MIGRATION as a nullable column with no backfill, so on a first
     * run every pre-existing row — redeem or not — carries NULL and is excluded
     * from the partial predicate by construction. The index therefore cannot
     * fail on existing data, and no backfill/dedupe step of the kind the earn
     * migration needed (it backfilled from `metadata`) applies here.
     *
     * That guarantee only holds on a FIRST run. A re-run against a tenant where
     * the column already exists (a partially applied migration, a restored
     * dump, a hand-patched database) could meet real keys, so the duplicate
     * census below runs whenever the column is already present and aborts with
     * the offending pair rather than letting CREATE UNIQUE INDEX fail
     * mid-fleet. Census query for the operator (run per tenant database):
     *
     *   SELECT enrollment_id, redemption_key, COUNT(*) AS duplicate_count
     *   FROM loyalty_transactions
     *   WHERE transaction_type = 'redeem' AND redemption_key IS NOT NULL
     *   GROUP BY enrollment_id, redemption_key
     *   HAVING COUNT(*) > 1;
     *
     * Engines: phpunit runs on SQLite :memory: (phpunit.xml), production and
     * staging are PG. A partial `CREATE UNIQUE INDEX ... WHERE` is valid on
     * both, so — exactly as the earn migration does — the DDL is shared rather
     * than pgsql-guarded. Nothing here is PG-only.
     */
    private const INDEX = 'loyalty_txn_redeem_key_unique';

    public function up(): void
    {
        if (Schema::hasColumn('loyalty_transactions', 'redemption_key')) {
            $duplicates = DB::select(
                "SELECT enrollment_id, redemption_key, COUNT(*) AS duplicate_count
                 FROM loyalty_transactions
                 WHERE transaction_type = 'redeem' AND redemption_key IS NOT NULL
                 GROUP BY enrollment_id, redemption_key
                 HAVING COUNT(*) > 1"
            );

            if ($duplicates !== []) {
                $first = $duplicates[0];
                throw new RuntimeException(sprintf(
                    'Cannot create %s: duplicate redemption key (enrollment %s, key %s). '
                    .'Resolve the duplicate redeem rows before re-running.',
                    self::INDEX,
                    (string) $first->enrollment_id,
                    (string) $first->redemption_key,
                ));
            }
        } else {
            Schema::table('loyalty_transactions', function (Blueprint $table): void {
                // Client/source-derived idempotency key for the redeem side.
                // Nullable: keyless (legacy / back-office) redemptions stay legal
                // and are excluded from the partial unique index.
                $table->string('redemption_key', 64)->nullable();
            });
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX
            ." ON loyalty_transactions (enrollment_id, redemption_key)
               WHERE transaction_type = 'redeem' AND redemption_key IS NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

        if (Schema::hasColumn('loyalty_transactions', 'redemption_key')) {
            Schema::table('loyalty_transactions', function (Blueprint $table): void {
                $table->dropColumn('redemption_key');
            });
        }
    }
};
