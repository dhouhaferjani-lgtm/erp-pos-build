# Authorization Conventions

> **Purpose:** How permissions work in backend and frontend
> **Last Updated:** 2026-09-10

## Backend Authorization Flow

```
Request
  ↓
ResolveTenancy (pre-auth: binds the tenant before the user is resolved)
  ↓
auth:sanctum
  ↓
SetPermissionsTeam (sets the Spatie team context)
  ↓
EnforceTokenTenantClaim (the token's tenant must match the resolved tenant)
  ↓
can:<permission>            ← THE ACTION GATE. Route middleware, always.
  ↓
CompanyContextMiddleware (validates company access)
  ↓
Policy (row-level only: "may this actor touch THIS record")
  ↓
Controller (business logic; may add defence in depth, never the only check)
```

## Critical: Route Middleware Pattern

This is the complete production file `apps/api/app/Modules/Promotion/Presentation/routes.php` after wave 0a Task 4:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Promotion\Presentation\Controllers\PromotionController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // Promotions CRUD. index/show already check promotions.view in the
    // controller (PromotionController.php:29,69) and store/update check
    // promotions.manage in their FormRequests (StorePromotionRequest.php:17,
    // UpdatePromotionRequest.php:17); destroy and the three status transitions
    // (PromotionController.php:122,146,170,194) check NOTHING at any layer — an
    // intra-controller inconsistency, not a policy.
    Route::get('promotions', [PromotionController::class, 'index']);
    Route::post('promotions', [PromotionController::class, 'store']);
    Route::get('promotions/{id}', [PromotionController::class, 'show']);
    Route::patch('promotions/{id}', [PromotionController::class, 'update']);
    Route::delete('promotions/{id}', [PromotionController::class, 'destroy'])
        ->middleware('can:promotions.manage');

    // Status transitions
    Route::post('promotions/{id}/activate', [PromotionController::class, 'activate'])
        ->middleware('can:promotions.manage');
    Route::post('promotions/{id}/pause', [PromotionController::class, 'pause'])
        ->middleware('can:promotions.manage');
    Route::post('promotions/{id}/archive', [PromotionController::class, 'archive'])
        ->middleware('can:promotions.manage');
});
```

The four `can:promotions.manage` gates are the gates Task 4 adds. `destroy`, `activate`, `pause`, and `archive` had no check at any layer. `index` and `show` call `Gate::authorize('promotions.view')` in `PromotionController.php:29,69`; `store` and `update` check `promotions.manage` in `StorePromotionRequest.php:17` and `UpdatePromotionRequest.php:17`. Those checks are defence in depth under E-2, never a substitute for route middleware. The four routes above with no route middleware remain `UNCOVERED` under E-1 and remain in the ratchet baseline; this is a real file mid-shrink, not a finished example.

- **E-1. Every route is gated or public, and the ratchet recognises exactly five classifications.** `Tests\Architecture\Support\RouteCoverageClassifier` decides them in this order:
  1. **`TOMBSTONE`** — one of the four keys in `tests/Architecture/fixtures/route-tombstones.json`, each proven by `TombstoneRouteBehaviourTest` to return an unconditional 410 with zero database queries.
  2. **`PUBLIC`** — one of the 18 keys in `RouteCoverageClassifier::PUBLIC_ALLOW_LIST`. This is an exact list, never a prefix.
  3. **`SELF_SERVICE`** — one of the seven method-and-URI keys in `Tests\Architecture\Support\SelfServiceRouteRegistry::ALLOW_LIST`. Wearing `authz.self` does not certify a route: an unlisted marked route is `UNCOVERED`. A self-service URI may carry only the caller's own `{session}` or `{tokenId}`; `POST notifications/{id}/read` is exempted by name because its lookup is scoped to `$request->user()`.
  4. **`GATED`** — the resolved middleware contains an action gate: `can:<permission>`, `require.any.permission:a,b`, `super_admin`, `central_admin`, or `central_admin_role`. `module:` is a module kill-switch, not an actor authorization gate; flag-conditional middleware is not a gate either.
  5. **`UNCOVERED`** — everything else. The ratchet counts and shrinks this set.
- **E-2.** `can:` route middleware is the single action gate. A controller may add defence in depth with `Gate::authorize()` or `$user->can()`, but may not be the only gate.
- **E-3.** Policies are for row-level decisions only and are registered explicitly in `apps/api/app/Providers/AppServiceProvider.php:270-276`. A policy never replaces the action gate.
- **E-4.** A FormRequest's `authorize()` must not be the sole permission check. Of 262 FormRequests, 180 return `true` unconditionally.

A new route with no gate fails `RoutePermissionCoverageRatchetTest`. New routes may never be added to the coverage baseline.

## Backend Controller Permission Checks

The route action gate has already run before a controller is invoked. When a controller adds defence in depth, use `Gate::authorize('users.view')`; do not build a 403 response by hand. `Gate::authorize()` flows through the global `AccessDeniedHttpException` renderer and preserves the repository's standard error envelope.

**Report-endpoint 403 contract (ticket 2026-08-06-l3-cash-scope-residuals.md (b)):** `cashMovements`, `agedReceivables`, `agedPayables` and `upcomingPayments` on `ReportsController` don't build this envelope by hand — they let `LocationScopeResolver::resolve()`'s `AuthorizationException` bubble unhandled (deliberately kept OUTSIDE any surrounding `try`/`catch`) to the global `AccessDeniedHttpException` render handler in `bootstrap/app.php`, which emits the exact same `{"error":{"code":"FORBIDDEN","message":<i18n auth.permission_denied_generic>,"ability":null}}` shape. Any new report/location-scoped endpoint should do the same rather than catching and re-wrapping the exception.

## Permission Catalogue

Today the catalogue is `apps/api/database/seeders/RolesAndPermissionsSeeder::permissionNames()`. In wave 1 it becomes `PermissionRegistry::activeKeys()`. The frontend mirror at `apps/web/src/hooks/permissionsMap.generated.ts` is generated and must never be hand-edited; read it to inspect the live catalogue.

Earlier versions of this guide invented `sales.view`, `sales.create`, `sales.edit`, `inventory.create`, `treasury.create`, `settings.edit`, and `accounting.view`. None is in the catalogue; four survive only as fail-open UI aliases. `reports.view` exists but is deprecated (`RolesAndPermissionsSeeder.php:325-332`) and must not be used as a positive gate. `accounts.manage` is real.

## Frontend Permission System

The hook is `apps/web/src/hooks/usePermissions.ts`. `PERMISSIONS` is generated in `apps/web/src/hooks/permissionsMap.generated.ts`, not authored in the hook. `Permission` is a closed literal union, so an unknown key is a compile error. `SERVER_AUTHORITATIVE_PERMISSIONS` names keys whose role-map fallback is deliberately disabled.

```typescript
export function usePermissions() {
  const user = useAuthStore((state) => state.user)

  const hasPermission = (permission: Permission): boolean => {
    // Examples of active generated keys:
    // users.view, promotions.view, settings.update
    return resolvePermission(user, permission)
  }

  const canAccessModule = (moduleKey: ModuleKey): boolean => {
    const requiredPermissions = MODULE_PERMISSIONS[moduleKey]
    return hasAnyPermission(requiredPermissions)
  }

  return { hasPermission, canAccessModule }
}
```

## RequirePermission Component

**File:** `apps/web/src/features/auth/components/RequirePermission.tsx`

```typescript
// Single permission check
<RequirePermission permission="users.view">
  <UsersList />
</RequirePermission>

// Module-level access
<RequirePermission moduleKey="sales">
  <SalesModule />
</RequirePermission>

// A module key is not a permission. ModuleKey is the closed union of keys in
// MODULE_PERMISSIONS; both namespaces are closed and unknown keys fail compile.

// Multiple permissions (ANY)
<RequirePermission permissions={['reports.financial', 'reports.operational']}>
  <ReportsPage />
</RequirePermission>

// Multiple permissions (ALL required)
<RequirePermission permissions={['journal.view', 'journal.post']} requireAll>
  <JournalEntry />
</RequirePermission>

// With fallback
<RequirePermission permission="settings.update" fallback={<div>Read-only</div>}>
  <SettingsForm editable />
</RequirePermission>
```

## Multi-Company Context

**File:** `apps/api/app/Modules/Company/Services/CompanyContext.php`

The `CompanyContextMiddleware` resolves company from:
1. `X-Company-Id` header (explicit selection)
2. User's first company membership (default)

```php
// Validates user has access
if (!$this->companyContext->userHasAccessToCompany($user, $companyId)) {
    return response()->json(['error' => 'COMPANY_ACCESS_DENIED'], 403);
}
```

## Location-Level Access

**File:** `apps/api/app/Modules/Company/Services/LocationContext.php`

```php
public function canAccessLocation(
    string $locationId,
    string $companyId,
    ?User $user = null
): bool {
    $membership = $this->getCurrentMembership($companyId, $user);

    // null = access all locations
    if ($membership->allowed_location_ids === null) return true;

    // Check if location in allowed list
    return in_array($locationId, $membership->allowed_location_ids, true);
}
```

## Checklist

- [ ] Backend routes use `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]`
- [ ] Every new route is ONE of the five classifications, and you can say which:
      - `can:<permission>` / `require.any.permission:a,b` / a super- or central-admin alias → GATED (the default; choose this unless one of the four below is true)
      - an entry in `RouteCoverageClassifier::PUBLIC_ALLOW_LIST` → PUBLIC (needs a reviewer's yes, not just yours)
      - `authz.self` PLUS an entry in `SelfServiceRouteRegistry::ALLOW_LIST` → SELF-SERVICE (only when the resource IS the caller, structurally)
      - an entry in `fixtures/route-tombstones.json` PLUS a passing `TombstoneRouteBehaviourTest` case → TOMBSTONE
      - anything else → UNCOVERED, and `RoutePermissionCoverageRatchetTest` fails
- [ ] Controller checks are defence in depth, never the only gate
- [ ] A FormRequest's `authorize()` is never the only permission check
- [ ] Frontend routes wrapped with `<RequirePermission>` — and the API route the page calls carries the SAME gate, or the page 403s on load for a role that can reach it
- [ ] Permission keys come from the generated map, never hand-typed in TypeScript, and never from `uiAliasPermissions.ts` (fail-open, deleted in wave 2a)
- [ ] `RoutePermissionCoverageRatchetTest` is green (a new ungated route fails it) and **new routes may never be added to its baseline**
