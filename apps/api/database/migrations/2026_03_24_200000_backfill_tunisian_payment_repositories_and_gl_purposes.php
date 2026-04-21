<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\PaymentRepositorySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Backfill existing tenants that are missing:
 * 1. Payment repositories (cash registers/bank accounts with GL links)
 * 2. CostOfGoodsSold system purpose (account 603 for Tunisia)
 * 3. GeneralExpense system purpose (account 65 for Tunisia)
 *
 * These were not seeded during registration, causing 500 errors when
 * processing POS sales (no GL account on payment repository) or
 * inventory operations (no COGS account).
 */
return new class extends Migration
{
    public function up(): void
    {
        $companies = Company::all();

        foreach ($companies as $company) {
            $tenant = Tenant::find($company->tenant_id);
            if ($tenant === null) {
                continue;
            }

            $changes = [];

            // 1. Seed payment repositories if none exist
            $repoCount = PaymentRepository::where('company_id', $company->id)->count();
            if ($repoCount === 0) {
                $seeder = new PaymentRepositorySeeder;
                $seeder->run($company);
                $changes[] = 'payment_repositories_seeded';
            } else {
                // Backfill gl_account_id on existing repositories that are missing it
                $this->backfillRepositoryGlAccounts($company, $changes);
            }

            // 2. Add CostOfGoodsSold purpose if missing (Tunisia: account 603)
            if (strtoupper($company->country_code) === 'TN') {
                $this->ensureTunisiaSystemPurpose(
                    $company,
                    SystemAccountPurpose::CostOfGoodsSold,
                    '603',
                    'Variation des stocks',
                    'expense',
                    '60',
                    $changes,
                );

                $this->ensureTunisiaSystemPurpose(
                    $company,
                    SystemAccountPurpose::GeneralExpense,
                    null, // Don't create, just assign to existing account 65
                    null,
                    null,
                    null,
                    $changes,
                    '65', // Try to assign to existing account with this code
                );
            }

            if (count($changes) > 0) {
                Log::info("Migration: Backfilled company {$company->id} ({$company->name}): ".implode(', ', $changes));
            }
        }
    }

    public function down(): void
    {
        // Data backfill — not reversible
    }

    /**
     * Backfill gl_account_id on payment repositories that are missing it.
     *
     * @param  list<string>  $changes
     */
    private function backfillRepositoryGlAccounts(Company $company, array &$changes): void
    {
        $cashAccount = Account::findByPurpose($company->id, SystemAccountPurpose::Cash);
        $bankAccount = Account::findByPurpose($company->id, SystemAccountPurpose::Bank);

        $repos = PaymentRepository::where('company_id', $company->id)
            ->whereNull('gl_account_id')
            ->get();

        foreach ($repos as $repo) {
            $glAccountId = in_array($repo->type, [RepositoryType::CashRegister, RepositoryType::Safe], true)
                ? $cashAccount?->id
                : $bankAccount?->id;

            if ($glAccountId !== null) {
                $repo->update(['gl_account_id' => $glAccountId]);
                $changes[] = "repo_{$repo->code}_gl_linked";
            } else {
                Log::warning(
                    "Cannot backfill gl_account_id for repository {$repo->code} (company {$company->id}): "
                    .'no Cash or Bank GL account found with system purpose.'
                );
            }
        }
    }

    /**
     * Ensure a system purpose account exists for a Tunisian company.
     *
     * @param  list<string>  $changes
     */
    private function ensureTunisiaSystemPurpose(
        Company $company,
        SystemAccountPurpose $purpose,
        ?string $newCode,
        ?string $newName,
        ?string $newType,
        ?string $parentCode,
        array &$changes,
        ?string $existingCode = null,
    ): void {
        // Check if purpose already assigned
        $existing = Account::findByPurpose($company->id, $purpose);
        if ($existing !== null) {
            return;
        }

        // Try to assign to existing account by code
        $targetCode = $existingCode ?? $newCode;
        if ($targetCode !== null) {
            $account = DB::table('accounts')
                ->where('company_id', $company->id)
                ->where('code', $targetCode)
                ->first();

            if ($account !== null) {
                /** @var stdClass $account */
                DB::table('accounts')
                    ->where('id', $account->id)
                    ->update([
                        'system_purpose' => $purpose->value,
                        'is_system' => true,
                    ]);
                $changes[] = "assigned_{$purpose->value}_to_{$targetCode}";

                return;
            }
        }

        // Create new account if we have the details
        if ($newCode !== null && $newName !== null && $newType !== null) {
            $parentId = null;
            if ($parentCode !== null) {
                $parent = DB::table('accounts')
                    ->where('company_id', $company->id)
                    ->where('code', $parentCode)
                    ->first();
                /** @var stdClass|null $parent */
                $parentId = $parent?->id;
            }

            DB::table('accounts')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'parent_id' => $parentId,
                'code' => $newCode,
                'name' => $newName,
                'type' => $newType,
                'system_purpose' => $purpose->value,
                'is_active' => true,
                'is_system' => true,
                'balance' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $changes[] = "created_{$newCode}_{$purpose->value}";
        }
    }
};
