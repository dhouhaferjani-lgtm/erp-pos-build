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
     * credit. The redeem side never got the equivalent backstop. This adds the
     * mirror-image key + index.
     *
     * Scope, stated honestly (treasury gate r1, F-1/F-3): no redeem row has
     * ever been written in production — the service 500'd at insert on every
     * call (created_at NOT NULL, no default) — so this index is not cleaning up
     * after a live double-spend and has nothing to reconcile. It is also
     * CAPABILITY-ONLY today: nothing sends a `redemption_key`, so every existing
     * and near-future row has NULL here and falls outside the partial predicate.
     * The index starts protecting real traffic only once a wiring lane lifts the
     * 501 gate on `LoyaltyPOSController::redeem` AND makes the client send a key.
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

    /**
     * Set when up() actually created the column, so down() can be the exact
     * inverse of what up() did (treasury gate r1, F-6).
     */
    private bool $createdColumn = false;

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
            $this->createdColumn = true;
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX
            ." ON loyalty_transactions (enrollment_id, redemption_key)
               WHERE transaction_type = 'redeem' AND redemption_key IS NOT NULL"
        );
    }

    /**
     * Inverse of up(), and ONLY of up() (treasury gate r1, F-6).
     *
     * The index is always dropped: this migration is the only thing that
     * creates it. The COLUMN is dropped only when this same process created it
     * — i.e. the first-run path above. Two reasons it is not dropped
     * unconditionally:
     *
     *  - up()'s census path deliberately does NOT create the column (it found
     *    one already there). Dropping it on the way back down would destroy a
     *    column this migration never owned, along with whatever keys it holds.
     *  - A real `migrate:rollback` runs down() on a FRESH instance, so
     *    `$createdColumn` is false there and the column survives. That is the
     *    intended outcome: an additive nullable column with no backfill is inert
     *    for every reader that does not know about it, whereas dropping it is
     *    irreversible. The flag therefore only fires for a same-process
     *    up()/down() round trip (migration tests, `migrate:refresh` in one run).
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

        if ($this->createdColumn && Schema::hasColumn('loyalty_transactions', 'redemption_key')) {
            Schema::table('loyalty_transactions', function (Blueprint $table): void {
                $table->dropColumn('redemption_key');
            });
            $this->createdColumn = false;
        }
    }
};
