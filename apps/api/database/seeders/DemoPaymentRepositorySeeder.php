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

/**
 * DEMO/DEV ONLY — a rich, funded treasury for demonstration tenants.
 *
 * DPA lane H-3 stripped fabricated banks and fabricated opening cash out of
 * {@see PaymentRepositorySeeder}, which is the LIVE registration path. Demo
 * fixtures still want a showable treasury: named bank accounts, a wallet, and
 * enough cash for the demo expense/payment stories to settle. That data lives
 * here, reachable ONLY from demo/dev seeders.
 *
 * Hard rule: `TenantInitializationService` must never reference this class. A
 * real tenant's opening balances come from the opening-balance document lane.
 *
 * Runs ON TOP of {@see PaymentRepositorySeeder} — it assumes CASH-01 and
 * SAFE-01 already exist, funds them, and adds the bank/virtual repositories.
 * Idempotent: a repository that already exists is left alone, and each opening
 * balance is keyed on the repository id so re-running lays down no second leg.
 */
class DemoPaymentRepositorySeeder extends Seeder
{
    /**
     * Opening cash for the two repositories the real registration path creates.
     *
     * @var array<string, numeric-string>
     */
    private const OPENING_BALANCES = [
        'CASH-01' => '500.000',
        'SAFE-01' => '5000.000',
    ];

    public function run(?Company $company = null): void
    {
        $company ??= Company::first();

        if (! $company instanceof Company) {
            $this->command?->error('No company found. Please run DatabaseSeeder first.');

            return;
        }

        $tenant = Tenant::find($company->tenant_id);
        if ($tenant === null) {
            return;
        }

        // Bank + wallet repositories post to the Bank system account, exactly as
        // the old provisioning seeder did (purpose-based, never by code).
        $bankAccount = Account::findByPurpose($company->id, SystemAccountPurpose::Bank);

        foreach ($this->bankRepositories($company->country_code) as $repo) {
            $this->createBankRepository($company, $tenant, $repo, $bankAccount?->id);
        }

        foreach (self::OPENING_BALANCES as $code => $amount) {
            $repository = PaymentRepository::query()
                ->where('company_id', $company->id)
                ->where('code', $code)
                ->first();

            if ($repository instanceof PaymentRepository && bccomp($repository->balance, '0', 3) === 0) {
                $this->recordOpeningBalance($repository, $company->currency, $amount);
            }
        }

        $this->command?->info('Seeded demo treasury balances for '.$company->name);
    }

    /**
     * @param  array{code: string, name: string, type: string, bank_code: string|null, bank_name: string|null, account_number: string|null, bic: string|null, balance: numeric-string}  $repo
     */
    private function createBankRepository(Company $company, Tenant $tenant, array $repo, ?string $glAccountId): void
    {
        if (PaymentRepository::query()->where('company_id', $company->id)->where('code', $repo['code'])->exists()) {
            return;
        }

        $bankId = $repo['bank_code'] !== null
            ? Bank::query()
                ->where('tenant_id', $tenant->id)
                ->where('country_code', strtoupper($company->country_code))
                ->where('rib_bank_code', $repo['bank_code'])
                ->value('id')
            : null;

        $repositoryId = Str::uuid()->toString();

        PaymentRepository::forceCreate([
            'id' => $repositoryId,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'account_id' => $glAccountId,
            'gl_account_id' => $glAccountId,
            'bank_id' => is_string($bankId) ? $bankId : null,
            'code' => $repo['code'],
            'name' => $repo['name'],
            'type' => $repo['type'],
            'bank_name' => $repo['bank_name'],
            'account_number' => $repo['account_number'],
            'bic' => $repo['bic'],
            'is_active' => true,
        ]);

        $created = PaymentRepository::query()->findOrFail($repositoryId);
        $this->recordOpeningBalance($created, $company->currency, $repo['balance']);
    }

    /**
     * Establish a demo opening balance through the treasury movement port, so
     * the cached balance stays backed by an `opening_balance` movement and the
     * direct-balance-write trigger is never fought.
     *
     * @param  numeric-string  $amount
     */
    private function recordOpeningBalance(PaymentRepository $repository, string $currency, string $amount): void
    {
        if (bccomp($amount, '0', 3) !== 1) {
            return;
        }

        /** @var TreasuryMovementServiceInterface $port */
        $port = app(TreasuryMovementServiceInterface::class);

        // The port requires an owning outer transaction (so its balance write +
        // the `SET LOCAL` GUC are atomic). `sourceId` is the repository id — a
        // stable, per-repository natural key for the single opening leg.
        // Resolving the port via the container is acceptable in a seeder (NOT in
        // app/ prod code, which must constructor-inject).
        DB::transaction(fn () => $port->record(new MovementIntent(
            repositoryId: $repository->id,
            tenantId: $repository->tenant_id,
            companyId: $repository->company_id,
            direction: MovementDirection::In,
            amount: $amount,
            currency: $currency,
            sourceType: MovementSourceType::OpeningBalance,
            sourceId: $repository->id,
            idempotencyLeg: 'opening',
            journalEntryId: null,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: 'Demo opening balance',
            allowWhileFrozen: false,
        )));
    }

    /**
     * Demo bank/wallet repositories per country. Fictional-by-convention demo
     * data — never provisioned for a real tenant.
     *
     * @return list<array{code: string, name: string, type: string, bank_code: string|null, bank_name: string|null, account_number: string|null, bic: string|null, balance: numeric-string}>
     */
    private function bankRepositories(string $countryCode): array
    {
        return match (strtoupper($countryCode)) {
            'TN' => [
                [
                    'code' => 'BANK-01',
                    'name' => 'Banque de Tunisie - Current Account',
                    'type' => RepositoryType::BankAccount->value,
                    'bank_code' => '05',
                    'bank_name' => 'BANQUE DE TUNISIE',
                    'account_number' => null,
                    'bic' => 'BTBKTNTT',
                    'balance' => '25000.000',
                ],
                [
                    'code' => 'BANK-02',
                    'name' => 'STB - Business Account',
                    'type' => RepositoryType::BankAccount->value,
                    'bank_code' => '10',
                    'bank_name' => 'SOCIETE TUNISIENNE DE BANQUE',
                    'account_number' => null,
                    'bic' => 'STBKTNTT',
                    'balance' => '15000.000',
                ],
                [
                    'code' => 'VIRT-01',
                    'name' => 'D17 Digital Wallet',
                    'type' => RepositoryType::Virtual->value,
                    'bank_code' => null,
                    'bank_name' => 'D17',
                    'account_number' => 'business@example.tn',
                    'bic' => null,
                    'balance' => '2000.000',
                ],
            ],
            'FR' => [
                [
                    'code' => 'BANK-01',
                    'name' => 'BNP Paribas - Current Account',
                    'type' => RepositoryType::BankAccount->value,
                    'bank_code' => null,
                    'bank_name' => 'BNP Paribas',
                    'account_number' => null,
                    'bic' => 'BNPAFRPP',
                    'balance' => '25000.000',
                ],
                [
                    'code' => 'BANK-02',
                    'name' => 'Crédit Agricole - Business Account',
                    'type' => RepositoryType::BankAccount->value,
                    'bank_code' => null,
                    'bank_name' => 'Crédit Agricole',
                    'account_number' => null,
                    'bic' => 'AGRIFRPP',
                    'balance' => '15000.000',
                ],
                [
                    'code' => 'VIRT-01',
                    'name' => 'PayPal Business Account',
                    'type' => RepositoryType::Virtual->value,
                    'bank_code' => null,
                    'bank_name' => 'PayPal',
                    'account_number' => 'business@example.com',
                    'bic' => null,
                    'balance' => '3500.000',
                ],
            ],
            default => [],
        };
    }
}
