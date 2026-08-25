<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
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
     * Locales that ship a `lang/<locale>/treasury.php` file. Kept in step with
     * the SetLocale middleware's supported list; an unknown company locale
     * degrades to `FALLBACK_LOCALE` rather than persisting a raw translation key
     * as a repository name.
     *
     * @var list<string>
     */
    private const TRANSLATED_LOCALES = ['en', 'fr', 'ar'];

    /**
     * Gate finding M-1: deliberately NOT `config('app.locale')`. That value is
     * request-mutable — `Application::setLocale()` writes it and the SetLocale
     * middleware calls it on every request — so reading it here would persist a
     * repository name derived from whatever `Accept-Language` the registering
     * HTTP request happened to carry. A provisioning default must not depend on
     * the shape of one request.
     */
    private const FALLBACK_LOCALE = 'en';

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

        // Campaign lane N-12 — attribute the day-one repositories to the
        // company's own POS location instead of leaving `location_id = NULL`.
        //
        // The wave-1 finding was cosmetic ("Cash across stores" filed everything
        // under *Unattributed*); the wave-4 re-runs measured the real cost once a
        // second branch existed — the Boutique Ariana terminal's 200.000 TND cash
        // sale landed in `CASH-01`, the Main location's drawer, because the tender
        // resolver had no location axis and broke ties on the stable UUID.
        //
        // Attribution is what arms the resolver's tier 1 for this tenant, so the
        // SECOND location provisioned (which gets its own drawer via
        // `LocationCashRegisterProvisioner`) can never silently borrow this one.
        // Null-safe by design: the location table may legitimately be empty at
        // this point in the provisioning order, and a NULL here is still the
        // resolver's tier 2 — i.e. exactly today's behaviour, never a failure.
        $locationId = $this->defaultLocationId($company);

        foreach ($this->defaultRepositories($company) as $repo) {
            // A repository is BORN at balance 0 — the direct-balance-write
            // trigger (`2026_07_08_160000`) rejects any other opening value, and
            // `balance` is port-managed and not fillable. Nothing here writes it.
            PaymentRepository::forceCreate([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'account_id' => $cashAccount?->id,
                'gl_account_id' => $cashAccount?->id,
                'location_id' => $locationId,
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
     * N-12 — the location these day-one repositories belong to.
     *
     * The default location first (`is_default`), then any POS-enabled one, then
     * nothing. `TenantProvisioningService` creates a `type=shop`, POS-enabled
     * Main Location, so the ordinary registration path resolves it; a seeder run
     * against a company that has no locations yet returns null and the
     * repositories stay unattributed, which is the pre-N-12 shape and still
     * fully served by the resolver's tier 2.
     */
    private function defaultLocationId(Company $company): ?string
    {
        $locationId = Location::query()
            ->where('company_id', $company->id)
            ->orderByDesc('is_default')
            ->orderByDesc('pos_enabled')
            ->orderBy('created_at')
            ->value('id');

        return is_string($locationId) ? $locationId : null;
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
     * code, falling back to a FIXED default when the tenant asked for a language
     * this build has no `lang/` directory for.
     */
    private function resolveLocale(Company $company): string
    {
        $language = strtolower(explode('-', str_replace('_', '-', $company->locale))[0]);

        return in_array($language, self::TRANSLATED_LOCALES, true)
            ? $language
            : self::FALLBACK_LOCALE;
    }
}
