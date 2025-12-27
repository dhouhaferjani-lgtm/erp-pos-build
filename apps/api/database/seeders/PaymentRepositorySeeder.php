<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PaymentRepositorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the first tenant and company (demo tenant)
        $tenant = \App\Modules\Tenant\Domain\Tenant::first();
        $company = Company::first();

        if (! $tenant || ! $company) {
            $this->command->error('No tenant or company found. Please run DatabaseSeeder first.');

            return;
        }

        $repositories = [
            // Cash Registers
            [
                'code' => 'CASH-01',
                'name' => 'Main Cash Register',
                'type' => 'cash_register',
                'bank_name' => null,
                'account_number' => null,
                'iban' => null,
                'bic' => null,
                'balance' => 500.00,
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
                'balance' => 200.00,
                'is_active' => true,
            ],
            // Safe
            [
                'code' => 'SAFE-01',
                'name' => 'Office Safe',
                'type' => 'safe',
                'bank_name' => null,
                'account_number' => null,
                'iban' => null,
                'bic' => null,
                'balance' => 5000.00,
                'is_active' => true,
            ],
            // Bank Accounts
            [
                'code' => 'BANK-01',
                'name' => 'BNP Paribas - Current Account',
                'type' => 'bank_account',
                'bank_name' => 'BNP Paribas',
                'account_number' => '30004 00123 00001234567 25',
                'iban' => 'FR76 3000 4001 2300 0012 3456 725',
                'bic' => 'BNPAFRPP',
                'balance' => 25000.00,
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
                'balance' => 15000.00,
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
                'balance' => 10000.00,
                'is_active' => true,
            ],
            // Virtual/Online Payment Account
            [
                'code' => 'VIRT-01',
                'name' => 'PayPal Business Account',
                'type' => 'virtual',
                'bank_name' => 'PayPal',
                'account_number' => 'business@example.com',
                'iban' => null,
                'bic' => null,
                'balance' => 3500.00,
                'is_active' => true,
            ],
        ];

        foreach ($repositories as $repository) {
            PaymentRepository::create(array_merge([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ], $repository));
        }

        $this->command->info('Created '.count($repositories).' payment repositories for company: '.$company->name);
    }
}
