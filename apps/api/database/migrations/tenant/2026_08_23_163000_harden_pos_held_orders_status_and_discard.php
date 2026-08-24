<?php

declare(strict_types=1);

use App\Modules\POS\Domain\Enums\HeldOrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Q-8 — held-order recall hardening (POS-till sub-report MEDIUM).
 *
 * Two changes to `pos_held_orders`:
 *
 * 1. `deleted_at` + `discarded_by` — `HeldOrderService::discardOrder()` used to
 *    hard-`DELETE` the row in any status, destroying the only server-side trace
 *    of what was parked. It now soft-deletes and records the actor.
 *
 * 2. `pos_held_orders_status_check` — the column was `string(20)` with a
 *    `'held'` default and no constraint at all, while `HeldOrderStatus` has
 *    exactly three cases. The CHECK is generated from the enum so the two can
 *    never drift.
 *
 * ============================== MIGRATION-BEARING ==============================
 * The CHECK is a constraint existing rows can violate, so `up()` performs a
 * pre-flight scan and ABORTS the tenant's `tenants:migrate` with the offending
 * values rather than letting PostgreSQL fail with an opaque
 * `check constraint ... is violated by some row`.
 *
 * PRE-FLIGHT CENSUS — run this per tenant database BEFORE deploying. Zero rows
 * everywhere ⇒ the migration cannot abort:
 *
 *   SELECT status, COUNT(*) AS offending_rows
 *   FROM pos_held_orders
 *   WHERE status IS NULL
 *      OR status NOT IN ('held', 'recalled', 'expired')
 *   GROUP BY status
 *   ORDER BY offending_rows DESC;
 *
 * Fleet sweep (from the central DB host, one line per tenant database):
 *
 *   for db in $(psql -Atc "SELECT datname FROM pg_database WHERE datname LIKE 'tenant_%'"); do
 *     echo -n "$db: ";
 *     psql -d "$db" -Atc "SELECT COALESCE(string_agg(status || '=' || n, ', '), 'clean')
 *       FROM (SELECT COALESCE(status,'<null>') AS status, COUNT(*) AS n
 *             FROM pos_held_orders
 *             WHERE status IS NULL OR status NOT IN ('held','recalled','expired')
 *             GROUP BY 1) s";
 *   done
 *
 * Remediation for a non-clean tenant: a held order is an ephemeral parked cart
 * (default TTL 4 hours) with no fiscal or GL consequence, so an out-of-enum row
 * is safe to normalise to `expired` — it is dead weight either way:
 *
 *   UPDATE pos_held_orders
 *   SET status = 'expired'
 *   WHERE status IS NULL OR status NOT IN ('held','recalled','expired');
 *
 * FLEET-ABORT RISK: LOW. `status` is only ever written from
 * `HeldOrderStatus` (`HeldOrderService.php` hold/recall/expire, plus the
 * `'held'` column default), there is no import path and no external writer, so
 * a violating row can only exist from a manual UPDATE. The abort is per-tenant
 * and happens before any DDL, so a dirty tenant stops on its own database and
 * leaves the rest of the fleet migrated.
 * ==============================================================================
 */
return new class extends Migration
{
    private const CHECK = 'pos_held_orders_status_check';

    /**
     * Re-entrant on purpose: a tenant whose columns landed but whose CHECK
     * aborted on the pre-flight scan must be able to re-run this migration
     * after the operator normalises the offending rows.
     */
    public function up(): void
    {
        Schema::table('pos_held_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('pos_held_orders', 'discarded_by')) {
                $table->uuid('discarded_by')->nullable()->after('recalled_at');
            }
            if (! Schema::hasColumn('pos_held_orders', 'deleted_at')) {
                $table->softDeletes();
                $table->index(['company_id', 'deleted_at'], 'pos_held_orders_company_deleted_idx');
            }
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->assertNoOutOfEnumStatuses();

        DB::statement('ALTER TABLE pos_held_orders DROP CONSTRAINT IF EXISTS '.self::CHECK);
        DB::statement(
            'ALTER TABLE pos_held_orders ADD CONSTRAINT '.self::CHECK
            .' CHECK (status IN ('.$this->quotedStatuses().'))'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pos_held_orders DROP CONSTRAINT IF EXISTS '.self::CHECK);
        }

        Schema::table('pos_held_orders', function (Blueprint $table): void {
            $table->dropIndex('pos_held_orders_company_deleted_idx');
            $table->dropSoftDeletes();
            $table->dropColumn('discarded_by');
        });
    }

    /**
     * Pre-flight scan: abort with the census result rather than let PostgreSQL
     * reject the ALTER with an opaque "violated by some row".
     */
    private function assertNoOutOfEnumStatuses(): void
    {
        $offenders = DB::select(
            'SELECT COALESCE(status, \'<null>\') AS status, COUNT(*) AS offending_rows
             FROM pos_held_orders
             WHERE status IS NULL OR status NOT IN ('.$this->quotedStatuses().')
             GROUP BY 1
             ORDER BY 2 DESC'
        );

        if ($offenders === []) {
            return;
        }

        $summary = implode(', ', array_map(
            static function (object $row): string {
                $values = (array) $row;

                return sprintf('%s=%d', (string) $values['status'], (int) $values['offending_rows']);
            },
            $offenders,
        ));

        throw new RuntimeException(sprintf(
            'Cannot add %s: pos_held_orders holds out-of-enum status values (%s). '
            .'Normalise them (UPDATE pos_held_orders SET status = \'%s\' WHERE status IS NULL OR status NOT IN (%s)) and re-run.',
            self::CHECK,
            $summary,
            HeldOrderStatus::Expired->value,
            $this->quotedStatuses(),
        ));
    }

    private function quotedStatuses(): string
    {
        return implode(', ', array_map(
            static fn (HeldOrderStatus $case): string => "'".str_replace("'", "''", $case->value)."'",
            HeldOrderStatus::cases(),
        ));
    }
};
