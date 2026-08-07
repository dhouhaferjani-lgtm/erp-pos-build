<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Backfill the `PurchaseStampDuty` account (TN/FR 6354, Generic 6350 —
 * "Droits d'enregistrement et de timbre") for existing companies whose chart
 * predates it.
 *
 * Q1 gate finding I-1 (2026-08-07,
 * docs/superpowers/reviews/2026-08-07-q1-cn-stamp-gl-gate.md). `PurchaseStampDuty`
 * was added to the three chart seeders on `72517d986` (2026-06-25) with NO
 * accompanying backfill — unlike `SalesStampDutyPayable`, which got one
 * (`2026_06_30_120000_backfill_sales_stamp_duty_account.php`). A seeder only
 * runs for NEW companies, so every company whose chart was seeded before
 * 2026-06-25 is missing this account.
 *
 * That gap was inert until the Q1 lane
 * (`AccountingService::createCreditNoteGLEntries()` /
 * `GeneralLedgerService::createFromCreditNote()`) started resolving
 * `PurchaseStampDuty` as the DEBIT (fiscal-charge) leg of a credit note's own
 * stamp-duty pair: without this migration, any pre-2026-06-25 tenant hard-422s
 * (`GlResidualRefusal::NoCreditNoteStampAccount`) on every credit note that
 * carries a timbre, the moment `CreditNoteService::applyConfirmEquivalentTotals()`
 * starts persisting `documents.stamp_duty_amount` (gate finding C-1, same lane).
 *
 * Modeled EXACTLY on `2026_08_05_120000_backfill_sales_rounding_difference_accounts.php`'s
 * guard ladder — differs only in that there is no cross-purpose skip
 * (`PurchaseStampDuty` is independent of whether the company already carries
 * `SalesStampDutyPayable`; TN companies need BOTH) and the preferred
 * code/name/parent are chosen by `country_code`, mirroring
 * `ChartOfAccountsService::getSeederForCountry()` (`'TN' => Tunisia`,
 * `'FR' => France`, `default => Generic`) since TN/FR and Generic seed this
 * account under different codes (6354 vs 6350).
 *
 * SELF-GUARDING AND IDEMPOTENT — pushing to `origin/dev` auto-runs
 * `tenants:migrate` on staging, so this must be safe on every tenant and
 * every chart shape, run any number of times:
 *
 *  - companies with no chart at all are skipped (nothing to attach to);
 *  - a company that already maps `PurchaseStampDuty` is skipped (re-run
 *    safety, and respects a chart where an admin assigned the purpose to an
 *    account of their own choosing);
 *  - a company that already owns an account at the preferred code keeps it:
 *    the purpose is MAPPED ONTO that account when its `type` is compatible
 *    and it carries no other system purpose. Creating a duplicate would
 *    violate `accounts_company_code_unique`; leaving it alone would leave the
 *    company refusing to post every stamp-bearing credit note forever;
 *  - if the preferred code is taken by an incompatible or already-purposed
 *    account, the next free code in the series is used instead;
 *  - never throws — a company this cannot place logs a warning and stays
 *    unpostable for stamp-bearing credit notes until fixed manually in
 *    Settings -> Chart of Accounts (same remedy `GlResidualRefusal` already
 *    points at), exactly as the modeled migration does.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $companies = DB::table('companies')->select('id', 'tenant_id', 'name', 'country_code')->get();

        foreach ($companies as $company) {
            $companyId = (string) $company->id;

            // No chart yet: the seeder will provide the account when one is created.
            if (DB::table('accounts')->where('company_id', $companyId)->doesntExist()) {
                continue;
            }

            $this->ensurePurchaseStampDutyAccount($companyId, (string) $company->tenant_id, (string) $company->name, (string) ($company->country_code ?? ''), $now);
        }
    }

    public function down(): void
    {
        // Data correction (adds a missing system account, or maps a purpose onto an
        // existing one). Not reversed: the account may already carry posted journal
        // lines, and unmapping the purpose would make credit notes unpostable again.
    }

    private function ensurePurchaseStampDutyAccount(
        string $companyId,
        string $tenantId,
        string $companyName,
        string $countryCode,
        mixed $now,
    ): void {
        $purpose = SystemAccountPurpose::PurchaseStampDuty;

        // Already mapped — re-run safety, and respects a chart where an admin
        // assigned the purpose to an account of their own choosing.
        if ($this->hasPurpose($companyId, $purpose)) {
            return;
        }

        // Mirrors ChartOfAccountsService::getSeederForCountry(): 'TN'/'FR' use
        // the PCG code 6354 ("Droits d'enregistrement et de timbre"); every
        // other country falls back to the Generic chart's 6350 ("Purchase
        // Stamp Duty").
        [$preferredCode, $name, $parents] = match ($countryCode) {
            'TN', 'FR' => ['6354', "Droits d'enregistrement et de timbre", ['63', '6000']],
            default => ['6350', 'Purchase Stamp Duty', ['6000']],
        };

        $existing = DB::table('accounts')
            ->where('company_id', $companyId)
            ->where('code', $preferredCode)
            ->first();

        if ($existing !== null) {
            $isClaimable = $existing->type === 'expense'
                && ($existing->system_purpose === null || $existing->system_purpose === '');

            if ($isClaimable) {
                DB::table('accounts')
                    ->where('id', $existing->id)
                    ->update([
                        'system_purpose' => $purpose->value,
                        'is_system' => true,
                        'updated_at' => $now,
                    ]);

                Log::info(sprintf(
                    'Migration: mapped %s onto existing account %s for company %s (%s).',
                    $purpose->value,
                    $preferredCode,
                    $companyId,
                    $companyName,
                ));

                return;
            }
        }

        $code = $existing === null
            ? $preferredCode
            : $this->nextFreeCode($companyId, $preferredCode);

        if ($code === null) {
            Log::warning(sprintf(
                'Migration: could not place %s for company %s (%s) — no free code near %s. '
                .'Assign the purpose manually in Settings -> Chart of Accounts.',
                $purpose->value,
                $companyId,
                $companyName,
                $preferredCode,
            ));

            return;
        }

        DB::table('accounts')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'parent_id' => $this->resolveParentId($companyId, $parents),
            'code' => $code,
            'name' => $name,
            'type' => 'expense',
            'system_purpose' => $purpose->value,
            'is_active' => true,
            'is_system' => true,
            'balance' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Log::info(sprintf(
            'Migration: created account %s (%s) for company %s (%s).',
            $code,
            $purpose->value,
            $companyId,
            $companyName,
        ));
    }

    private function hasPurpose(string $companyId, SystemAccountPurpose $purpose): bool
    {
        return DB::table('accounts')
            ->where('company_id', $companyId)
            ->where('system_purpose', $purpose->value)
            ->exists();
    }

    /**
     * The next unused code in the same series (6354 -> 6355 -> ... -> 6362,
     * or 6350 -> 6351 -> ... -> 6358).
     */
    private function nextFreeCode(string $companyId, string $preferred): ?string
    {
        $base = (int) $preferred;

        for ($candidate = $base + 1; $candidate <= $base + 8; $candidate++) {
            $code = (string) $candidate;

            $taken = DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->exists();

            if (! $taken) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function resolveParentId(string $companyId, array $candidates): ?string
    {
        foreach ($candidates as $parentCode) {
            $parentId = DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('code', $parentCode)
                ->value('id');

            if ($parentId !== null) {
                return (string) $parentId;
            }
        }

        return null;
    }
};
