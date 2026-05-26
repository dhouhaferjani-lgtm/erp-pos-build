<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fix existing Tunisian companies that were created without:
 * - default_tax_rate (was never set during registration)
 * - tax_configurations (TunisiaTaxConfigurationSeeder was not in ProductionSeeder)
 * - GL accounts (if chart of accounts seeding was skipped)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Seed Tunisia tax configurations globally (idempotent)
        // Guard: only seed if countries table has data (skip in fresh test DBs)
        $hasCountries = DB::table('countries')->where('code', 'TN')->exists();
        if ($hasCountries) {
            $seeder = new TunisiaTaxConfigurationSeeder;
            $seeder->run();
            Log::info('Migration: Tunisia tax configurations seeded.');
        }

        // 2. Fix default_tax_rate for all Tunisian companies
        $tnCompanies = Company::where('country_code', 'TN')->get();

        foreach ($tnCompanies as $company) {
            $changes = [];

            // Set default_tax_rate if missing
            if ($company->default_tax_rate === null) {
                $company->update(['default_tax_rate' => '19.00']);
                $changes[] = 'default_tax_rate=19.00';
            }

            // Check if GL accounts were seeded
            $accountCount = DB::table('accounts')
                ->where('company_id', $company->id)
                ->count();

            if ($accountCount === 0) {
                // GL accounts missing entirely — seed them
                $chartSeeder = new TunisiaChartOfAccountsSeeder;
                $chartSeeder->run($company->id, $company->tenant_id);
                $changes[] = 'chart_of_accounts_seeded';
            } else {
                // Check for critical system_purpose accounts
                $missingPurposes = $this->findMissingSystemPurposes($company->id);
                if (count($missingPurposes) > 0) {
                    $changes[] = 'WARNING: missing system purposes: '.implode(', ', $missingPurposes);
                }
            }

            if (count($changes) > 0) {
                Log::info("Migration: Fixed company {$company->id} ({$company->name}): ".implode(', ', $changes));
            }
        }

        // 3. Also fix French companies' default_tax_rate if missing
        Company::where('country_code', 'FR')
            ->whereNull('default_tax_rate')
            ->update(['default_tax_rate' => '20.00']);

        Log::info("Migration: Fixed {$tnCompanies->count()} Tunisian companies.");
    }

    public function down(): void
    {
        // Not reversible — setting tax rates is a data correction, not a schema change
    }

    /**
     * @return list<string>
     */
    private function findMissingSystemPurposes(string $companyId): array
    {
        $requiredPurposes = [
            SystemAccountPurpose::CustomerReceivable->value,
            SystemAccountPurpose::ProductRevenue->value,
            SystemAccountPurpose::ServiceRevenue->value,
            SystemAccountPurpose::VatCollected->value,
            SystemAccountPurpose::Cash->value,
            SystemAccountPurpose::Bank->value,
        ];

        $existingPurposes = DB::table('accounts')
            ->where('company_id', $companyId)
            ->whereNotNull('system_purpose')
            ->pluck('system_purpose')
            ->toArray();

        return array_values(array_diff($requiredPurposes, $existingPurposes));
    }
};
