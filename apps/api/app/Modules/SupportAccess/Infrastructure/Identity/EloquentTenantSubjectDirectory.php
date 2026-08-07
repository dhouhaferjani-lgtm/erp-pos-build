<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Infrastructure\Identity;

use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\SupportAccess\TenantSubjectDirectory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;

final class EloquentTenantSubjectDirectory implements TenantSubjectDirectory
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
    ) {}

    public function belongsToTenant(string $tenantId, string $subjectUserId): bool
    {
        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            return false;
        }

        $central = $this->database->connection(
            $this->config->get('tenancy.database.central_connection'),
        );

        // An explicit database-per-tenant topology always wins over schema
        // sniffing. Test environments may deliberately load tenant migrations
        // into central for compatibility coverage; that must never redirect a
        // production-like support lookup away from the physical tenant DB.
        if (! (bool) $this->config->get('tenancy_resolver.db_per_tenant', false)
            && $central->getSchemaBuilder()->hasTable('users')) {
            return $central->table('users')
                ->where('id', $subjectUserId)
                ->where('tenant_id', $tenantId)
                ->exists();
        }

        return $tenant->run(
            static fn (): bool => User::query()
                ->whereKey($subjectUserId)
                ->where('tenant_id', $tenantId)
                ->exists(),
        );
    }
}
