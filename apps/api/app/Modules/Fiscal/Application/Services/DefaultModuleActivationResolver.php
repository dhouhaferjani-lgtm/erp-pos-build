<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Illuminate\Database\QueryException;

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
 * tenant-level-cached — cache key `tenant_config:{tenant_id}`, TTL 24h —
 * and every current `Vertical::defaultModules()` includes `'Treasury'`.
 * Treasury is not even a `compatibleExtras()` toggle, so no current
 * vertical can run with it inactive in production. The seam is therefore
 * real and testable via test doubles today (e.g.
 * `FiscalEventProjectionRegistryTest` in Task 18 constructs the resolver
 * to report Treasury inactive and verifies the Treasury bridge is
 * excluded); making a *production* Treasury-inactive deployment real is
 * a later config-model change, not Phase 1. **Operational note:** a
 * live admin module-toggle takes up to 24h to propagate via the cache —
 * bust the key explicitly if a faster turnaround is needed.
 *
 * **Defensive contract.** A missing tenant — whether the id is unknown
 * (Eloquent returns null) or malformed (PostgreSQL `uuid` column rejects
 * with `QueryException`) — resolves to `false`, never throws. So the
 * `FiscalEventProjectionRegistry` simply excludes the gated bridge
 * rather than crashing the ingest path (§7.2 validate-then-insert flow).
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

        try {
            $tenant = Tenant::find($tenantId);
        } catch (QueryException) {
            // Malformed tenantId on PG — `uuid`-typed column rejects strings
            // that don't match the canonical UUID grammar. Phase 1 callers
            // (Tasks 18/22) source tenantId from `fiscal_events.tenant_id`
            // which is also `uuid`-typed, so this path is not exercised in
            // the happy ingest flow — but the docblock pins "never throws"
            // and this defends against a future caller passing a free-form
            // string without round-tripping it through the schema first.
            return false;
        }
        if (! $tenant instanceof Tenant) {
            return false;
        }

        $config = $this->configService->getConfigForTenant($tenant);

        return $config->hasModule($module);
    }
}
