<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Session D final review, finding I-1 — BROWNFIELD CENSUS. Mutates NOTHING.
 *
 * # What it looks for
 *
 * `payment_methods.is_cash_tender` is THE cash-ness predicate: the shared
 * repository rule, `TreasuryReceiptBridge`, the device checkout resolver and —
 * since I-1 — `ShiftExpectedCashService` and the device Z aggregation all read
 * that one column. The write path
 * (`PaymentMethodController::assertCashTenderInvariant()`) now enforces
 * coherence in BOTH directions. Rows already in a tenant DB predate that guard,
 * and three shapes make a row read differently by different consumers:
 *
 *   A. `code = 'CASH'` (exact) with `is_cash_tender = false` — the shape the
 *      one-way guard allowed straight through both `store()` and `update()`.
 *      Read as CASH by every `UPPER(code) = 'CASH'` consumer and as NON-cash by
 *      every flag reader.
 *   B. a mixed-case cash-family code (`UPPER(code) = 'CASH'` but `code <> 'CASH'`)
 *      with `is_cash_tender = false` — the collision losers
 *      `2026_07_28_100000_add_is_cash_tender_to_payment_methods` deliberately
 *      left alone because normalizing them would have violated
 *      `unique(company_id, code)` and aborted an unattended `tenants:migrate`.
 *      Same split as (A).
 *   C. `is_cash_tender = true` on a code that is NOT exactly `CASH` — the
 *      direction the old guard did block on write, censused because a seeder, a
 *      console command or a hand-run UPDATE never passed through that guard.
 *
 * # Why it does not fix them
 *
 * Every remedy is a JUDGEMENT about money that has already moved. Flagging (A)
 * retroactively reclassifies its historical receipts as cash for any figure
 * derived after the change; renaming (B) invalidates the `payment_method_code`
 * snapshot on every receipt payment that already names the old code, and the
 * device caches payment methods by code, so a rename needs a forced resync
 * before the next sale. Neither belongs in an unattended fleet migrate that runs
 * on every deploy (see the auto-deploy rule in CLAUDE.md). So this migration
 * REPORTS, names the row, and prints the statement the operator would run.
 *
 * SELF-GUARDING: no-ops when `payment_methods` or the `is_cash_tender` column is
 * absent (a partially provisioned tenant, or one whose A1 migration has not run
 * yet), and says so out loud. IDEMPOTENT by construction — it writes nothing.
 * A census of ZERO still prints, so silence in the migrate log can only ever
 * mean "did not run".
 */
return new class extends Migration
{
    /** How many rows to name individually before truncating the listing. */
    private const int LISTED_ROW_LIMIT = 50;

    private const string TAG = '[I-1]';

    public function up(): void
    {
        if (! Schema::hasTable('payment_methods')) {
            $this->emit(self::TAG.' SKIPPED: no payment_methods table on this tenant — nothing to census.');

            return;
        }

        if (! Schema::hasColumn('payment_methods', 'is_cash_tender')) {
            $this->emit(
                self::TAG.' SKIPPED: payment_methods.is_cash_tender is absent — '
                .'2026_07_28_100000_add_is_cash_tender_to_payment_methods has not run on this tenant.'
            );

            return;
        }

        $rows = DB::table('payment_methods')
            ->select(['id', 'tenant_id', 'company_id', 'code', 'name', 'is_cash_tender', 'is_active'])
            ->where(function ($query): void {
                // (A) + (B): a cash-family code left unflagged. One predicate,
                // because the split they cause is identical; the per-row shape
                // below tells them apart so the suggested remedy can differ.
                $query->where(function ($cashish): void {
                    $cashish->whereRaw('UPPER(code) = ?', ['CASH'])
                        ->where('is_cash_tender', false);
                })
                    // (C): the flag on anything that is not the canonical code.
                    ->orWhere(function ($flagged): void {
                        $flagged->where('is_cash_tender', true)
                            ->where('code', '!=', 'CASH');
                    });
            })
            ->orderBy('company_id')
            ->orderBy('code')
            ->get();

        $this->emit(sprintf(
            '%s cash-tender invariant violations found: %d',
            self::TAG,
            $rows->count(),
        ));

        Log::info('cash_tender_invariant.census', ['violations' => $rows->count()]);

        if ($rows->isEmpty()) {
            return;
        }

        // Per COMPANY, because that is the scope the uniqueness of `code` lives
        // in (`unique(company_id, code)` since
        // 2025_12_30_195300_fix_multi_company_unique_constraints) and therefore
        // the scope in which a remedy is decidable: whether a mixed-case row can
        // be renamed to CASH depends on whether ITS company already holds one.
        $byCompany = $rows->groupBy(static fn (object $row): string => (string) $row->company_id);

        foreach ($byCompany as $companyId => $companyRows) {
            $codes = $companyRows->map(static fn (object $row): string => (string) $row->code)->all();

            $this->emit(sprintf(
                '%s   company %s: %d row(s) — %s',
                self::TAG,
                (string) $companyId,
                $companyRows->count(),
                implode(', ', $codes),
            ));

            Log::warning('cash_tender_invariant.census.company', [
                'company_id' => (string) $companyId,
                'violations' => $companyRows->count(),
                'codes' => $codes,
            ]);
        }

        $listed = 0;

        /** @var object{id: string, tenant_id: string, company_id: string, code: string, name: string, is_cash_tender: bool|int, is_active: bool|int} $row */
        foreach ($rows as $row) {
            if ($listed >= self::LISTED_ROW_LIMIT) {
                $this->emit(sprintf(
                    '%s   … %d further row(s) not listed; query payment_methods for the rest.',
                    self::TAG,
                    $rows->count() - $listed,
                ));

                break;
            }

            $this->emit(sprintf(
                '%s   %s  company=%s  code=%s  is_cash_tender=%s  active=%s  →  %s',
                self::TAG,
                (string) $row->id,
                (string) $row->company_id,
                (string) $row->code,
                ((bool) $row->is_cash_tender) ? 'true' : 'false',
                ((bool) $row->is_active) ? 'true' : 'false',
                $this->suggestedStatement($row),
            ));

            $listed++;
        }

        $this->emit(
            self::TAG.' NOTHING WAS CHANGED. Each remedy reclassifies money that has already moved '
            .'(a rename invalidates the payment_method_code snapshot on existing receipt payments and needs a '
            .'forced device payment-method resync), so it is an operator decision, not a deploy step.'
        );
    }

    /**
     * The statement an operator would run for THIS row — never executed here.
     *
     * @param  object{id: string, code: string, is_cash_tender: bool|int}  $row
     */
    private function suggestedStatement(object $row): string
    {
        $id = (string) $row->id;
        $code = (string) $row->code;

        if ((bool) $row->is_cash_tender) {
            // (C) — flagged on a non-canonical code. The flag is the wrong half:
            // only one method per company may hold `CASH`, so renaming is not
            // generally available and clearing the flag is the safe remedy.
            return sprintf(
                "UPDATE payment_methods SET is_cash_tender = false WHERE id = '%s'; "
                .'-- flag set on a non-canonical code; clear it, or rename this method to CASH if the company has no '
                .'canonical row and this really is its cash tender',
                $id,
            );
        }

        if ($code === 'CASH') {
            // (A) — the canonical row itself, unflagged. Flagging it is the only
            // coherent end state: the company's cash tender IS this row.
            return sprintf(
                "UPDATE payment_methods SET is_cash_tender = true WHERE id = '%s'; "
                .'-- canonical CASH row left unflagged; flagging it makes every consumer agree',
                $id,
            );
        }

        // (B) — a mixed-case collision loser. It CANNOT be flagged: the flag is
        // only legal on the exact code `CASH`, and the canonical row (or an
        // earlier variant) already owns it. Rename it out of the cash family so
        // no consumer can mistake it for cash, and resync the devices.
        return sprintf(
            "UPDATE payment_methods SET code = '%s_LEGACY' WHERE id = '%s'; "
            .'-- mixed-case cash variant; it cannot be flagged (the canonical CASH row owns the code), so rename it '
            .'out of the cash family. Existing receipt payments keep the OLD code snapshot, and devices cache '
            .'methods by code: force a payment-method resync afterwards',
            strtoupper($code),
            $id,
        );
    }

    /**
     * Write to the migrate output.
     *
     * `echo` and not a logger call alone: `tenants:migrate` streams stdout per
     * tenant, and that stream is what the deploy note asks the operator to read.
     */
    private function emit(string $message): void
    {
        echo $message.PHP_EOL;
    }

    /**
     * Nothing to reverse — the up leg is a read.
     */
    public function down(): void
    {
        Log::info('cash_tender_invariant.census: read-only migration, down() is a no-op.');
    }
};
