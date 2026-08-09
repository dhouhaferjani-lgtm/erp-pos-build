<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * DPA `DPA-REV2-A` / task A3 — backfill `CustomerAdvance` (TN/FR 419, Generic
 * 4190) and `SupplierAdvance` (TN/FR 409, Generic 4090) for existing companies.
 *
 * Both purposes are in `SystemAccountPurpose::requiredPurposes()`, but the
 * France chart seeded 419 "Clients créditeurs" and 409 "Fournisseurs débiteurs"
 * with the correct ACCOUNT TYPES and **no `system_purpose`, no `is_system`**
 * (`FranceChartOfAccountsSeeder.php:172`, `:182`). A French company therefore
 * hard-fails `createCustomerAdvanceJournalEntry()` at
 * `GeneralLedgerService.php:417` on any over-payment — it cannot post a customer
 * advance at all.
 *
 * Task A2 fixed the seeder, which only helps NEW companies. The seeder's re-run
 * path promotes `is_system` but **never** `system_purpose`
 * (`FranceChartOfAccountsSeeder.php:46-57`), so re-seeding an existing chart
 * does not close the gap. **This migration is mandatory, not optional** (plan
 * A3 / A-D12).
 *
 * Guard ladder cloned from
 * `2026_08_07_100000_backfill_purchase_stamp_duty_account.php`, with one
 * structural difference: BOTH purposes are handled in one pass and they are
 * INDEPENDENT — a chart that already maps one must still receive the other, so
 * the "already mapped?" check is per-purpose, never shared.
 *
 * SELF-GUARDING AND IDEMPOTENT — pushing to `origin/dev` auto-runs
 * `tenants:migrate` on staging, so this must be safe on every tenant and every
 * chart shape, run any number of times:
 *
 *  - companies with no chart at all are skipped (nothing to attach to);
 *  - a company that already maps the purpose is skipped (re-run safety, and it
 *    respects a chart where an admin assigned the purpose to an account of
 *    their own choosing);
 *  - a company that already owns an account at the preferred code keeps it: the
 *    purpose is MAPPED ONTO that account when its `type` matches
 *    `expectedAccountType()` and it carries no other system purpose. This is the
 *    PRIMARY path here, not a fallback — every FR/TN/Generic chart already ships
 *    the row, just unpurposed. Creating a duplicate would violate
 *    `accounts_company_code_unique`;
 *  - if the preferred code is taken by an incompatible or already-purposed
 *    account, the next free code in the series is used instead;
 *  - never throws — a company this cannot place logs a warning and stays
 *    unable to post advances until fixed manually in
 *    Settings -> Chart of Accounts, exactly as the modeled migration does.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $companies = DB::table('companies')->select('id', 'tenant_id', 'name', 'country_code')->get();

        foreach ($companies as $company) {
            $companyId = (string) $company->id;

            // No chart yet: the (A2-fixed) seeder will provide both accounts when
            // one is created.
            if (DB::table('accounts')->where('company_id', $companyId)->doesntExist()) {
                continue;
            }

            $tenantId = (string) $company->tenant_id;
            $companyName = (string) $company->name;
            $countryCode = (string) ($company->country_code ?? '');

            // The two purposes are INDEPENDENT — a chart that already maps one
            // must still receive the other.
            $this->ensureAdvanceAccount(
                $companyId,
                $tenantId,
                $companyName,
                $countryCode,
                SystemAccountPurpose::CustomerAdvance,
                $now,
            );
            $this->ensureAdvanceAccount(
                $companyId,
                $tenantId,
                $companyName,
                $countryCode,
                SystemAccountPurpose::SupplierAdvance,
                $now,
            );
        }
    }

    public function down(): void
    {
        // Data correction (maps a purpose onto an existing account, or adds a
        // missing one). Not reversed: the account may already carry posted
        // journal lines, and unmapping the purpose would make customer advances
        // unpostable again — the exact defect this migration exists to fix.
    }

    private function ensureAdvanceAccount(
        string $companyId,
        string $tenantId,
        string $companyName,
        string $countryCode,
        SystemAccountPurpose $purpose,
        mixed $now,
    ): void {
        // Already mapped — re-run safety, and respects a chart where an admin
        // assigned the purpose to an account of their own choosing.
        if ($this->hasPurpose($companyId, $purpose)) {
            return;
        }

        // Mirrors ChartOfAccountsService::getSeederForCountry(): 'TN' and 'FR'
        // both use the 3-digit PCG/PCN codes; every other country falls back to
        // the Generic chart's 4-digit numbering.
        [$preferredCode, $name, $parents] = match (true) {
            $purpose === SystemAccountPurpose::CustomerAdvance && in_array($countryCode, ['TN', 'FR'], true) => ['419', 'Clients créditeurs', ['41', '4']],
            $purpose === SystemAccountPurpose::CustomerAdvance => ['4190', 'Customer Advances', ['419', '41', '4']],
            in_array($countryCode, ['TN', 'FR'], true) => ['409', 'Fournisseurs débiteurs', ['40', '4']],
            default => ['4090', 'Supplier Advances', ['409', '40', '4']],
        };

        $expectedType = $purpose->expectedAccountType()->value;

        $existing = DB::table('accounts')
            ->where('company_id', $companyId)
            ->where('code', $preferredCode)
            ->first();

        if ($existing !== null) {
            $isClaimable = $existing->type === $expectedType
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
            'type' => $expectedType,
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
     * The next unused code in the same series (419 -> 4191 .. 4199, or
     * 4190 -> 4191 .. 4199). Appending a digit keeps the account inside its own
     * class rather than colliding with the NEXT control account: 419 + 1 = 420
     * is "Personnel", a different class entirely.
     */
    private function nextFreeCode(string $companyId, string $preferred): ?string
    {
        $base = strlen($preferred) === 3 ? $preferred.'0' : $preferred;
        $prefix = substr($base, 0, 3);

        for ($suffix = 1; $suffix <= 9; $suffix++) {
            $code = $prefix.$suffix;

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
