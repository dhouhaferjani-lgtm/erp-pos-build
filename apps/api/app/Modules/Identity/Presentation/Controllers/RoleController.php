<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Count users assigned to a role via direct database query.
     * This avoids reliance on Spatie's morphedByMany relationship which requires proper guard config.
     */
    private function countUsersForRole(Role $role): int
    {
        return DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->count();
    }

    /**
     * Resolve a user by id constrained to the caller's tenant. The 404
     * shape matches a genuinely missing id so cross-tenant ids cannot
     * be distinguished from nonexistent ones. dev-remediation/B.M2.4.
     */
    private function resolveTenantUser(string $userId): User
    {
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $user = User::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $userId)
            ->first();

        if ($user === null) {
            abort(404, 'User not found');
        }

        return $user;
    }

    /**
     * List all roles.
     */
    #[CrossTenantRoute(reason: 'Spatie TeamScope auto-scoping: the SetPermissionsTeam middleware (mounted on every tenant-scoped route group at routes/api.php:48) sets the active team_id on Spatie\'s permission registrar, and Spatie\'s package-level global scope filters Role queries by team_id. Role::with(\'permissions\')->get() therefore returns only the current team\'s roles. The model_has_roles direct DB query in countUsersForRole IS unscoped — but this is read-only count of users tied to a Spatie-scoped role record, so the role-id input is already team-bound.')]
    public function index(Request $request): JsonResponse
    {
        $roles = Role::with('permissions')->get();

        $data = $roles->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'guard_name' => $role->guard_name,
            'permissions' => $role->permissions->pluck('name'),
            'users_count' => $this->countUsersForRole($role),
            'created_at' => $role->created_at?->toIso8601String(),
            'updated_at' => $role->updated_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Get a single role.
     */
    #[CrossTenantRoute(reason: 'Spatie TeamScope auto-scoping: Role::with(\'permissions\')->findOrFail($id) is filtered by Spatie\'s package-level global scope to the active team_id (set by SetPermissionsTeam middleware on the route group). A role belonging to a different team would not be found by this query.')]
    public function show(Request $request, int $id): JsonResponse
    {
        $role = Role::with('permissions')->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'permissions' => $role->permissions->pluck('name'),
                'users_count' => $this->countUsersForRole($role),
                'created_at' => $role->created_at?->toIso8601String(),
                'updated_at' => $role->updated_at?->toIso8601String(),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Create a new role.
     */
    #[CrossTenantRoute(reason: 'Spatie TeamScope auto-scoping: Role::create() inserts the new role with team_id auto-stamped by Spatie\'s permission registrar (SetPermissionsTeam middleware sets the active team_id). $role->syncPermissions(...) operates on the just-created role, which is also team-scoped.')]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
        ]);

        if (! empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        $role->load('permissions');

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'permissions' => $role->permissions->pluck('name'),
                'users_count' => 0,
                'created_at' => $role->created_at?->toIso8601String(),
                'updated_at' => $role->updated_at?->toIso8601String(),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Update a role.
     */
    #[CrossTenantRoute(reason: 'Spatie TeamScope auto-scoping: Role::findOrFail($id) is filtered by team_id (Spatie global scope set by SetPermissionsTeam middleware); a role from a different team would 404 here. System-role guard (super-admin/admin/owner) prevents renaming protected role names.')]
    public function update(Request $request, int $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        // Prevent modifying system roles
        $systemRoles = ['super-admin', 'admin', 'owner'];
        if (in_array($role->name, $systemRoles, true) && $request->has('name') && $request->input('name') !== $role->name) {
            return response()->json([
                'error' => [
                    'code' => 'SYSTEM_ROLE_PROTECTED',
                    'message' => 'System roles cannot be renamed.',
                ],
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        if (isset($validated['name'])) {
            $role->name = $validated['name'];
            $role->save();
        }

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        $role->load('permissions');

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'permissions' => $role->permissions->pluck('name'),
                'users_count' => $this->countUsersForRole($role),
                'created_at' => $role->created_at?->toIso8601String(),
                'updated_at' => $role->updated_at?->toIso8601String(),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Delete a role.
     */
    #[CrossTenantRoute(reason: 'Spatie TeamScope auto-scoping: Role::findOrFail($id) is team-scoped via Spatie. System-role guard prevents deleting protected names; users-count guard prevents orphaning role assignments.')]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        // Prevent deleting system roles
        $systemRoles = ['super-admin', 'admin', 'owner'];
        if (in_array($role->name, $systemRoles, true)) {
            return response()->json([
                'error' => [
                    'code' => 'SYSTEM_ROLE_PROTECTED',
                    'message' => 'System roles cannot be deleted.',
                ],
            ], 422);
        }

        // Check if role has users
        $usersCount = $this->countUsersForRole($role);
        if ($usersCount > 0) {
            return response()->json([
                'error' => [
                    'code' => 'ROLE_HAS_USERS',
                    'message' => "Cannot delete role with {$usersCount} assigned user(s). Reassign users first.",
                ],
            ], 422);
        }

        $role->delete();

        return response()->json([
            'data' => [
                'message' => 'Role deleted successfully.',
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * List all permissions.
     */
    #[CrossTenantRoute(reason: 'Permissions catalog: Permission::all() returns the platform-defined permission catalog. Permissions in Spatie\'s package are platform-shared (the role-permission relationship is team-scoped, not the permissions themselves) — every team picks from the same permission catalog.')]
    public function permissions(Request $request): JsonResponse
    {
        $permissions = Permission::all();

        // Group permissions by module
        $grouped = $permissions->groupBy(function (Permission $permission) {
            $parts = explode('.', $permission->name);

            return $parts[0];
        });

        return response()->json([
            'data' => $grouped->map(fn ($perms) => $perms->pluck('name')),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Assign a role to a user.
     */
    public function assignRole(Request $request, string $userId): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', 'exists:roles,name'],
        ]);

        $user = $this->resolveTenantUser($userId);

        /** @var string $roleName */
        $roleName = $validated['role'];
        $user->assignRole($roleName);

        return response()->json([
            'data' => [
                'message' => "Role '{$roleName}' assigned to user",
                'user_id' => $user->id,
                'roles' => $user->getRoleNames(),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Remove a role from a user.
     */
    public function removeRole(Request $request, string $userId): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', 'exists:roles,name'],
        ]);

        $user = $this->resolveTenantUser($userId);

        /** @var string $roleName */
        $roleName = $validated['role'];
        $user->removeRole($roleName);

        return response()->json([
            'data' => [
                'message' => "Role '{$roleName}' removed from user",
                'user_id' => $user->id,
                'roles' => $user->getRoleNames(),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Get user roles and permissions.
     */
    public function userRoles(Request $request, string $userId): JsonResponse
    {
        $user = $this->resolveTenantUser($userId);

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }
}
