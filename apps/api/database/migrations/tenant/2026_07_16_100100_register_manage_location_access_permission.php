<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::findOrCreate('users.manage_location_access', 'sanctum');

        $admin = Role::query()
            ->where('name', 'admin')
            ->where('guard_name', 'sanctum')
            ->first();

        if ($admin !== null && ! $admin->hasPermissionTo($permission)) {
            $admin->givePermissionTo($permission);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permission-registration migrations are intentionally non-destructive.
    }
};
