<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Treasury\CompanyPaymentRepositoryProvisionerInterface;
use Illuminate\Console\Command;
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
    private ?Command $outputCommand = null;

    public function __construct(
        private readonly CompanyPaymentRepositoryProvisionerInterface $provisioner,
    ) {}

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
            $this->outputCommand?->error('No tenant or company found. Please run DatabaseSeeder first.');

            return;
        }

        $this->seedRepositoriesForCompany($firstCompany, $tenant);
    }

    /**
     * Seed payment repositories for a specific company.
     */
    private function seedRepositoriesForCompany(Company $company, Tenant $tenant): void
    {
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
        // A repository is BORN at balance 0 — the direct-balance-write trigger
        // (`2026_07_08_160000`) rejects any other opening value, and `balance`
        // is port-managed and not fillable. Nothing here writes it.
        $this->provisioner->provisionForCompany($company->tenant_id, $company->id);

        // Null-safe: this seeder is `new`-instantiated (not container-resolved) from
        // TenantInitializationService::seedPaymentRepositories(), so `$command` is
        // null on the live registration path. A hard call threw AFTER
        // seedReferenceData(), and compensate() then dropped the tenant database —
        // which made the country_payment_settings self-healing inert.
        $this->outputCommand?->info('Created 2 payment repositories for '.$company->name);
    }

    public function setCommand(Command $command): static
    {
        parent::setCommand($command);
        $this->outputCommand = $command;

        return $this;
    }
}
