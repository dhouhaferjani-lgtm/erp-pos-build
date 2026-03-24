<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Commands;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\TenantInitializationService;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class ResetTenantCommand extends Command
{
    protected $signature = 'tenant:reset
                            {slug : The tenant slug to reset}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Drop and re-initialize a tenant schema (destroys all tenant data)';

    public function handle(TenantInitializationService $initService): int
    {
        /** @var string $slug */
        $slug = $this->argument('slug');

        $tenant = Tenant::where('slug', $slug)->first();

        if ($tenant === null) {
            $this->error("Tenant with slug '{$slug}' not found.");
            $this->newLine();
            $this->info('Available tenants:');
            /** @var Tenant $t */
            foreach (Tenant::all() as $t) {
                $this->line("  - {$t->slug} ({$t->name})");
            }

            return self::FAILURE;
        }

        $schemaName = $tenant->getDatabaseName();

        $this->warn("This will permanently destroy ALL data for tenant '{$tenant->name}' (schema: {$schemaName}).");
        $this->warn('This includes: transactions, receipts, documents, journal entries, hash chains, etc.');

        if (! $this->option('force') && ! $this->confirm('Are you sure you want to continue?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        // Step 1: Drop the tenant schema
        $this->info("[1/4] Dropping schema '{$schemaName}'...");
        DB::statement("DROP SCHEMA IF EXISTS \"{$schemaName}\" CASCADE");

        // Step 2: Recreate the schema
        $this->info("[2/4] Creating schema '{$schemaName}'...");
        DB::statement("CREATE SCHEMA \"{$schemaName}\"");

        // Step 3: Run migrations within tenant context
        $this->info('[3/4] Running migrations...');
        tenancy()->initialize($tenant);

        try {
            Artisan::call('migrate', [
                '--force' => true,
                '--path' => 'database/migrations',
                '--realpath' => false,
            ]);

            $this->line(Artisan::output());

            // Step 4: Re-initialize tenant data
            $this->info('[4/4] Seeding tenant data...');

            // Re-assign admin role to all tenant users (once, outside company loop)
            $users = User::where('tenant_id', $tenant->id)->get();
            setPermissionsTeamId($tenant->id);
            foreach ($users as $user) {
                $user->assignRole('admin');
                $this->line("  Assigned admin role to: {$user->email}");
            }

            /** @var \Illuminate\Database\Eloquent\Collection<int, Company> $companies */
            $companies = $tenant->companies;

            if ($companies->isEmpty()) {
                $this->warn('No companies found for this tenant. Skipping company seeding.');
                $this->warn('The schema has been reset but no chart of accounts or payment data was seeded.');
            } else {
                /** @var User $firstUser */
                $firstUser = $users->firstOrFail();

                foreach ($companies as $company) {
                    $this->line("  Seeding company: {$company->name} ({$company->country_code})");
                    $initService->initializeForNewRegistration($tenant, $company, $firstUser);
                    $this->line("    Seeded: chart of accounts, tax config, payment methods, payment repos");
                }
            }
        } catch (\Throwable $e) {
            $this->error("Reset failed: {$e->getMessage()}");
            $this->error("Schema '{$schemaName}' may be in a partial state. Re-run this command to retry.");

            return self::FAILURE;
        } finally {
            tenancy()->end();
        }

        $this->newLine();
        $this->info("Tenant '{$tenant->name}' has been reset successfully.");
        $this->info('The hash chains will start fresh from the first transaction.');

        return self::SUCCESS;
    }
}
