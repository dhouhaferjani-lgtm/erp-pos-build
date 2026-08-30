<?php

declare(strict_types=1);

namespace App\Modules\Import\Application\Services;

use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use Illuminate\Contracts\Auth\Authenticatable;

final class ModuleEntitlementCheck
{
    public function __construct(
        private readonly CompanyConfigService $configService,
    ) {}

    public function ensure(ImportType $type, ?Authenticatable $user): void
    {
        $module = $type->requiredModule();
        if ($module === null) {
            return;
        }

        if (! $this->allows($type, $user)) {
            abort(403, "Module '{$module}' is not enabled for this business type");
        }
    }

    public function allows(ImportType $type, ?Authenticatable $user): bool
    {
        $module = $type->requiredModule();
        if ($module === null) {
            return true;
        }

        $tenant = $this->tenantFor($user);
        $config = $this->configService->getConfigForTenant($tenant);

        return $config->hasModule($module);
    }

    private function tenantFor(?Authenticatable $user): Tenant
    {
        if ($user === null) {
            throw new \RuntimeException('User must be authenticated to check module access');
        }

        if (! $user instanceof User) {
            throw new \RuntimeException('Module access requires a tenant user, not a super admin');
        }

        $tenant = $user->tenant;
        if (! $tenant instanceof Tenant) {
            throw new \RuntimeException('Tenant not found for authenticated user');
        }

        return $tenant;
    }
}
