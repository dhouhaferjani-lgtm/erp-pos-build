<?php

declare(strict_types=1);

namespace App\Modules\Import\Application\Services;

use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;

final class ModuleEntitlementCheck
{
    public function __construct(
        private readonly CompanyConfigService $configService,
    ) {}

    public function ensure(ImportType $type, User $user): void
    {
        $module = $type->requiredModule();
        if ($module === null) {
            return;
        }

        $tenant = $user->tenant;
        if (! $tenant instanceof Tenant) {
            throw new \RuntimeException('Tenant not found for authenticated user');
        }

        $config = $this->configService->getConfigForTenant($tenant);
        if (! $config->hasModule($module)) {
            abort(403, "Module '{$module}' is not enabled for this business type");
        }
    }
}
