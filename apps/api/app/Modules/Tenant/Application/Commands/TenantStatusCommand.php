<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Commands;

use App\Modules\Tenant\Application\Services\TenantHealthService;
use App\Modules\Tenant\Application\Services\TenantHealthSnapshot;
use Illuminate\Console\Command;

/**
 * @cross-tenant-by-design Operational read-only command that introspects every tenant's per-tenant database (existence, size, active connections) + last backup status against the central directory without a bound CompanyContext (master plan §14 cat-(b)).
 */
class TenantStatusCommand extends Command
{
    protected $signature = 'tenant:status';

    protected $description = 'Print a per-tenant infra health table (database exists, size, active connections, last backup)';

    public function handle(TenantHealthService $service): int
    {
        $snapshots = $service->snapshot();

        if ($snapshots->isEmpty()) {
            $this->info('No tenants found.');

            return self::SUCCESS;
        }

        $rows = $snapshots->map(fn (TenantHealthSnapshot $s): array => [
            $s->tenantSlug,
            $s->databaseExists ? 'yes' : 'no',
            $this->formatBytes($s->databaseSizeBytes),
            $s->activeConnections === null ? '—' : (string) $s->activeConnections,
            $s->lastBackupStatus ?? '—',
            $s->lastBackupCompletedAt ?? '—',
        ])->all();

        $this->table(
            ['slug', 'db?', 'size', 'conns', 'backup', 'when'],
            $rows,
        );

        return self::SUCCESS;
    }

    private function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }
}
