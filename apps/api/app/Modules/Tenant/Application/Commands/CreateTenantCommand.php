<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Commands;

use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * @cross-tenant-by-design Tenant-lifecycle administrative command that creates a new tenant; operates without a bound CompanyContext because the tenant does not yet exist when the command starts.
 */
class CreateTenantCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:create
                            {name : The name of the tenant/business}
                            {--slug= : URL-friendly identifier (auto-generated if not provided)}
                            {--domain= : Primary domain for the tenant}
                            {--plan=trial : Subscription plan (trial, starter, professional, enterprise)}
                            {--country= : ISO 3166-1 alpha-2 country code}
                            {--currency=EUR : ISO 4217 currency code}
                            {--central-row-only : Acknowledge that this writes ONLY the central directory rows (no tenant database, no migrations, no initialization). Required under database-per-tenant.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new tenant for AutoERP';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->guardAgainstBrickingTheTenant()) {
            return self::FAILURE;
        }

        /** @var string $name */
        $name = $this->argument('name');

        /** @var string|null $slugOption */
        $slugOption = $this->option('slug');
        $slug = $slugOption !== null && $slugOption !== '' ? $slugOption : Str::slug($name);

        // Check if slug already exists
        if (Tenant::where('slug', $slug)->exists()) {
            $this->error("A tenant with slug '{$slug}' already exists.");

            return self::FAILURE;
        }

        $planOption = $this->option('plan');
        $plan = match ($planOption) {
            'trial' => SubscriptionPlan::Trial,
            'starter' => SubscriptionPlan::Starter,
            'professional' => SubscriptionPlan::Professional,
            'enterprise' => SubscriptionPlan::Enterprise,
            default => SubscriptionPlan::Trial,
        };

        $countryCode = $this->option('country');
        $currencyCode = $this->option('currency');

        $tenant = Tenant::create([
            'name' => $name,
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => $plan,
            'country_code' => is_string($countryCode) ? strtoupper($countryCode) : null,
            'currency_code' => is_string($currencyCode) ? strtoupper($currencyCode) : 'EUR',
            'settings' => [
                'timezone' => 'UTC',
                'locale' => 'en',
                'date_format' => 'Y-m-d',
                'fiscal_year_start' => '01-01',
            ],
            'trial_ends_at' => $plan === SubscriptionPlan::Trial ? now()->addDays(14) : null,
            'subscription_ends_at' => $plan !== SubscriptionPlan::Trial ? now()->addYear() : null,
        ]);

        // Create domain if provided
        $domain = $this->option('domain');
        if (is_string($domain) && $domain !== '') {
            $tenant->domains()->create([
                'domain' => $domain,
                'is_primary' => true,
                'is_verified' => false,
            ]);
            $this->info("Created domain: {$domain}");
        }

        $this->info('Tenant created successfully!');
        $this->table(
            ['Property', 'Value'],
            [
                ['ID', $tenant->id],
                ['Name', $tenant->name],
                ['Slug', $tenant->slug],
                ['Status', $tenant->status->value],
                ['Plan', $tenant->plan->value],
                ['Country', $tenant->country_code ?? 'Not set'],
                ['Currency', $tenant->currency_code ?? 'Not set'],
                ['Database Schema', $tenant->getDatabaseName()],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Register G-1 — fail closed instead of producing a permanently broken tenant.
     *
     * This command writes ONLY the central `tenants` (+ optional `domains`) rows.
     * That is the complete tenant in the shared-DB compat mode, but under
     * database-per-tenant the per-tenant database is never created, never
     * migrated, and TenantInitializationService never runs. TenancyResolver::
     * initializeIfProvisioned() then fails closed on every request for that
     * tenant (TenantUnavailableException -> 503), forever — and nothing at
     * creation time said so. An operator reaching for the obvious command
     * bricked the tenant silently.
     *
     * So: refuse under database-per-tenant and name the working path, unless the
     * operator explicitly asked for the central rows alone (repairing a directory
     * entry for an already-provisioned database), which is announced as partial.
     *
     * Building real provisioning into this command is deliberately NOT done here
     * — that is an open owner decision, and a half-provisioning command would
     * reintroduce the same silent trap in a new shape.
     *
     * @return bool false when the command must abort
     */
    private function guardAgainstBrickingTheTenant(): bool
    {
        $centralRowOnly = (bool) $this->option('central-row-only');
        $dbPerTenant = (bool) config('tenancy_resolver.db_per_tenant', false);

        if ($dbPerTenant && ! $centralRowOnly) {
            $this->error('Refusing to create a tenant: database-per-tenant mode is ON (tenancy_resolver.db_per_tenant=true).');
            $this->line('');
            $this->line('This command writes only the central tenants/domains rows. It does not create');
            $this->line('the per-tenant database, does not migrate it, and does not run');
            $this->line('TenantInitializationService — so the tenant would be permanently unusable:');
            $this->line('every request for it fails closed with a 503 (TenantUnavailableException).');
            $this->line('');
            $this->line('Use the working path instead — the self-service signup form at /register.');
            $this->line('API route: POST /api/v1/auth/register');
            $this->line('It runs TenantProvisioningService, which creates and migrates the tenant');
            $this->line('database, then initializes roles, reference data, chart of accounts, tax,');
            $this->line('and payment configuration.');
            $this->line('');
            $this->line('If you genuinely want the central directory row alone (for example repairing');
            $this->line('a directory entry for a database that is already provisioned), re-run with');
            $this->line('--central-row-only.');

            return false;
        }

        if ($centralRowOnly) {
            $this->warn('--central-row-only: writing the central tenants/domains rows only.');
            $this->warn('No tenant database is created or migrated and no tenant initialization runs.');
            $this->warn('Under database-per-tenant this tenant returns 503 until its database is provisioned separately.');
        }

        return true;
    }
}
