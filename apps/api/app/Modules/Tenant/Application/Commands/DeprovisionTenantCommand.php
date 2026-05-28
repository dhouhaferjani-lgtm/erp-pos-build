<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Commands;

use App\Modules\Tenant\Application\Services\TenantDeprovisioningService;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Console\Command;

/**
 * @cross-tenant-by-design Tenant-lifecycle administrative command that tears down (drops the per-tenant database + removes central directory rows) or reversibly suspends a tenant by slug; operates on the central tenant directory without a bound CompanyContext — symmetric with tenant:create / tenant:reset (master plan §14 cat-(b)).
 */
class DeprovisionTenantCommand extends Command
{
    protected $signature = 'tenant:deprovision
                            {slug : The tenant slug to deprovision or suspend}
                            {--suspend : Reversibly suspend the tenant (keeps its database) instead of deleting it}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Tear down a tenant (drop its database + remove central rows) or reversibly suspend it';

    public function handle(TenantDeprovisioningService $service): int
    {
        /** @var string $slug */
        $slug = $this->argument('slug');

        $tenant = Tenant::where('slug', $slug)->first();

        if ($tenant === null) {
            $this->error("Tenant with slug '{$slug}' not found.");

            return self::FAILURE;
        }

        $suspend = (bool) $this->option('suspend');

        if ($suspend) {
            return $this->runSuspend($service, $tenant);
        }

        return $this->runDeprovision($service, $tenant);
    }

    private function runSuspend(TenantDeprovisioningService $service, Tenant $tenant): int
    {
        $this->info("Suspending tenant '{$tenant->name}' (slug: {$tenant->slug}).");
        $this->line('Suspend is reversible: the tenant database is preserved and access is revoked until reactivated.');

        if (! $this->confirmDestructive("Suspend tenant '{$tenant->name}'?")) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $service->suspend($tenant);

        $this->info("Tenant '{$tenant->name}' is now suspended.");

        return self::SUCCESS;
    }

    private function runDeprovision(TenantDeprovisioningService $service, Tenant $tenant): int
    {
        $databaseName = $tenant->getDatabaseName();

        $this->warn("This will PERMANENTLY tear down tenant '{$tenant->name}' (slug: {$tenant->slug}).");
        $this->warn("In database-per-tenant mode this DROPS the physical database '{$databaseName}' and ALL its data");
        $this->warn('(receipts, documents, journal entries, hash chains, etc.) and removes the central directory rows.');
        $this->warn('This is NOT reversible. To pause access instead, use --suspend.');

        if (! $this->confirmDestructive("Permanently deprovision tenant '{$tenant->name}'?")) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $service->deprovision($tenant);

        $this->info("Tenant '{$tenant->name}' has been deprovisioned.");

        return self::SUCCESS;
    }

    private function confirmDestructive(string $question): bool
    {
        if ((bool) $this->option('force')) {
            return true;
        }

        return $this->confirm($question);
    }
}
