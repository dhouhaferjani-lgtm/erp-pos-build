<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Fiscal;

/**
 * Per-`(tenant, company)` module-activation seam — spec v7 §7.3 +
 * SoT §13.6/D16 (the bounded-modules asymmetric guardrail).
 *
 * Answers "is module M active for `(tenant, company)`?" so the
 * `FiscalEventProjectionRegistry` (Task 18) can gate pluggable
 * projector bridges. The Phase 1 `TreasuryReceiptBridge` (Task 22)
 * uses this seam: `requiresModule() = 'Treasury'` and runs only when
 * `isActive('Treasury', $tenantId, $companyId)` returns true.
 *
 * **Canonical PascalCase tokens — load-bearing.** `$module` is passed
 * through to `CompanyConfig::hasModule()` which does an `in_array(..., true)`
 * strict-compare against `Vertical::defaultModules()` (PascalCase). A
 * lowercase or alternate-cased token always resolves false. Callers must
 * use the canonical token — `'Treasury'`, `'Accounting'`, `'Sales'`,
 * `'Inventory'`, etc.
 *
 * **Caveat (§18 carry-forward, not Phase 1):** the Phase 1 implementation
 * delegates to a tenant-level-cached surface and every current vertical
 * default includes `'Treasury'`. The seam is real and testable here via
 * test doubles (`FiscalEventProjectionRegistryTest` constructs the
 * resolver to report Treasury inactive); a *production* Treasury-inactive
 * deployment is a later config-model change.
 */
interface ModuleActivationResolver
{
    /**
     * @param  string  $module  Canonical PascalCase module identifier (e.g. `'Treasury'`).
     * @param  string  $tenantId  Tenant UUID.
     * @param  string  $companyId  Company UUID. Phase 1 implementation does not branch on this — the
     *                             surface is tenant-level — but the parameter is part of the contract so
     *                             a future per-company implementation is a drop-in replacement.
     */
    public function isActive(string $module, string $tenantId, string $companyId): bool;
}
