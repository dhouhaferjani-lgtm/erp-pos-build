<?php

declare(strict_types=1);

use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB backstop for the voucher void edge (Session B lane Q-5, sweep findings #22/#24).
 *
 * A voucher can be voided at most once, so it can carry at most ONE `voided`
 * row in the append-only `voucher_ledger`. `VoucherVoidService` enforces that
 * under a `FOR UPDATE` row lock; this partial unique index is the backstop for
 * anything that races or bypasses the service.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * MIGRATION-BEARING — PRE-FLIGHT DUPLICATE CENSUS REQUIRED BEFORE DEPLOY
 * ─────────────────────────────────────────────────────────────────────────────
 * Existing rows CAN violate this index. Two shipped code paths produced a second
 * `voided` row on the same voucher:
 *
 *   1. `VoucherLookupService::autoVoidVoucher()` ran for ANY non-active voucher
 *      status — including one already `Voided`. Every fifth failed scan of an
 *      already-voided voucher appended another `voided` row (reproduced as a
 *      red test in `VoucherLookupServiceTest`:
 *      test_5_failed_attempts_on_already_voided_voucher_does_not_append_a_second_voided_row).
 *   2. `VoucherController::void()` checked the terminal state OUTSIDE its
 *      transaction with no row lock, so two concurrent voids could both pass.
 *
 * Run this census on EVERY tenant database before promoting. Any row returned
 * aborts `tenants:migrate` for that tenant (this migration throws, matching
 * 2026_08_11_000100_unique_journal_entries_source_inventory_movement.php):
 *
 *   SELECT voucher_id, COUNT(*) AS voided_rows
 *   FROM voucher_ledger
 *   WHERE event = 'voided'
 *   GROUP BY voucher_id
 *   HAVING COUNT(*) > 1;
 *
 * Fleet-wide sweep (from the central DB host, per tenant_<uuid> database):
 *
 *   for db in $(psql -At -c "SELECT 'tenant_'||id FROM tenants"); do
 *     echo "== $db"; psql -d "$db" -At -c "SELECT voucher_id, COUNT(*) FROM voucher_ledger
 *       WHERE event='voided' GROUP BY voucher_id HAVING COUNT(*)>1";
 *   done
 *
 * REMEDIATION NOTE: `voucher_ledger` carries the
 * `enforce_voucher_ledger_immutability` trigger, which rejects UPDATE and
 * DELETE. Duplicates therefore CANNOT simply be deleted — remediation is an
 * accounting decision (drop-trigger + surgical delete of the un-posted
 * duplicate, or ship this index only to tenants that come back clean). Do not
 * deploy this migration until the census returns empty on every tenant.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CENSUS 2 — THE #22 MONEY HOLE (accounting remediation, NOT a migration gate)
 * ─────────────────────────────────────────────────────────────────────────────
 * Separate from the duplicate census above, and it does NOT abort this
 * migration: the partial unique index is indifferent to it. It measures the
 * liability that `VoucherController::void()` extinguished with
 * `'gl_journal_entry_id' => null` hardcoded (sweep finding #22, HIGH) — every
 * back-office manual void ever performed zeroed `vouchers.current_balance`
 * while posting NO journal entry, so the voucher liability account still
 * carries the balance and the P&L never took the release.
 *
 * Run per tenant database, alongside census 1, before the promotion that
 * carries this migration (treasury lens r1, correction F-2):
 *
 *   SELECT COUNT(*), SUM(ABS(amount)) FROM voucher_ledger WHERE event='voided' AND gl_journal_entry_id IS NULL AND amount <> 0;
 *
 * Fleet-wide sweep (from the central DB host, per tenant_<uuid> database):
 *
 *   for db in $(psql -At -c "SELECT 'tenant_'||id FROM tenants"); do
 *     echo "== $db"; psql -d "$db" -At -c "SELECT COUNT(*), SUM(ABS(amount))
 *       FROM voucher_ledger WHERE event='voided' AND gl_journal_entry_id IS NULL
 *       AND amount <> 0";
 *   done
 *
 * Note `amount <> 0` is load-bearing: a zero-balance void legitimately carries
 * no GL entry (there is nothing to reverse), on this lane's code path as on the
 * two pre-existing ones. Only non-zero rows are holes.
 *
 * REMEDIATION IS FORWARD-ONLY AND UNREPAIRABLE IN PLACE. The same
 * `enforce_voucher_ledger_immutability` trigger rejects UPDATE, so a historical
 * row's NULL `gl_journal_entry_id` can NEVER be backfilled — not by this
 * migration, not by a data script. The only remediation is a forward CORRECTING
 * JOURNAL ENTRY per tenant for the summed amount this census returns, dated in
 * an open period, reversing the stranded voucher liability. Hand the census
 * output to the accounting owner with the promotion checklist; do not attempt a
 * silent fix.
 */
return new class extends Migration
{
    private const INDEX = 'uniq_voucher_ledger_voided_per_voucher';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $event = VoucherEvent::Voided->value;

        $duplicates = DB::select(
            "SELECT voucher_id, COUNT(*) AS duplicate_count
             FROM voucher_ledger
             WHERE event = '{$event}'
             GROUP BY voucher_id
             HAVING COUNT(*) > 1"
        );

        if ($duplicates !== []) {
            $first = $duplicates[0];
            throw new RuntimeException(sprintf(
                'Cannot create %s: voucher %s already carries %s "%s" ledger rows. '
                .'Run the duplicate census in this migration docblock across the fleet '
                .'and remediate before deploying.',
                self::INDEX,
                (string) $first->voucher_id,
                (string) $first->duplicate_count,
                $event,
            ));
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX
            ." ON voucher_ledger (voucher_id) WHERE event = '{$event}'"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
