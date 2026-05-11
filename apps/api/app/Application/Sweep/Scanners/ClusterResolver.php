<?php

declare(strict_types=1);

namespace App\Application\Sweep\Scanners;

/**
 * Resolves a relative file path (from repo root) to a sweep cluster id.
 *
 * The mapping is data-driven so tests can supply a fixture map and prod can
 * use the master plan Section 6 catalogue. A path that doesn't match any
 * mapping resolves to the configured fallback cluster id.
 *
 * Codex Phase 1 review #2: the production fallback is the synthetic
 * `api.unmapped` cluster, NOT `api.identity-company`. Routing unmatched
 * paths into identity-company silently misclassified real modules
 * (BatchExpiry, Expense, Product, Scheduling, Vehicle, …). Those callsites
 * must instead surface in `api.unmapped` so triage can re-classify them in
 * Phase 2 and the generate command can warn loudly when the catch-all is
 * hit.
 */
final class ClusterResolver
{
    /**
     * Production default fallback cluster. Anything the resolver can't map
     * to a known module lands here. The seed inventory must declare this
     * cluster (api.unmapped) so the merge step doesn't fail schema validation.
     */
    public const DEFAULT_FALLBACK_CLUSTER_ID = 'api.unmapped';

    /**
     * @param  array<string, string>  $moduleToCluster  Map: module short-name (e.g. "Treasury") → cluster id.
     */
    public function __construct(
        private readonly array $moduleToCluster,
        private readonly string $fallbackClusterId = self::DEFAULT_FALLBACK_CLUSTER_ID,
    ) {}

    /**
     * @param  string  $relativePath  e.g. "apps/api/app/Modules/Treasury/Presentation/Requests/Foo.php"
     */
    public function resolve(string $relativePath): string
    {
        // Match `Modules/<Name>/` whether or not a leading slash is present
        // (real prod paths begin "apps/api/app/Modules/..."; fixture paths
        // may begin "Modules/..." when the scan root IS the fixture root).
        if (preg_match('#(?:^|/)Modules/([A-Za-z0-9_-]+)/#', $relativePath, $match) !== 1) {
            return $this->fallbackClusterId;
        }
        $module = $match[1];

        return $this->moduleToCluster[$module] ?? $this->fallbackClusterId;
    }

    /**
     * Default mapping from the master plan Section 6 catalogue. Modules
     * not in this map fall back to {@see self::DEFAULT_FALLBACK_CLUSTER_ID}
     * (`api.unmapped`).
     *
     * @return array<string, string>
     */
    public static function defaultModuleToClusterMap(): array
    {
        return [
            'Treasury' => 'api.treasury',
            'Document' => 'api.document',
            'Inventory' => 'api.inventory',
            // BatchExpiry handles batch tracking + write-offs + transfer between
            // stock locations — semantically part of inventory management per
            // master plan Section 6 ("BatchExpiry, counting, stock reservation/
            // adjustment" listed under api.inventory).
            'BatchExpiry' => 'api.inventory',
            'Taxation' => 'api.taxation',
            'Loyalty' => 'api.loyalty',
            'Accounting' => 'api.accounting',
            // Expense tracking is operational accounting (expense categories,
            // partner-linked expenses, account postings). Folds into the
            // accounting cluster's existing scope (ledger, partner balance,
            // reconciliation per master plan Section 6).
            'Expense' => 'api.accounting',
            'Catalog' => 'api.catalog',
            // Product module exposes the product catalog CRUD (categories,
            // create/update product). The Catalog cluster's scope per master
            // plan Section 6 is "Modifier groups, modifiers, product catalog
            // selectors" — Product slots in.
            'Product' => 'api.catalog',
            'Contact' => 'api.contact',
            'Compliance' => 'api.compliance',
            'Pricing' => 'api.pricing',
            // Coupon application is a pricing/discount mechanism (margin +
            // discount calculation at cart time). Folds into api.pricing's
            // scope (margin checks, pricing rules per master plan Section 6).
            'Coupon' => 'api.pricing',
            'Service' => 'api.service',
            'Cart' => 'api.cart',
            'Workshop' => 'api.workshop',
            'Identity' => 'api.identity-company',
            'Tenant' => 'api.identity-company',
            'Company' => 'api.identity-company',
            'Membership' => 'api.identity-company',
            'Platform' => 'api.platform-integration',
            'Webhook' => 'api.webhooks-incoming',
            'Webhooks' => 'api.webhooks-incoming',
            'POS' => 'api.pos-stabilization',
            'Pos' => 'api.pos-stabilization',
            'SuperAdmin' => 'api.super-admin-context',
            // AdminBillingController is mounted under the super-admin route
            // group (`Route::prefix('admin')->middleware(['auth:sanctum-admin',
            // 'super_admin', ...])`) — every Billing controller endpoint is
            // super-admin-only. Map to api.super-admin-context so the cluster
            // owner (claude) handles it via #[CrossTenantRoute] annotation per
            // master plan Section 9, not via per-tenant scoping.
            'Billing' => 'api.super-admin-context',
            'Module' => 'api.module-gating',
            'ModuleGating' => 'api.module-gating',
            'Auth' => 'api.auth-permissions',
            'Permission' => 'api.auth-permissions',
            'Permissions' => 'api.auth-permissions',
            // Marketplace module (multi-seller listings, sync to external
            // marketplaces — Amazon/eBay-style, distinct from internal
            // Synerivia platform integration). Triaged 2026-05-09 to its own
            // api.marketplace cluster. Justification:
            //   1. Listeners are genuinely unscoped — `Product::query()->find()`
            //      with no tenant_id filter; MarketplaceSeller lookups by
            //      company_id alone despite both columns being present.
            //   2. Surface is non-trivial — 26 PHP files (3 controllers, 4
            //      services, 2 jobs, 6 domain models with tenant_id+company_id).
            //      Two listener callsites are the tip; controllers + services
            //      will surface more once swept.
            //   3. Threat model is distinct from api.platform-integration
            //      (B2B internal data sync) — Marketplace publishes to external
            //      consumer marketplaces, different trust boundary.
            // Cluster placement reviewed by Codex pending lock at submission
            // commit; see master plan Section 6 update + commit body.
            'Marketplace' => 'api.marketplace',
        ];
    }
}
