<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash-rounding Phase 1 / migration A1.
     *
     * ONE cash-ness predicate for every layer (spec §4.1): a tender leg is
     * cash iff its payment method carries `is_cash_tender = true`. The
     * invariant `is_cash_tender = true => code = 'CASH'` (EXACT,
     * case-sensitive) is enforced on write by PaymentMethodController; this
     * migration establishes it for existing rows by normalizing the code at
     * the same time it sets the flag.
     *
     * Self-guarding: the table may not exist on a partially-provisioned
     * tenant DB, and the column may already exist on a re-run.
     */
    public function up(): void
    {
        if (! Schema::hasTable('payment_methods')) {
            return;
        }

        if (! Schema::hasColumn('payment_methods', 'is_cash_tender')) {
            Schema::table('payment_methods', function (Blueprint $table): void {
                $table->boolean('is_cash_tender')
                    ->default(false)
                    ->after('is_physical');
            });
        }

        // Backfill + code normalization in one statement so no row can end up
        // flagged with a non-canonical code.
        //
        // Collision-safe: PostgreSQL compares the unique index case-sensitively,
        // so a company may legitimately hold BOTH 'CASH' and 'cash' today.
        // Rewriting the variant to 'CASH' would violate the index and abort the
        // whole `tenants:migrate` run (which auto-deploy executes unattended).
        // Such a row is left alone and logged for manual reconciliation instead
        // — it stays is_cash_tender = false, which is fail-closed.
        //
        // The collision scope is COMPANY, not tenant: the original
        // unique(['tenant_id','code']) from 2025_11_30_120000 was dropped and
        // replaced with unique(['company_id','code']) by
        // 2025_12_30_195300_fix_multi_company_unique_constraints, precisely so
        // sibling companies in one tenant can reuse codes. Correlating on
        // tenant_id here would skip a second company's 'cash' row that has no
        // collision at all, leaving that company with no cash-flagged method.
        //
        // Exactly ONE winner is elected per company:
        //  - the canonical 'CASH' row always wins if the company has one;
        //  - otherwise the lowest-id case-variant wins. Without that second
        //    guard a company holding 'cash' AND 'Cash' but NO 'CASH' would see
        //    both rows pass the canonical check and both rewrite to 'CASH',
        //    which fires the unique index and aborts the migration.
        // Every loser is logged below and left untouched.
        $skipped = DB::select(
            <<<'SQL'
                SELECT pm.id, pm.tenant_id, pm.company_id, pm.code,
                       CASE WHEN EXISTS (
                           SELECT 1 FROM payment_methods c
                           WHERE c.company_id = pm.company_id AND c.code = 'CASH'
                       ) THEN 1 ELSE 0 END AS canonical_exists
                FROM payment_methods pm
                WHERE UPPER(pm.code) = 'CASH'
                  AND pm.code <> 'CASH'
                  AND (
                      EXISTS (
                          SELECT 1 FROM payment_methods x
                          WHERE x.company_id = pm.company_id AND x.code = 'CASH'
                      )
                      OR EXISTS (
                          SELECT 1 FROM payment_methods y
                          WHERE y.company_id = pm.company_id
                            AND UPPER(y.code) = 'CASH'
                            AND y.code <> 'CASH'
                            AND y.id < pm.id
                      )
                  )
                SQL
        );

        foreach ($skipped as $row) {
            Log::warning('cash_rounding.backfill.skipped_ambiguous_cash_code', [
                'migration' => '2026_07_28_100000_add_is_cash_tender_to_payment_methods',
                'payment_method_id' => $row->id,
                'tenant_id' => $row->tenant_id,
                'company_id' => $row->company_id,
                'code' => $row->code,
                'reason' => ((int) $row->canonical_exists === 1
                    ? "company already holds a canonical 'CASH' payment method"
                    : "company holds several 'CASH' case-variants and no canonical row; "
                        .'a lower-id variant was elected instead')
                    .'; normalizing this row would violate unique(company_id, code). '
                    .'Left unflagged — reconcile manually.',
            ]);
        }

        // The target-row predicate is expressed as `id IN (SELECT …)` rather
        // than an aliased UPDATE target. SQLite accepts `UPDATE payment_methods
        // AS pm SET …` but rejects the AS-less `UPDATE payment_methods pm SET …`;
        // the subquery form sidesteps the distinction entirely and reads the
        // same on both drivers.
        DB::statement(
            <<<'SQL'
                UPDATE payment_methods
                SET is_cash_tender = true, code = 'CASH'
                WHERE id IN (
                    SELECT pm.id FROM payment_methods pm
                    WHERE UPPER(pm.code) = 'CASH'
                      AND (
                          pm.code = 'CASH'
                          OR (
                              NOT EXISTS (
                                  SELECT 1 FROM payment_methods x
                                  WHERE x.company_id = pm.company_id AND x.code = 'CASH'
                              )
                              AND NOT EXISTS (
                                  SELECT 1 FROM payment_methods y
                                  WHERE y.company_id = pm.company_id
                                    AND UPPER(y.code) = 'CASH'
                                    AND y.code <> 'CASH'
                                    AND y.id < pm.id
                              )
                          )
                      )
                )
                SQL
        );

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'COMMENT ON COLUMN payment_methods.is_cash_tender IS '.
                "'Canonical cash-ness predicate for POS rounding/tolerance. TRUE implies code = ''CASH'' exactly.'"
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_methods') || ! Schema::hasColumn('payment_methods', 'is_cash_tender')) {
            return;
        }

        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropColumn('is_cash_tender');
        });
    }
};
