<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PaymentRepositorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @param  Company|null  $company  Optional specific company to seed for
     */
    public function run(?Company $company = null): void
    {
        // If a specific company is provided, seed only for that company
        if ($company !== null) {
            $tenant = Tenant::find($company->tenant_id);
            if ($tenant === null) {
                return;
            }
            $this->seedRepositoriesForCompany($company, $tenant);

            return;
        }

        // Otherwise, seed for first/demo company (dev mode)
        $tenant = Tenant::first();
        $firstCompany = Company::first();

        if (! $tenant || ! $firstCompany) {
            $this->command->error('No tenant or company found. Please run DatabaseSeeder first.');

            return;
        }

        $this->seedRepositoriesForCompany($firstCompany, $tenant);
    }

    /**
     * Seed payment repositories for a specific company.
     */
    private function seedRepositoriesForCompany(Company $company, Tenant $tenant): void
    {
        $repositories = $this->getRepositoriesForCountry($company->country_code);

        // Look up GL accounts by purpose for linking
        $cashAccount = Account::findByPurpose($company->id, SystemAccountPurpose::Cash);
        $bankAccount = Account::findByPurpose($company->id, SystemAccountPurpose::Bank);

        if ($cashAccount === null || $bankAccount === null) {
            $this->command->warn(
                "GL accounts not found for {$company->name}. Payment repositories will be created without GL links. "
                .'Run ChartOfAccountsSeeder first, then re-run this seeder.'
            );
        }

        foreach ($repositories as $repo) {
            $glAccountId = $this->resolveGlAccountId($repo['type'], $cashAccount, $bankAccount);

            PaymentRepository::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'account_id' => $glAccountId,
                'gl_account_id' => $glAccountId,
                ...$repo,
            ]);
        }

        $this->command->info('Created '.count($repositories).' payment repositories for '.$company->name);
    }

    /**
     * Resolve the GL account ID based on repository type.
     */
    private function resolveGlAccountId(string $type, ?Account $cashAccount, ?Account $bankAccount): ?string
    {
        $repositoryType = RepositoryType::from($type);

        return match ($repositoryType) {
            RepositoryType::CashRegister, RepositoryType::Safe => $cashAccount?->id,
            RepositoryType::BankAccount, RepositoryType::Virtual => $bankAccount?->id,
        };
    }

    /**
     * @return array<int, array{
     *     code: string,
     *     name: string,
     *     type: string,
     *     bank_name: string|null,
     *     account_number: string|null,
     *     iban: string|null,
     *     bic: string|null,
     *     balance: string,
     *     is_active: bool
     * }>
     */
    private function getRepositoriesForCountry(string $countryCode): array
    {
        // Common repositories (same for all countries)
        $common = [
            [
                'code' => 'CASH-01',
                'name' => 'Main Cash Register',
                'type' => 'cash_register',
                'bank_name' => null,
                'account_number' => null,
                'iban' => null,
                'bic' => null,
                'balance' => '500.000',
                'is_active' => true,
            ],
            [
                'code' => 'CASH-02',
                'name' => 'Workshop Cash Register',
                'type' => 'cash_register',
                'bank_name' => null,
                'account_number' => null,
                'iban' => null,
                'bic' => null,
                'balance' => '200.000',
                'is_active' => true,
            ],
            [
                'code' => 'SAFE-01',
                'name' => 'Office Safe',
                'type' => 'safe',
                'bank_name' => null,
                'account_number' => null,
                'iban' => null,
                'bic' => null,
                'balance' => '5000.000',
                'is_active' => true,
            ],
        ];

        // Country-specific banks
        $banks = match (strtoupper($countryCode)) {
            'TN' => [
                [
                    'code' => 'BANK-01',
                    'name' => 'Banque de Tunisie - Current Account',
                    'type' => 'bank_account',
                    'bank_name' => 'Banque de Tunisie',
                    'account_number' => '08 000 0012345678',
                    'iban' => 'TN59 0800 0001 2345 6789 0123',
                    'bic' => 'BTUETTT',
                    'balance' => '25000.000',
                    'is_active' => true,
                ],
                [
                    'code' => 'BANK-02',
                    'name' => 'STB - Business Account',
                    'type' => 'bank_account',
                    'bank_name' => 'Société Tunisienne de Banque',
                    'account_number' => '10 000 0012345678',
                    'iban' => 'TN59 1000 0001 2345 6789 0123',
                    'bic' => 'STBKTTT',
                    'balance' => '15000.000',
                    'is_active' => true,
                ],
                [
                    'code' => 'BANK-03',
                    'name' => 'BIAT - Savings Account',
                    'type' => 'bank_account',
                    'bank_name' => 'Banque Internationale Arabe de Tunisie',
                    'account_number' => '08 030 0012345678',
                    'iban' => 'TN59 0803 0001 2345 6789 0123',
                    'bic' => 'BIATTTTT',
                    'balance' => '10000.000',
                    'is_active' => true,
                ],
                [
                    'code' => 'VIRT-01',
                    'name' => 'D17 Digital Wallet',
                    'type' => 'virtual',
                    'bank_name' => 'D17',
                    'account_number' => 'business@example.tn',
                    'iban' => null,
                    'bic' => null,
                    'balance' => '2000.000',
                    'is_active' => true,
                ],
            ],
            'FR' => [
                [
                    'code' => 'BANK-01',
                    'name' => 'BNP Paribas - Current Account',
                    'type' => 'bank_account',
                    'bank_name' => 'BNP Paribas',
                    'account_number' => '30004 00123 00001234567 25',
                    'iban' => 'FR76 3000 4001 2300 0012 3456 725',
                    'bic' => 'BNPAFRPP',
                    'balance' => '25000.000',
                    'is_active' => true,
                ],
                [
                    'code' => 'BANK-02',
                    'name' => 'Crédit Agricole - Business Account',
                    'type' => 'bank_account',
                    'bank_name' => 'Crédit Agricole',
                    'account_number' => '11315 00020 12345678901 54',
                    'iban' => 'FR14 1131 5000 2012 3456 7890 154',
                    'bic' => 'AGRIFRPP',
                    'balance' => '15000.000',
                    'is_active' => true,
                ],
                [
                    'code' => 'BANK-03',
                    'name' => 'Société Générale - Savings Account',
                    'type' => 'bank_account',
                    'bank_name' => 'Société Générale',
                    'account_number' => '30003 00123 11223344556 78',
                    'iban' => 'FR31 3000 3001 2311 2233 4455 678',
                    'bic' => 'SOGEFRPP',
                    'balance' => '10000.000',
                    'is_active' => true,
                ],
                [
                    'code' => 'VIRT-01',
                    'name' => 'PayPal Business Account',
                    'type' => 'virtual',
                    'bank_name' => 'PayPal',
                    'account_number' => 'business@example.com',
                    'iban' => null,
                    'bic' => null,
                    'balance' => '3500.000',
                    'is_active' => true,
                ],
            ],
            default => [],
        };

        return array_merge($common, $banks);
    }
}
