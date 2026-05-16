<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;

/**
 * Phase 1 default `ModuleActivationResolver` — spec v7 §7.3.
 *
 * Delegates to the existing module-activation surface: `CompanyConfigService`
 * → `CompanyConfig::hasModule()` over `allEnabledModules` — the same surface
 * `RequireModule` middleware reads. The token is passed through to
 * `hasModule()` **without transformation** (no case folding, no aliasing) so
 * the canonical-PascalCase contract (§7.3) is preserved end-to-end and the
 * strict `in_array(..., true)` compare on `Vertical::defaultModules()` keeps
 * a lowercase 'treasury' from accidentally resolving true.
 *
 * **Phase 1 caveat (§18 carry-forward).** The underlying surface is
 * tenant-level-cached (`tenant_config:{tenant_id}`, 24h TTL) and every
 * current vertical default includes `'Treasury'` — Treasury is not even a
 * `compatibleExtras()` toggle, so no current vertical can run with it
 * inactive in production. The seam is therefore real and testable via test
 * doubles today (e.g. `FiscalEventProjectionRegistryTest` in Task 18
 * constructs the resolver to report Treasury inactive and verifies the
 * Treasury bridge is excluded); making a *production* Treasury-inactive
 * deployment real is a later config-model change, not Phase 1.
 *
 * **Defensive contract.** A missing tenant resolves to `false`, never
 * throws — so the `FiscalEventProjectionRegistry` simply excludes the
 * gated bridge rather than crashing the ingest path (§7.2 validate-
 * then-insert flow).
 */
final class DefaultModuleActivationResolver implements ModuleActivationResolver
{
    public function __construct(
        private readonly CompanyConfigService $configService,
    ) {}

    public function isActive(string $module, string $tenantId, string $companyId): bool
    {
        // companyId is part of the contract (§7.3) but Phase 1 reads the
        // tenant-level surface — see class docblock + §18.
        unset($companyId);

        $tenant = Tenant::find($tenantId);
        if (! $tenant instanceof Tenant) {
            return false;
        }

        $config = $this->configService->getConfigForTenant($tenant);

        return $config->hasModule($module);
    }
}
