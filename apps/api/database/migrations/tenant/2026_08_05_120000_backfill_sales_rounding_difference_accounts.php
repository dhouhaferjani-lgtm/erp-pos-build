<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Backfill the sales tax-rounding difference accounts (PCG 658/758) for existing
 * companies whose chart has no account able to absorb a positive GL residual.
 *
 * W-6 D1a. A sales document debits AR with the HEADER `total` and credits the
 * LINES' revenue plus a RECOMPUTED per-line VAT. `groupTaxByRate()` truncates each
 * line's tax while `TaxCalculationService` truncates once per rate bucket, and
 * `Σ trunc(xᵢ) <= trunc(Σ xᵢ)`, so the header can exceed the GL credits by up to
 * one unit of the last place per line. That residual needs a credit leg or the
 * entry cannot balance — and `AccountingService` now REFUSES to post a document
 * whose residual has nowhere to go, rather than sealing an unbalanced entry.
 *
 * The Tunisian chart absorbs the residual in `4375` alongside the genuine timbre.
 * `FranceChartOfAccountsSeeder` / `GenericChartOfAccountsSeeder` now seed
 * `6581` / `7581` for the same job, but a SEEDER only runs for NEW companies —
 * every existing FR/Generic company would hit unpostable invoices on the first
 * two-line order with an odd truncation. Hence this backfill, modelled on
 * `2026_06_30_120000_backfill_sales_stamp_duty_account.php`.
 *
 * SELF-GUARDING AND IDEMPOTENT — pushing to `origin/dev` auto-runs
 * `tenants:migrate` on staging, so this must be safe on every tenant and every
 * chart shape, run any number of times:
 *
 *  - companies with no chart at all are skipped (nothing to attach to);
 *  - companies that already map `SalesStampDutyPayable` are skipped — their
 *    residual already has a home and `residualPlan()` prefers it, so the pair
 *    would be dead rows. This is what makes the migration a NO-OP on Tunisia;
 *  - a purpose already mapped for the company is skipped (re-run safety);
 *  - a company that already owns an account at the preferred code keeps it: the
 *    purpose is MAPPED ONTO that account when its `type` is compatible and it
 *    carries no other system purpose. Creating a duplicate would violate
 *    `accounts_company_code_unique` and leaving it alone would leave the company
 *    refusing to post — the exact edge the seeder's skip-if-exists produces;
 *  - if the preferred code is taken by an incompatible or already-purposed
 *    account, the next free code in the series is used instead.
 */
return new class extends Migration
{
    /**
     * purpose => [preferred code, account type, FR parent, generic parent, name]
     *
     * @var list<array{purpose: SystemAccountPurpose, code: string, type: string, parents: list<string>, name: string}>
     */
    private array $definitions;

    public function __construct()
    {
        $this->definitions = [
            [
                'purpose' => SystemAccountPurpose::SalesRoundingDifferenceExpense,
                'code' => '6581',
                'type' => 'expense',
                'parents' => ['65', '6000'],
                'name' => 'Écart d\'arrondi sur facturation (charges)',
            ],
            [
                'purpose' => SystemAccountPurpose::SalesRoundingDifferenceIncome,
                'code' => '7581',
                'type' => 'revenue',
                'parents' => ['75', '7000'],
                'name' => 'Écart d\'arrondi sur facturation (produits)',
            ],
        ];
    }

    public function up(): void
    {
        $now = now();

        $companies = DB::table('companies')->select('id', 'tenant_id', 'name')->get();

        foreach ($companies as $company) {
            $companyId = (string) $company->id;

            // No chart yet: the seeder will provide the pair when one is created.
            if (DB::table('accounts')->where('company_id', $companyId)->doesntExist()) {
                continue;
            }

            // Tunisia (and any chart carrying a collected-timbre liability) already
            // has an absorbing account, and residualPlan() prefers it. No-op.
            if ($this->hasPurpose($companyId, SystemAccountPurpose::SalesStampDutyPayable)) {
                continue;
            }

            foreach ($this->definitions as $definition) {
                $this->ensurePurpose($companyId, (string) $company->tenant_id, (string) $company->name, $definition, $now);
            }
        }
    }

    public function down(): void
    {
        // Data correction (adds a missing system account, or maps a purpose onto an
        // existing one). Not reversed: the account may already carry posted journal
        // lines, and unmapping the purpose would make invoices unpostable again.
    }

    /**
     * @param  array{purpose: SystemAccountPurpose, code: string, type: string, parents: list<string>, name: string}  $definition
     */
    private function ensurePurpose(
        string $companyId,
        string $tenantId,
        string $companyName,
        array $definition,
        mixed $now,
    ): void {
        $purpose = $definition['purpose'];

        // Already mapped — re-run safety, and respects a chart where an admin
        // assigned the purpose to an account of their own choosing.
        if ($this->hasPurpose($companyId, $purpose)) {
            return;
        }

        $existing = DB::table('accounts')
            ->where('company_id', $companyId)
            ->where('code', $definition['code'])
            ->first();

        if ($existing !== null) {
            $isClaimable = $existing->type === $definition['type']
                && ($existing->system_purpose === null || $existing->system_purpose === '');

            if ($isClaimable) {
                // The company already owns an account at this code with no purpose
                // of its own. Map the purpose onto it — a duplicate row would break
                // accounts_company_code_unique, and skipping (what the seeder does)
                // leaves this company unable to post. Name and type are the user's;
                // only the purpose and the system flag are set, because the GL now
                // depends on this account existing.
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
                    $definition['code'],
                    $companyId,
                    $companyName,
                ));

                return;
            }
        }

        $code = $existing === null
            ? $definition['code']
            : $this->nextFreeCode($companyId, $definition['code']);

        if ($code === null) {
            Log::warning(sprintf(
                'Migration: could not place %s for company %s (%s) — no free code near %s. '
                .'Assign the purpose manually in Settings -> Chart of Accounts.',
                $purpose->value,
                $companyId,
                $companyName,
                $definition['code'],
            ));

            return;
        }

        DB::table('accounts')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'parent_id' => $this->resolveParentId($companyId, $definition['parents']),
            'code' => $code,
            'name' => $definition['name'],
            'type' => $definition['type'],
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
     * The next unused code in the same series (6581 -> 6582 -> ... -> 6589).
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
