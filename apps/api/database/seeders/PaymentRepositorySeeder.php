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

/**
 * Provision the treasury a brand-new tenant is born with.
 *
 * THIS IS THE LIVE REGISTRATION PATH — `TenantInitializationService
 * ::seedPaymentRepositories()` `new`-instantiates this seeder for every tenant
 * that signs up, and the `2026_03_24_200000` backfill migration re-uses it for
 * legacy tenants that ended up with zero repositories.
 *
 * DPA lane H-3 (owner ruling 2026-08-09 — "no hard data anywhere … a new
 * customer needs a clean setup"): a fresh tenant gets exactly TWO repositories —
 * one cash register and one safe — both at a ZERO balance, with no bank
 * identity and no movements. It used to mint three cash tills carrying 500.000 /
 * 200.000 / 5000.000 plus named third-party bank accounts (Banque de Tunisie,
 * STB, BIAT, D17 / BNP Paribas, Crédit Agricole, Société Générale, PayPal) with
 * a further 52 000 of fabricated cash, and it pushed every one of those balances
 * through the treasury movement port as a REAL `opening_balance` movement. The
 * tenant has no relationship with those banks and never received that money.
 *
 * Repository *shapes* are legitimate provisioning; balances are not. Money
 * enters through the opening-balance document lane
 * (`AccountingOpeningService`), which is the only flow that produces a
 * justifying document — never through provisioning.
 *
 * Demo/dev fixtures that still want a rich treasury call
 * {@see DemoPaymentRepositorySeeder} on top of this one. That seeder is NEVER
 * reachable from registration.
 */
class PaymentRepositorySeeder extends Seeder
{
    /**
     * Locales that ship a `lang/<locale>/treasury.php` file — the SetLocale
     * middleware's supported list minus the locales with no translation
     * directory, so an unknown company locale degrades to the application
     * default rather than emitting a raw translation key as a repository name.
     *
     * @var list<string>
     */
    private const TRANSLATED_LOCALES = ['en', 'fr'];

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
        // Both seeded types (cash register, safe) are cash on the balance sheet,
        // so a single purpose lookup covers them. Resolution is purpose-based —
        // never by literal account code.
        $cashAccount = Account::findByPurpose($company->id, SystemAccountPurpose::Cash);

        if ($cashAccount === null) {
            $this->command?->warn(
                "Cash GL account not found for {$company->name}. Payment repositories will be created without GL links. "
                .'Run ChartOfAccountsSeeder first, then re-run this seeder.'
            );
        }

        foreach ($this->defaultRepositories($company) as $repo) {
            // A repository is BORN at balance 0 — the direct-balance-write
            // trigger (`2026_07_08_160000`) rejects any other opening value, and
            // `balance` is port-managed and not fillable. Nothing here writes it.
            PaymentRepository::forceCreate([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'account_id' => $cashAccount?->id,
                'gl_account_id' => $cashAccount?->id,
                ...$repo,
            ]);
        }

        // Null-safe: this seeder is `new`-instantiated (not container-resolved) from
        // TenantInitializationService::seedPaymentRepositories(), so `$command` is
        // null on the live registration path. A hard call threw AFTER
        // seedReferenceData(), and compensate() then dropped the tenant database —
        // which made the country_payment_settings self-healing inert.
        $this->command?->info('Created 2 payment repositories for '.$company->name);
    }

    /**
     * The two repositories every tenant starts with.
     *
     * No country axis: a cash register and a safe are universal, and the only
     * country-dependent thing the old implementation carried (bank identities)
     * was fabricated. Labels come from `lang/<locale>/treasury.php` under the
     * registering company's locale, so a French-speaking tenant is not handed
     * English defaults.
     *
     * @return list<array{code: string, name: string, type: string, is_active: bool}>
     */
    private function defaultRepositories(Company $company): array
    {
        $locale = $this->resolveLocale($company);

        return [
            [
                'code' => 'CASH-01',
                'name' => trans('treasury.default_repositories.cash_register', [], $locale),
                'type' => RepositoryType::CashRegister->value,
                'is_active' => true,
            ],
            [
                'code' => 'SAFE-01',
                'name' => trans('treasury.default_repositories.safe', [], $locale),
                'type' => RepositoryType::Safe->value,
                'is_active' => true,
            ],
        ];
    }

    /**
     * Reduce a company locale (`fr_TN`, `fr-FR`, `en`) to a translated language
     * code, falling back to the application default when the tenant asked for a
     * language this build has no `lang/` directory for.
     */
    private function resolveLocale(Company $company): string
    {
        $language = strtolower(explode('-', str_replace('_', '-', $company->locale))[0]);

        if (in_array($language, self::TRANSLATED_LOCALES, true)) {
            return $language;
        }

        $fallback = strtolower((string) config('app.locale', 'en'));

        return in_array($fallback, self::TRANSLATED_LOCALES, true) ? $fallback : 'en';
    }
}
