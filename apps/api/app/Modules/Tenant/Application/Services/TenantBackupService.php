<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Services;

use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * T6 Phase 0b — per-tenant logical backup (pg_dump custom format).
 *
 * Produces a self-contained binary dump of one tenant's database, suitable for
 * pg_restore back into the same or a fresh database. Local-disk only for the
 * launch-day baseline (storage/app/tenant-backups/<tenant-uuid>/<ts>.dump);
 * S3/object-store mirroring is a future iteration.
 *
 * Gating: backups only run in DB-per-tenant mode (tenancy_resolver.db_per_tenant
 * true). In shared-DB compat mode there is no per-tenant database to dump.
 *
 * Metadata for every attempt — started_at, completed_at, status,
 * file_size_bytes, sha256, error_message — is persisted to the central
 * `tenant_backups` table so monitoring can answer "when did this tenant last
 * back up successfully?" without filesystem inspection.
 */
class TenantBackupService
{
    public function __construct(
        private readonly Repository $config,
        private readonly ConnectionResolverInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    public function backup(Tenant $tenant): TenantBackupResult
    {
        if (! $this->isDbPerTenantMode()) {
            throw new RuntimeException(
                'Tenant backups require database-per-tenant mode (TENANCY_DB_PER_TENANT=true).'
            );
        }

        $backupId = (string) Str::uuid();
        $startedAt = Carbon::now();
        $databaseName = $tenant->database()->getName();
        if ($databaseName === null || $databaseName === '') {
            throw new RuntimeException(
                "Tenant {$tenant->id} has no resolvable database name; refusing to back up."
            );
        }
        $directory = $this->backupDirectory($tenant->id);
        $this->ensureDirectory($directory);
        $filename = $startedAt->format('Ymd-His').'.dump';
        $filePath = $directory.DIRECTORY_SEPARATOR.$filename;

        $this->db->connection('central')->table('tenant_backups')->insert([
            'id' => $backupId,
            'tenant_id' => $tenant->id,
            'status' => 'in_progress',
            'started_at' => $startedAt,
            'created_at' => $startedAt,
            'updated_at' => $startedAt,
        ]);

        try {
            $this->runPgDump($databaseName, $filePath);

            $size = filesize($filePath);
            if ($size === false) {
                throw new RuntimeException("Backup file was not created: {$filePath}");
            }
            $sha256 = hash_file('sha256', $filePath);
            if ($sha256 === false) {
                throw new RuntimeException("Failed to hash backup file: {$filePath}");
            }
            $completedAt = Carbon::now();

            $this->db->connection('central')->table('tenant_backups')
                ->where('id', $backupId)
                ->update([
                    'status' => 'completed',
                    'file_path' => $filePath,
                    'file_size_bytes' => $size,
                    'sha256' => $sha256,
                    'completed_at' => $completedAt,
                    'updated_at' => $completedAt,
                ]);

            $this->logger->info('Tenant backup completed', [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'file_path' => $filePath,
                'size_bytes' => $size,
                'sha256' => $sha256,
            ]);

            $this->applyRetention($tenant->id);

            return new TenantBackupResult(
                tenantBackupId: $backupId,
                tenantId: $tenant->id,
                filePath: $filePath,
                fileSizeBytes: $size,
                sha256: $sha256,
            );
        } catch (Throwable $e) {
            $failedAt = Carbon::now();
            $this->db->connection('central')->table('tenant_backups')
                ->where('id', $backupId)
                ->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'completed_at' => $failedAt,
                    'updated_at' => $failedAt,
                ]);

            if (is_file($filePath)) {
                @unlink($filePath);
            }

            $this->logger->error('Tenant backup failed', [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function runPgDump(string $databaseName, string $outputPath): void
    {
        $cfg = $this->pgConfig();

        $process = new Process([
            'pg_dump',
            '--no-owner',
            '--no-acl',
            '--format=custom',
            '--file='.$outputPath,
            '--host='.$cfg['host'],
            '--port='.(string) $cfg['port'],
            '--username='.$cfg['username'],
            '--dbname='.$databaseName,
        ]);
        if ($cfg['password'] !== '') {
            $process->setEnv(['PGPASSWORD' => $cfg['password']]);
        }
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());
            $stdout = trim($process->getOutput());
            $suffix = $stdout !== '' ? "\n".$stdout : '';
            throw new RuntimeException(
                sprintf('pg_dump failed for database "%s": %s%s', $databaseName, $stderr, $suffix)
            );
        }
    }

    private function applyRetention(string $tenantId): void
    {
        $keep = (int) $this->config->get('tenant_backups.keep', 14);
        if ($keep <= 0) {
            return;
        }

        $expired = $this->db->connection('central')->table('tenant_backups')
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->get(['id', 'file_path']);

        foreach ($expired as $row) {
            $path = is_string($row->file_path) ? $row->file_path : '';
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
            $this->db->connection('central')->table('tenant_backups')
                ->where('id', $row->id)->delete();
        }
    }

    /**
     * @return array{host: string, port: int, username: string, password: string}
     */
    private function pgConfig(): array
    {
        $base = $this->config->get('database.connections.pgsql');
        if (! is_array($base)) {
            throw new RuntimeException('Missing pgsql connection config.');
        }

        return [
            'host' => (string) ($base['host'] ?? '127.0.0.1'),
            'port' => (int) ($base['port'] ?? 5432),
            'username' => (string) ($base['username'] ?? ''),
            'password' => (string) ($base['password'] ?? ''),
        ];
    }

    private function backupDirectory(string $tenantId): string
    {
        $root = (string) $this->config->get(
            'tenant_backups.root',
            storage_path('app/tenant-backups')
        );

        return $root.DIRECTORY_SEPARATOR.$tenantId;
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (! mkdir($path, 0o775, true) && ! is_dir($path)) {
            throw new RuntimeException("Unable to create backup directory: {$path}");
        }
    }

    private function isDbPerTenantMode(): bool
    {
        return (bool) $this->config->get('tenancy_resolver.db_per_tenant', false);
    }
}
