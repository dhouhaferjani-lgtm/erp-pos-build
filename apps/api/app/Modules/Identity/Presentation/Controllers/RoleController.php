<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Controllers;

use App\Modules\Identity\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * List all roles.
     */
    public function index(Request $request): JsonResponse
    {
        $roles = Role::with('permissions')->get();

        $data = $roles->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'guard_name' => $role->guard_name,
            'permissions' => $role->permissions->pluck('name'),
            'users_count' => $role->users()->count(),
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
    public function show(Request $request, int $id): JsonResponse
    {
        $role = Role::with('permissions')->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'permissions' => $role->permissions->pluck('name'),
                'users_count' => $role->users()->count(),
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
                'users_count' => $role->users()->count(),
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
        $usersCount = $role->users()->count();
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

        $user = User::findOrFail($userId);

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

        $user = User::findOrFail($userId);

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
        $user = User::findOrFail($userId);

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
