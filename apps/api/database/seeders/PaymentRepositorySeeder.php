<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Bank;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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
            $bankCode = $repo['bank_code'];
            unset($repo['bank_code']);
            $bankId = $bankCode !== null
                ? Bank::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('country_code', strtoupper($company->country_code))
                    ->where('rib_bank_code', $bankCode)
                    ->value('id')
                : null;

            // Cutover-hardening (Fix 1): repositories are BORN at balance 0 — the
            // direct-balance-write trigger now guards INSERTs too, rejecting a
            // non-zero opening balance minted with no backing movement. Strip the
            // seed's `balance` from the INSERT attributes; the model default (0)
            // satisfies the INSERT guard.
            $openingBalance = $repo['balance'];
            unset($repo['balance']);

            $repositoryId = Str::uuid()->toString();

            // forceCreate: the remaining columns (code/name/type/bank_*) are set;
            // `balance` is port-managed and defaults to 0 on the INSERT.
            PaymentRepository::forceCreate([
                'id' => $repositoryId,
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'account_id' => $glAccountId,
                'gl_account_id' => $glAccountId,
                'bank_id' => is_string($bankId) ? $bankId : null,
                ...$repo,
            ]);

            // A non-zero opening balance is established through the PORT — this
            // sets the cached balance AND lays down the backing `opening_balance`
            // movement so the ledger reconciles (spec §4: opening_balance legs are
            // JE-nullable). Resolving the port via the container is acceptable in a
            // seeder (NOT in app/ prod code, which must constructor-inject).
            if (bccomp($openingBalance, '0', 3) === 1) {
                $this->recordOpeningBalance(
                    repositoryId: $repositoryId,
                    tenantId: $tenant->id,
                    companyId: $company->id,
                    currency: $company->currency,
                    amount: $openingBalance,
                );
            }
        }

        // Null-safe: this seeder is `new`-instantiated (not container-resolved) from
        // TenantInitializationService::seedPaymentRepositories(), so `$command` is
        // null on the live registration path. A hard call threw AFTER
        // seedReferenceData(), and compensate() then dropped the tenant database —
        // which made the country_payment_settings self-healing inert.
        $this->command?->info('Created '.count($repositories).' payment repositories for '.$company->name);
    }

    /**
     * Establish a repository's opening balance via the treasury movement port, so
     * the cached balance is backed by an `opening_balance` movement (spec §4/§5).
     *
     * @param  numeric-string  $amount
     */
    private function recordOpeningBalance(
        string $repositoryId,
        string $tenantId,
        string $companyId,
        string $currency,
        string $amount,
    ): void {
        /** @var TreasuryMovementServiceInterface $port */
        $port = app(TreasuryMovementServiceInterface::class);

        // MED-9: the port requires an owning outer transaction (so its balance
        // write + the `SET LOCAL` GUC are atomic). `sourceId` is the repository id
        // — a stable, per-repository natural key for the single opening leg.
        DB::transaction(fn () => $port->record(new MovementIntent(
            repositoryId: $repositoryId,
            tenantId: $tenantId,
            companyId: $companyId,
            direction: MovementDirection::In,
            amount: $amount,
            currency: $currency,
            sourceType: MovementSourceType::OpeningBalance,
            sourceId: $repositoryId,
            idempotencyLeg: 'opening',
            journalEntryId: null,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: 'Seeded opening balance',
            allowWhileFrozen: false,
        )));
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
     *     bank_code: string|null,
     *     bank_name: string|null,
     *     account_number: string|null,
     *     iban: string|null,
     *     bic: string|null,
     *     balance: numeric-string,
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
                'bank_code' => null,
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
                'bank_code' => null,
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
                'bank_code' => null,
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
                    'bank_code' => '05',
                    'bank_name' => 'BANQUE DE TUNISIE',
                    'account_number' => null,
                    'iban' => null,
                    'bic' => 'BTBKTNTT',
                    'balance' => '25000.000',
                    'is_active' => true,
                ],
                [
                    'code' => 'BANK-02',
                    'name' => 'STB - Business Account',
                    'type' => 'bank_account',
                    'bank_code' => '10',
                    'bank_name' => 'SOCIETE TUNISIENNE DE BANQUE',
                    'account_number' => null,
                    'iban' => null,
                    'bic' => 'STBKTNTT',
                    'balance' => '15000.000',
                    'is_active' => true,
                ],
                [
                    'code' => 'BANK-03',
                    'name' => 'BIAT - Savings Account',
                    'type' => 'bank_account',
                    'bank_code' => '08',
                    'bank_name' => 'BANQUE INTERNATIONALE ARABE DE TUNISIE',
                    'account_number' => null,
                    'iban' => null,
                    'bic' => 'BIATTNTT',
                    'balance' => '10000.000',
                    'is_active' => true,
                ],
                [
                    'code' => 'VIRT-01',
                    'name' => 'D17 Digital Wallet',
                    'type' => 'virtual',
                    'bank_code' => null,
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
                    'bank_code' => null,
                    'bank_name' => 'BNP Paribas',
                    'account_number' => null,
                    'iban' => null,
                    'bic' => 'BNPAFRPP',
                    'balance' => '25000.000',
                    'is_active' => true,
                ],
                [
                    'code' => 'BANK-02',
                    'name' => 'Crédit Agricole - Business Account',
                    'type' => 'bank_account',
                    'bank_code' => null,
                    'bank_name' => 'Crédit Agricole',
                    'account_number' => null,
                    'iban' => null,
                    'bic' => 'AGRIFRPP',
                    'balance' => '15000.000',
                    'is_active' => true,
                ],
                [
                    'code' => 'BANK-03',
                    'name' => 'Société Générale - Savings Account',
                    'type' => 'bank_account',
                    'bank_code' => null,
                    'bank_name' => 'Société Générale',
                    'account_number' => null,
                    'iban' => null,
                    'bic' => 'SOGEFRPP',
                    'balance' => '10000.000',
                    'is_active' => true,
                ],
                [
                    'code' => 'VIRT-01',
                    'name' => 'PayPal Business Account',
                    'type' => 'virtual',
                    'bank_code' => null,
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
