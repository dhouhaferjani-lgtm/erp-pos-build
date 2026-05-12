<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Product Cost Price Update Channel
 *
 * Private channel for real-time product cost/price updates.
 *
 * Channel pattern: private-tenant.{tenantId}.company.{companyId}.product.{productId}
 * Event: product.cost-price-updated
 *
 * @cross-tenant-anchored Channel name embeds tenantId + companyId + productId. Auth callback
 *   delegates to User::canAccessChannel which verifies (1) user.tenant_id === tenantId,
 *   (2) user.isActive(), and (3) an active UserCompanyMembership row for companyId.
 *   The {productId} segment is intentionally NOT validated against (tenantId, companyId)
 *   because products carry company_id and there is no cross-company product sharing within
 *   a tenant — the company-membership gate is the upstream guard, and the {productId}
 *   segment is a fan-out / subscription-scoping signal only. Architecture test
 *   BroadcastChannelTenantContextTest enforces this annotation OR a known-tenant-anchored
 *   auth-helper call (canAccessChannel / canAccessCompanyChannel) in the closure body.
 */
Broadcast::channel('tenant.{tenantId}.company.{companyId}.product.{productId}', function (User $user, string $tenantId, string $companyId, string $productId) {
    return $user->canAccessChannel($tenantId, $companyId, $productId);
});

/**
 * Import Progress Channel
 *
 * Private channel for real-time import progress updates.
 *
 * Channel pattern: private-tenant.{tenantId}.company.{companyId}.imports
 * Events: import.progress, import.completed
 *
 * @cross-tenant-anchored Channel name embeds tenantId + companyId. Auth callback delegates
 *   to User::canAccessCompanyChannel which verifies tenant_id match + active status +
 *   active UserCompanyMembership in companyId. Company-level fan-out for the imports
 *   feature; no per-import-row segmentation. Architecture test enforces this annotation
 *   OR a known-tenant-anchored auth-helper call in the closure body.
 */
Broadcast::channel('tenant.{tenantId}.company.{companyId}.imports', function (User $user, string $tenantId, string $companyId) {
    return $user->canAccessCompanyChannel($tenantId, $companyId);
});

/**
 * Partner Balance Update Channel
 *
 * Private channel for real-time partner balance updates.
 * Company-level channel — all partner balance updates for the company.
 *
 * Channel pattern: private-tenant.{tenantId}.company.{companyId}.partners
 * Event: partner.balance-updated
 *
 * @cross-tenant-anchored Channel name embeds tenantId + companyId. Auth callback delegates
 *   to User::canAccessCompanyChannel. Same anchor shape as the imports channel —
 *   company-level fan-out so the list page receives all partner balance updates without
 *   subscribing to N per-partner channels; no per-partner-row segmentation. Architecture
 *   test enforces this annotation OR a known-tenant-anchored auth-helper call in the
 *   closure body.
 */
Broadcast::channel('tenant.{tenantId}.company.{companyId}.partners', function (User $user, string $tenantId, string $companyId) {
    return $user->canAccessCompanyChannel($tenantId, $companyId);
});

/**
 * POS Terminal Activation Channel
 *
 * Private channel for real-time terminal activation notifications.
 *
 * Channel pattern: private-tenant.{tenantId}.company.{companyId}.pos.terminal.{terminalId}
 * Event: terminal.activated
 *
 * @cross-tenant-anchored Channel name embeds tenantId + companyId + terminalId. The closure
 *   signature binds only ($user, $tenantId, $companyId) — Laravel passes only the parameters
 *   declared by the closure, so the {terminalId} segment is NOT a security boundary.
 *   Auth callback delegates to User::canAccessCompanyChannel. Terminals carry company_id
 *   (no cross-company terminal sharing within a tenant), so the company-membership gate
 *   is the upstream guard and the {terminalId} segment is a fan-out / subscription-scoping
 *   signal only. Architecture test enforces this annotation OR a known-tenant-anchored
 *   auth-helper call in the closure body.
 */
Broadcast::channel('tenant.{tenantId}.company.{companyId}.pos.terminal.{terminalId}', function (User $user, string $tenantId, string $companyId) {
    return $user->canAccessCompanyChannel($tenantId, $companyId);
});

/**
 * POS Catalog Changed Channel
 *
 * Private channel for real-time POS catalog refresh signals.
 *
 * Channel pattern: private-tenant.{tenantId}.company.{companyId}.catalog
 * Event: catalog.changed (coarse — payload carries only `reason` + `timestamp`)
 *
 * @cross-tenant-anchored Channel name embeds tenantId + companyId. Auth callback
 *   delegates to User::canAccessCompanyChannel which verifies tenant_id match +
 *   active status + active UserCompanyMembership in companyId. Company-level
 *   fan-out for catalog mutations (Product / MenuCategory / MenuCategoryItem);
 *   the POS frontend debounces incoming events and refetches via the REST API,
 *   so per-entity routing is unnecessary. Architecture test enforces this
 *   annotation OR a known-tenant-anchored auth-helper call in the closure body.
 */
Broadcast::channel('tenant.{tenantId}.company.{companyId}.catalog', function (User $user, string $tenantId, string $companyId) {
    return $user->canAccessCompanyChannel($tenantId, $companyId);
});

/**
 * POS Kitchen Display Channel
 *
 * Private channel for real-time kitchen display system updates.
 *
 * Channel pattern: private-tenant.{tenantId}.company.{companyId}.pos.kitchen
 * Events: order.sent_to_kitchen, order.line_status_changed, order.ready
 *
 * @cross-tenant-anchored Channel name embeds tenantId + companyId. Auth callback delegates
 *   to User::canAccessCompanyChannel. Same anchor shape as the imports/partners channels —
 *   company-level fan-out for the kitchen-display-system; all KDS-relevant order events
 *   for the company flow on one channel. Architecture test enforces this annotation OR a
 *   known-tenant-anchored auth-helper call in the closure body.
 */
Broadcast::channel('tenant.{tenantId}.company.{companyId}.pos.kitchen', function (User $user, string $tenantId, string $companyId) {
    return $user->canAccessCompanyChannel($tenantId, $companyId);
});
