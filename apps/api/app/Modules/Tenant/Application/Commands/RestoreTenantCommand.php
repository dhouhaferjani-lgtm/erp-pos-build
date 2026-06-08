<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Commands;

use App\Modules\Tenant\Application\Services\TenantBackupService;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Console\Command;

/**
 * @cross-tenant-by-design Tenant-lifecycle administrative command that drops + recreates one tenant's per-tenant database and pg_restores from a backup file, against the central directory without a bound CompanyContext; symmetric with tenant:backup (master plan §14 cat-(b)).
 */
class RestoreTenantCommand extends Command
{
    protected $signature = 'tenant:restore
                            {slug : The tenant slug to restore}
                            {file : Path to the pg_dump custom-format backup file (.dump)}
                            {--force : Skip the destructive-action confirmation prompt}';

    protected $description = 'Drop + recreate a tenant database and pg_restore from a backup file (destructive)';

    public function handle(TenantBackupService $service): int
    {
        /** @var string $slug */
        $slug = $this->argument('slug');
        /** @var string $file */
        $file = $this->argument('file');

        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("Tenant with slug '{$slug}' not found.");

            return self::FAILURE;
        }

        $this->warn("This will DROP tenant '{$tenant->name}' (slug: {$tenant->slug}) and pg_restore from:");
        $this->warn("  {$file}");
        $this->warn('All data not in the dump will be lost. This is NOT reversible.');

        if (! (bool) $this->option('force')) {
            if (! $this->confirm("Restore tenant '{$tenant->name}' from this backup?")) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        $service->restore($tenant, $file, force: true);

        $this->info("Tenant '{$tenant->name}' has been restored from {$file}.");

        return self::SUCCESS;
    }
}
