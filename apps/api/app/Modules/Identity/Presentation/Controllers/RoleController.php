<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Controllers;

use App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Application\DTOs\RoleData;
use App\Modules\Identity\Application\Services\GeneralManagerAssignmentGuard;
use App\Modules\Identity\Domain\Enums\RoleProvisioningSource;
use App\Modules\Identity\Domain\Enums\SystemRoleName;
use App\Modules\Identity\Domain\Events\RoleAssigned;
use App\Modules\Identity\Domain\Events\RoleRemoved;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Requests\AssignRoleRequest;
use App\Modules\Identity\Presentation\Requests\DeleteRoleRequest;
use App\Modules\Identity\Presentation\Requests\StoreRoleRequest;
use App\Modules\Identity\Presentation\Requests\UpdateRoleRequest;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    /**
     * Permission-group prefix (the token before the first '.') => the module
     * that must be enabled for the group to be listed. Only VERTICAL-GATED
     * prefixes appear here; any group whose prefix is NOT a key is treated as
     * a core group and is always shown. Mirrors the seeded catalog: the seeder
     * always creates every group (111 tests depend on that) — this is a
     * READ-TIME filter only.
     */
    private const PERMISSION_GROUP_MODULE = [
        'vehicles' => 'Vehicle',
        'workshop' => 'Workshop',
        'workshop-bundles' => 'Workshop',
        'work-orders' => 'Workshop',
        'menus' => 'Menu',
        'modifier-groups' => 'Menu',
        'composite-items' => 'CompositeItems',
        'scheduling' => 'Appointments',
        'batches' => 'BatchExpiry',
        'loyalty' => 'Loyalty',
    ];

    /**
     * Role name => the module that must be enabled for the role to be listed.
     * Only vertical-exclusive roles appear here; unmapped roles (admin,
     * manager, cashier, viewer, accountant, ...) are core and always shown.
     */
    private const ROLE_MODULE = [
        'technician' => 'Workshop',
        'operator' => 'Workshop',
    ];

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LotActionPermissionActivation $activation,
        private readonly CompanyConfigService $configService,
        private readonly GeneralManagerAssignmentGuard $generalManagerAssignmentGuard,
        private readonly PermissionRegistrar $permissionRegistrar,
    ) {}

    private function isMarkedProvisionedRole(Role $role): bool
    {
        return $role->name === SystemRoleName::GeneralManager->value && $role->guard_name === 'sanctum'
            && $role->getAttribute(config('permission.column_names.team_foreign_key')) !== null
            && $role->getAttribute('provisioning_source') === RoleProvisioningSource::Wlota1a->value;
    }

    private function isProtectedSystemRole(Role $role): bool
    {
        return in_array($role->name, ['super-admin', 'admin', 'owner'], true) || $this->isMarkedProvisionedRole($role);
    }

    private function roleData(Role $role): RoleData
    {
        return new RoleData(
            id: (int) $role->id, name: $role->name, guard_name: (string) $role->guard_name,
            permissions: array_values($role->permissions->pluck('name')->map(static fn ($name): string => (string) $name)->all()), users_count: $this->countUsersForRole($role),
            created_at: $role->created_at?->toIso8601String(), updated_at: $role->updated_at?->toIso8601String(),
            is_provisioned_read_only: $this->isMarkedProvisionedRole($role),
        );
    }

    private function provisionedRoleReadOnlyResponse(): JsonResponse
    {
        return response()->json(['error' => ['code' => 'PROVISIONED_ROLE_READ_ONLY', 'message' => 'Provisioned roles are read-only.']], 422);
    }

    /**
     * Resolve the enabled modules for the caller's tenant, mirroring
     * RequireModule::handle. Returns null when there is no tenant context
     * (super-admin / central) — callers treat null as "do not filter".
     *
     * @return array<int, string>|null
     */
    private function enabledModules(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $tenant = $user->tenant;

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return $this->configService->getConfigForTenant($tenant)->allEnabledModules;
    }

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

        // Read-time vertical filter: drop vertical-exclusive roles whose backing
        // module is not enabled for this tenant. Null => no tenant context, so
        // list everything (admin/central behavior unchanged).
        $enabledModules = $this->enabledModules($request);
        if ($enabledModules !== null) {
            $roles = $roles->reject(function (Role $role) use ($enabledModules): bool {
                $module = self::ROLE_MODULE[$role->name] ?? null;

                return $module !== null && ! in_array($module, $enabledModules, true);
            })->values();
        }

        $data = $roles->map(fn (Role $role) => (array) $this->roleData($role));

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
            'data' => (array) $this->roleData($role),
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
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'sanctum',
        ]);

        assert($role instanceof Role);

        if (! empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        $role->load('permissions');

        return response()->json([
            'data' => (array) $this->roleData($role),
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
    public function update(UpdateRoleRequest $request, int $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        if ($this->isMarkedProvisionedRole($role) && ($request->has('name') || $request->has('permissions'))) {
            return $this->provisionedRoleReadOnlyResponse();
        }

        // Prevent modifying system roles
        if ($this->isProtectedSystemRole($role) && $request->has('name') && $request->input('name') !== $role->name) {
            return response()->json([
                'error' => [
                    'code' => 'SYSTEM_ROLE_PROTECTED',
                    'message' => 'System roles cannot be renamed.',
                ],
            ], 422);
        }

        $validated = $request->validated();

        if (isset($validated['name'])) {
            $role->name = $validated['name'];
            $role->save();
        }

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        $role->load('permissions');

        return response()->json([
            'data' => (array) $this->roleData($role),
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
    public function destroy(DeleteRoleRequest $request, int $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        if ($this->isMarkedProvisionedRole($role)) {
            return $this->provisionedRoleReadOnlyResponse();
        }

        // Prevent deleting system roles
        if ($this->isProtectedSystemRole($role)) {
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

        // Read-time vertical filter: drop vertical-gated groups whose backing
        // module is not enabled for this tenant. Null => no tenant context, so
        // list everything. Core (unmapped) groups are always kept.
        $enabledModules = $this->enabledModules($request);
        if ($enabledModules !== null) {
            $grouped = $grouped->reject(function ($perms, $prefix) use ($enabledModules): bool {
                $module = self::PERMISSION_GROUP_MODULE[$prefix] ?? null;

                return $module !== null && ! in_array($module, $enabledModules, true);
            });
        }

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
    public function assignRole(AssignRoleRequest $request, string $userId): JsonResponse
    {
        $validated = $request->validated();

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $previousTeam = $this->permissionRegistrar->getPermissionsTeamId();
        $this->permissionRegistrar->setPermissionsTeamId($actor->tenant_id);
        try {
            return DB::transaction(function () use ($request, $userId, $validated, $actor): JsonResponse {
                $user = User::query()->where('tenant_id', $actor->tenant_id)->where('id', $userId)->lockForUpdate()->firstOrFail();
                $companyId = $this->companyContext->requireCompanyId();
                $memberships = UserCompanyMembership::query()->where('user_id', $userId)->where('status', MembershipStatus::Active)->orderBy('company_id')->lockForUpdate()->get();
                $membership = $memberships->firstWhere('company_id', $companyId);
                abort_if($this->activation->enforced() && $membership === null, 422, 'Active company membership required.');
                Role::query()->where(config('permission.column_names.team_foreign_key'), $actor->tenant_id)->where('name', $validated['role'])->lockForUpdate()->first();
                $effectiveRoles = array_values(array_unique([...array_values($user->getRoleNames()->map(static fn ($name): string => (string) $name)->all()), $validated['role']]));
                $this->generalManagerAssignmentGuard->assertAssignable($actor, $userId, $companyId, $effectiveRoles, $membership?->allowed_location_ids);

                /** @var string $roleName */
                $roleName = $validated['role'];
                $user->assignRole($roleName);
                $this->generalManagerAssignmentGuard->assertAssignable($actor, $userId, $companyId, array_values($user->getRoleNames()->map(static fn ($name): string => (string) $name)->all()), $membership?->allowed_location_ids);

                // Privileged action — leave an audit trail (actor + target + role +
                // timestamp). The audit_events row is the only record of who granted
                // whom which role; model_has_roles carries team_id alone.
                $actor = $request->user();
                if ($actor instanceof User) {
                    event(new RoleAssigned(
                        targetUserId: $user->id,
                        roleName: $roleName,
                        companyId: $this->companyContext->requireCompany()->id,
                        actorUserId: $actor->id,
                        assignedAt: now()->toIso8601String(),
                    ));
                }

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
            });
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId($previousTeam);
        }
    }

    /**
     * Remove a role from a user.
     */
    public function removeRole(AssignRoleRequest $request, string $userId): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->resolveTenantUser($userId);

        /** @var string $roleName */
        $roleName = $validated['role'];
        $user->removeRole($roleName);

        // Privileged action — role revocation must be audit-logged with the
        // actor and timestamp, mirroring assignRole().
        $actor = $request->user();
        if ($actor instanceof User) {
            event(new RoleRemoved(
                targetUserId: $user->id,
                roleName: $roleName,
                companyId: $this->companyContext->requireCompany()->id,
                actorUserId: $actor->id,
                removedAt: now()->toIso8601String(),
            ));
        }

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
