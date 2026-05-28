<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Services;

use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * T6 Phase 0b — per-tenant infra health snapshot.
 *
 * Lowest-friction monitoring for the launch: combines central directory
 * facts (tenant slug/name, last backup row) with live Postgres
 * introspection (database existence, size, active backend connections) so
 * a human operator (or a scraping cronjob) can answer:
 *
 *   - Does this tenant have a physical database?
 *   - How big is it?
 *   - How many active connections is it sustaining right now?
 *   - When did it last back up successfully? Did the last attempt fail?
 *
 * Surfaces both an artisan command (`tenant:status`) and a JSON endpoint
 * (`GET /api/v1/admin/monitoring/tenants`) — the same snapshot collection
 * either way.
 */
class TenantHealthService
{
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return Collection<int, TenantHealthSnapshot>
     */
    public function snapshot(): Collection
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, Tenant> $tenants */
        $tenants = Tenant::orderBy('slug')->get();

        return $tenants->map(
            fn (Tenant $tenant): TenantHealthSnapshot => $this->snapshotFor($tenant)
        )->values();
    }

    public function snapshotFor(Tenant $tenant): TenantHealthSnapshot
    {
        $databaseName = (string) ($tenant->database()->getName() ?? '');
        $exists = false;
        $size = null;
        $activeConnections = null;

        if ($this->isDbPerTenantMode() && $databaseName !== '') {
            try {
                $exists = $this->databaseExists($databaseName);
                if ($exists) {
                    $size = $this->databaseSizeBytes($databaseName);
                    $activeConnections = $this->activeConnections($databaseName);
                }
            } catch (Throwable $e) {
                $this->logger->warning('Tenant health probe failed', [
                    'tenant_id' => $tenant->id,
                    'database' => $databaseName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        [$lastCompletedAt, $lastStatus, $lastError] = $this->lastBackupFacts($tenant->id);

        return new TenantHealthSnapshot(
            tenantId: $tenant->id,
            tenantSlug: $tenant->slug,
            tenantName: $tenant->name,
            databaseName: $databaseName,
            databaseExists: $exists,
            databaseSizeBytes: $size,
            activeConnections: $activeConnections,
            lastBackupCompletedAt: $lastCompletedAt,
            lastBackupStatus: $lastStatus,
            lastBackupError: $lastError,
        );
    }

    private function databaseExists(string $databaseName): bool
    {
        return $this->db->connection('central')->selectOne(
            'select 1 as ok from pg_database where datname = ?',
            [$databaseName],
        ) !== null;
    }

    private function databaseSizeBytes(string $databaseName): ?int
    {
        $row = $this->db->connection('central')->selectOne(
            'select pg_database_size(?) as size',
            [$databaseName],
        );
        if ($row === null || ! isset($row->size)) {
            return null;
        }

        return (int) $row->size;
    }

    private function activeConnections(string $databaseName): ?int
    {
        $row = $this->db->connection('central')->selectOne(
            'select numbackends from pg_stat_database where datname = ?',
            [$databaseName],
        );
        if ($row === null || ! isset($row->numbackends)) {
            return null;
        }

        return (int) $row->numbackends;
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function lastBackupFacts(string $tenantId): array
    {
        $row = $this->db->connection('central')->table('tenant_backups')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('started_at')
            ->first(['status', 'completed_at', 'started_at', 'error_message']);

        if ($row === null) {
            return [null, null, null];
        }

        $status = is_string($row->status) ? $row->status : null;
        $completedAt = $this->stringify($row->completed_at);
        $startedAt = $this->stringify($row->started_at);
        $error = is_string($row->error_message) ? $row->error_message : null;

        return [
            $status === 'completed' ? $completedAt : $startedAt,
            $status,
            $status === 'failed' ? $error : null,
        ];
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return (string) $value;
    }

    private function isDbPerTenantMode(): bool
    {
        return (bool) $this->config->get('tenancy_resolver.db_per_tenant', false);
    }
}
