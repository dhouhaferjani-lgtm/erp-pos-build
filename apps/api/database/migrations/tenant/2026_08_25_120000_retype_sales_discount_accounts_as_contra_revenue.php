<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * W4-9 treasury gate I-3 — re-type the seeded `sales_discount` account
 * (`709x Rabais, remises et ristournes accordés`) from `expense` to `revenue`.
 *
 * WHY. It is CONTRA-REVENUE: a debit-balance account that sits inside the
 * revenue class. `ProfitLossService` partitions strictly on `accounts.type`
 * (revenue = `credit − debit`, expenses = `debit − credit`), so while it was
 * typed `expense` the statement read *chiffre d'affaires 600 / charges 100*
 * where the PCG-TN presentation is *CA net de RRR 500*. Net income was correct
 * either way and the trial balance always closed — it is the FACE of the P&L
 * that was wrong, which is exactly the kind of defect nobody notices until an
 * accountant does.
 *
 * WHY NOW. W4-9 made it material for the POS channel. Before it, a discounted
 * POS sale credited the post-discount tender straight to `707` and posted no
 * `709x` line at all; from W4-9 on, every discounted receipt books one. What
 * used to appear only on the rare `createPOSChargeEntry` (ACCOUNT_CHARGE) arm
 * now appears on ordinary counter sales.
 *
 * SCOPE — `sales_discount` ONLY. `SalesReturn` (`709` proper) and
 * `SalesReturnsClearing` (`7091`) are likewise seeded `expense`; they are
 * pre-existing chart decisions with their own consumers and their own
 * presentation question, and re-typing them is a separate call that this lane
 * has no evidence for. Matching on the SYSTEM PURPOSE rather than on a code
 * keeps this migration from touching them even where the codes collide across
 * charts (the generic chart puts `sales_discount` on `7091`, which is
 * `sales_returns_clearing` on the FR/TN charts — a code-keyed migration would
 * have re-typed the wrong account on half the fleet).
 *
 * MIGRATION-BEARING. Per-tenant census query, to run BEFORE and AFTER the
 * deploy (`tenants:run` loop, or per tenant database):
 *
 *     SELECT id, code, name, type
 *     FROM accounts
 *     WHERE system_purpose = 'sales_discount';
 *
 * Expected: every row `type = 'expense'` before, `type = 'revenue'` after; the
 * row COUNT must not change (this migration only ever UPDATEs). On the wave-4
 * tenant that is exactly one row (`7097`). A tenant with zero rows is a chart
 * that never carried the purpose — the backfill command's job, not this one's.
 *
 * FLEET-ABORT RISK: none. No DDL, no constraint, no unique index — a single
 * idempotent `UPDATE` behind three guards, wrapped so a failure on one tenant
 * is logged and skipped rather than aborting the fleet run. Nothing about the
 * `accounts` row's identity, code, purpose or balance changes, and no posted
 * journal line references `accounts.type`, so no existing entry moves.
 *
 * SELF-GUARDING AND IDEMPOTENT, as unattended `tenants:migrate` demands:
 *  - a database with no `accounts` table (central, or a tenant migrated before
 *    accounting existed) returns early;
 *  - the `WHERE type = 'expense'` predicate makes a second run a no-op;
 *  - a chart that never carried the purpose matches nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->retype(AccountType::Expense, AccountType::Revenue);
    }

    /**
     * Reversible: put the rows back exactly as they were. Only rows that still
     * carry the purpose AND are still `revenue` are touched, so a chart an
     * operator has since re-typed by hand is left alone.
     */
    public function down(): void
    {
        $this->retype(AccountType::Revenue, AccountType::Expense);
    }

    private function retype(AccountType $from, AccountType $to): void
    {
        if (! Schema::hasTable('accounts') || ! Schema::hasColumn('accounts', 'system_purpose')) {
            return;
        }

        try {
            $updated = DB::table('accounts')
                ->where('system_purpose', SystemAccountPurpose::SalesDiscount->value)
                ->where('type', $from->value)
                ->update(['type' => $to->value, 'updated_at' => now()]);
        } catch (Throwable $e) {
            // Log-never-throw: one tenant's chart must not abort the fleet run.
            Log::warning('retype_sales_discount_accounts: skipped a tenant database', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($updated > 0) {
            Log::info('retype_sales_discount_accounts: re-typed contra-revenue accounts', [
                'from' => $from->value,
                'to' => $to->value,
                'rows' => $updated,
            ]);
        }
    }
};
