# Authorization Conventions

> **Purpose:** How permissions work in backend and frontend
> **Last Updated:** 2025-12-30

## Backend Authorization Flow

```
Request
  ↓
SetPermissionsTeam middleware (sets Spatie context)
  ↓
CompanyContextMiddleware (validates company access)
  ↓
ValidateLocationAccess middleware (optional)
  ↓
Controller ($user->can('permission'))
  ↓
Response (403 if unauthorized)
```

## Critical: Route Middleware Pattern

**MUST use this exact pattern for ALL module routes:**

```php
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])
    ->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
    });
```

**Missing ANY of these causes 401/403 errors:**
- `'api'` - API middleware stack initialization
- `'auth:sanctum'` - Authentication check
- `SetPermissionsTeam::class` - Permission context setup

## Backend Controller Permission Check

```php
public function index(Request $request): JsonResponse
{
    $user = $request->user();

    // ALWAYS check permission FIRST
    if (!$user->can('users.view')) {
        return response()->json([
            'error' => [
                'code' => 'FORBIDDEN',
                'message' => 'You do not have permission to view users.',
            ],
        ], 403);
    }

    // Continue with business logic
    $users = User::where('tenant_id', $user->tenant_id)->paginate();
    return response()->json(['data' => $users]);
}
```

## Common Permissions

```
users.view, users.create, users.update, users.delete
settings.view, settings.update
roles.view, roles.manage
journal.view, journal.create, journal.post
reports.view
accounts.view, accounts.manage
sales.view, sales.create, sales.edit
inventory.view, inventory.create
treasury.view, treasury.create
```

## Frontend Permission System

**File:** `apps/web/src/hooks/usePermissions.ts`

```typescript
export const PERMISSIONS = {
  'sales.view': ['admin', 'sales', 'manager'],
  'sales.create': ['admin', 'sales', 'manager'],
  'inventory.view': ['admin', 'inventory', 'manager'],
  'settings.edit': ['admin'],  // Only admins
} as const

export function usePermissions() {
  const user = useAuthStore((state) => state.user)
  const roles = user?.roles ?? []

  const hasPermission = (permission: Permission): boolean => {
    const allowedRoles = PERMISSIONS[permission]
    return roles.some((role) => allowedRoles.includes(role))
  }

  const canAccessModule = (moduleKey: string): boolean => {
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

// Multiple permissions (ANY)
<RequirePermission permissions={['reports.view', 'accounting.view']}>
  <ReportsPage />
</RequirePermission>

// Multiple permissions (ALL required)
<RequirePermission permissions={['journal.view', 'journal.post']} requireAll>
  <JournalEntry />
</RequirePermission>

// With fallback
<RequirePermission permission="settings.edit" fallback={<div>Read-only</div>}>
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

- [ ] Backend routes use `['api', 'auth:sanctum', SetPermissionsTeam::class]`
- [ ] Controllers check `$user->can('permission')` FIRST
- [ ] Frontend routes wrapped with `<RequirePermission>`
- [ ] Permission keys match between backend and frontend
- [ ] User roles properly assigned in database
