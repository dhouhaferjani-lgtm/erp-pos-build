<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Commands;

use App\Modules\Tenant\Application\Services\TenantBackupService;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * @cross-tenant-by-design Tenant-lifecycle administrative command that runs pg_dump for one tenant or every tenant against the central directory without a bound CompanyContext; symmetric with tenant:deprovision (master plan §14 cat-(b)).
 */
class BackupTenantCommand extends Command
{
    protected $signature = 'tenant:backup
                            {slug? : The tenant slug to back up (omit when using --all)}
                            {--all : Back up every tenant in the central directory}';

    protected $description = 'Run pg_dump against the per-tenant database(s) and record metadata in central tenant_backups';

    public function handle(TenantBackupService $service): int
    {
        $all = (bool) $this->option('all');
        $slug = $this->argument('slug');

        if ($all && is_string($slug) && $slug !== '') {
            $this->error('Pass either a slug OR --all, not both.');

            return self::FAILURE;
        }

        if (! $all && (! is_string($slug) || $slug === '')) {
            $this->error('Pass a tenant slug, or use --all to back up every tenant.');

            return self::FAILURE;
        }

        /** @var Collection<int, Tenant> $tenants */
        $tenants = $all
            ? Tenant::orderBy('slug')->get()
            : Tenant::where('slug', $slug)->get();

        if ($tenants->isEmpty()) {
            if ($all) {
                $this->info('No tenants found.');

                return self::SUCCESS;
            }
            $this->error("Tenant with slug '{$slug}' not found.");

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($tenants as $tenant) {
            $this->line("Backing up '{$tenant->name}' (slug: {$tenant->slug})...");
            try {
                $result = $service->backup($tenant);
                $this->info(sprintf(
                    '  ok — %s (%d bytes, sha256=%s)',
                    $result->filePath,
                    $result->fileSizeBytes,
                    substr($result->sha256, 0, 12).'…',
                ));
            } catch (Throwable $e) {
                $failed++;
                $this->error("  failed — {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
