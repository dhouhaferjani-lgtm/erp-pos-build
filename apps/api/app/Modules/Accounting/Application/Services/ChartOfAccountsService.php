<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\CountryDefaults\Application\Services\CountryTemplateResolver;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Infrastructure\Seeders\TemplateChartOfAccountsSeeder;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\GenericChartOfAccountsSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Service for managing chart of accounts operations.
 *
 * Handles:
 * - Country-specific COA seeding
 * - System purpose validation
 * - Account purpose assignment
 */
class ChartOfAccountsService
{
    public function __construct(
        private readonly CountryTemplateResolver $templateResolver,
        private readonly TemplateChartOfAccountsSeeder $templateSeeder,
        private readonly InventoryVarianceAccountProvisioner $inventoryVarianceAccounts,
        private readonly RefundCompensationAccountProvider $refundCompensationAccounts,
    ) {}

    /**
     * Seed chart of accounts for a newly created company.
     * Automatically selects the appropriate seeder based on country.
     * Falls back to generic international chart for countries without a dedicated seeder.
     */
    public function seedForCompany(Company $company): void
    {
        if ((bool) config('country_defaults.provisioning_enabled', false)) {
            $template = $this->templateResolver->resolve(
                TemplateDomain::ChartOfAccounts,
                $company->country_code,
            );
            DB::transaction(function () use ($company, $template): void {
                $this->templateSeeder->seed($template, $company);
                $this->inventoryVarianceAccounts->provisionTemplateCompany(
                    $company->id,
                    $company->tenant_id,
                    $company->country_code,
                );
                $this->refundCompensationAccounts->provisionNewCompany(
                    $company->id,
                    $company->tenant_id,
                    $company->country_code,
                );
            });

            return;
        }

        $seeder = $this->getSeederForCountry($company->country_code);

        DB::transaction(function () use ($company, $seeder): void {
            /** @var TunisiaChartOfAccountsSeeder|FranceChartOfAccountsSeeder|GenericChartOfAccountsSeeder $seeder */
            $seeder->run($company->id, $company->tenant_id);
            $this->inventoryVarianceAccounts->provisionCompany(
                $company->id,
                $company->tenant_id,
                $company->country_code,
            );
            $this->refundCompensationAccounts->provisionNewCompany(
                $company->id,
                $company->tenant_id,
                $company->country_code,
            );
        });
    }

    /**
     * Check if a company has all required system accounts.
     *
     * @return array{valid: bool, missing_purposes: list<string>}
     */
    public function validateCompanyAccounts(string $companyId): array
    {
        $missing = [];

        foreach (SystemAccountPurpose::requiredPurposes() as $purpose) {
            $account = Account::findByPurpose($companyId, $purpose);

            if ($account === null) {
                $missing[] = $purpose->value;
            }
        }

        return [
            'valid' => empty($missing),
            'missing_purposes' => $missing,
        ];
    }

    /**
     * Get account by system purpose for a company.
     *
     * @throws RuntimeException When account not found
     */
    public function getAccountByPurpose(string $companyId, SystemAccountPurpose $purpose): Account
    {
        return Account::findByPurposeOrFail($companyId, $purpose);
    }

    /**
     * List all accounts with their system purposes for admin display.
     *
     * @return list<array{id: string, code: string, name: string, type: string, system_purpose: string|null, is_system: bool}>
     */
    public function getAccountsWithPurposes(string $companyId): array
    {
        /** @var list<array{id: string, code: string, name: string, type: string, system_purpose: string|null, is_system: bool}> */
        return Account::forCompany($companyId)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'system_purpose', 'is_system'])
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'system_purpose' => $account->system_purpose?->value,
                'is_system' => $account->is_system,
            ])
            ->toArray();
    }

    /**
     * Assign a system purpose to an account (admin function).
     *
     * @throws RuntimeException If purpose already assigned to another account
     */
    public function assignPurpose(string $companyId, string $accountId, SystemAccountPurpose $purpose): void
    {
        // Check if another account already has this purpose
        $existing = Account::forCompany($companyId)
            ->withPurpose($purpose)
            ->where('id', '!=', $accountId)
            ->first();

        if ($existing !== null) {
            throw new RuntimeException(
                "Purpose '{$purpose->value}' is already assigned to account {$existing->code} ({$existing->name}). ".
                'Remove it from that account first.'
            );
        }

        $account = Account::where('company_id', $companyId)
            ->where('id', $accountId)
            ->firstOrFail();

        $account->update(['system_purpose' => $purpose]);
    }

    /**
     * Remove system purpose from an account.
     */
    public function removePurpose(string $companyId, string $accountId): void
    {
        Account::where('company_id', $companyId)
            ->where('id', $accountId)
            ->update(['system_purpose' => null]);
    }

    /**
     * Get the seeder instance for a given country.
     *
     * Returns country-specific seeders for TN/FR, generic for all others.
     *
     * @return TunisiaChartOfAccountsSeeder|FranceChartOfAccountsSeeder|GenericChartOfAccountsSeeder
     */
    private function getSeederForCountry(string $countryCode): Seeder
    {
        return match (strtoupper($countryCode)) {
            'TN' => new TunisiaChartOfAccountsSeeder,
            'FR' => new FranceChartOfAccountsSeeder,
            default => new GenericChartOfAccountsSeeder,
        };
    }

    /**
     * Get list of countries with dedicated chart of accounts seeders.
     *
     * All countries are supported via the generic fallback, but these
     * have country-specific accounting plans.
     *
     * @return list<string>
     */
    public function getSupportedCountries(): array
    {
        return ['TN', 'FR'];
    }

    /**
     * Get all available system purposes with labels.
     *
     * @return list<array{value: string, label: string}>
     */
    public function getAvailablePurposes(): array
    {
        return array_map(
            fn (SystemAccountPurpose $purpose): array => [
                'value' => $purpose->value,
                'label' => $purpose->label(),
            ],
            SystemAccountPurpose::cases()
        );
    }
}
