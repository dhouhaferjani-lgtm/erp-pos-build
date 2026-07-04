<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Backfill existing tenants with Wave 4 purchase price variance accounts.
 *
 * Fresh COA seeds include these accounts, but already-provisioned companies need
 * an idempotent per-company data migration before supplier-invoice posting can
 * resolve the unconditional PPV system-purpose accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Chunk to avoid loading every Company into memory at once. The migration
        // remains idempotent: each per-company branch short-circuits when the
        // target system purpose is already present, so re-running is safe.
        Company::query()->chunk(50, function ($companies): void {
            foreach ($companies as $company) {
                $this->backfillCompany($company);
            }
        });
    }

    private function backfillCompany(Company $company): void
    {
        $tenant = Tenant::find($company->tenant_id);
        if ($tenant === null) {
            return;
        }

        $country = strtoupper((string) $company->country_code);
        $expenseParentCode = in_array($country, ['TN', 'FR'], true) ? '65' : '6000';
        $incomeParentCode = in_array($country, ['TN', 'FR'], true) ? '75' : '7000';

        $changes = [];
        $this->ensurePpvAccount(
            $company,
            SystemAccountPurpose::PurchasePriceVarianceExpense,
            '6585',
            '658',
            'Écart sur prix d\'achat',
            'expense',
            $expenseParentCode,
            $changes,
        );
        $this->ensurePpvAccount(
            $company,
            SystemAccountPurpose::PurchasePriceVarianceIncome,
            '7585',
            '758',
            'Écart sur prix d\'achat',
            'revenue',
            $incomeParentCode,
            $changes,
        );

        if (count($changes) > 0) {
            Log::info("Migration: Backfilled company {$company->id} ({$company->name}): ".implode(', ', $changes));
        }
    }

    public function down(): void
    {
        // Data backfill - not reversible.
    }

    /**
     * @param  list<string>  $changes
     */
    private function ensurePpvAccount(
        Company $company,
        SystemAccountPurpose $purpose,
        string $preferredCode,
        string $collisionPrefix,
        string $name,
        string $type,
        string $parentCode,
        array &$changes,
    ): void {
        if (Account::findByPurpose($company->id, $purpose) !== null) {
            return;
        }

        $code = $this->availableCode($company->id, $preferredCode, $collisionPrefix);
        if ($code === null) {
            Log::warning(
                "Cannot backfill {$purpose->value} for company {$company->id}: no free code in {$collisionPrefix}x."
            );

            return;
        }

        $parent = DB::table('accounts')
            ->where('company_id', $company->id)
            ->where('code', $parentCode)
            ->first();

        DB::table('accounts')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'parent_id' => $parent?->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'system_purpose' => $purpose->value,
            'is_active' => true,
            'is_system' => true,
            'balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $changes[] = "created_{$code}_{$purpose->value}";
    }

    private function availableCode(string $companyId, string $preferredCode, string $prefix): ?string
    {
        $candidate = $preferredCode;
        for ($suffix = (int) substr($preferredCode, -1); $suffix <= 9; $suffix++) {
            $candidate = $prefix.$suffix;
            $exists = DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('code', $candidate)
                ->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        return null;
    }
};
