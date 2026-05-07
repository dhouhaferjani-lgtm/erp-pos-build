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
                            {--currency=EUR : ISO 4217 currency code}';

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
}
