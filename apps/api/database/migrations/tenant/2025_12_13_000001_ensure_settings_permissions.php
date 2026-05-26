<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ensures settings.view and settings.update permissions exist
     * and are assigned to the admin role.
     */
    public function up(): void
    {
        // Reset cached permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Ensure permissions exist
        $settingsView = Permission::firstOrCreate([
            'name' => 'settings.view',
            'guard_name' => 'sanctum',
        ]);

        $settingsUpdate = Permission::firstOrCreate([
            'name' => 'settings.update',
            'guard_name' => 'sanctum',
        ]);

        // Assign to admin role
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'sanctum')->first();
        if ($adminRole !== null) {
            $adminRole->givePermissionTo($settingsView);
            $adminRole->givePermissionTo($settingsUpdate);
        }

        // Also assign settings.view to manager and viewer roles
        $managerRole = Role::where('name', 'manager')->where('guard_name', 'sanctum')->first();
        if ($managerRole !== null) {
            $managerRole->givePermissionTo($settingsView);
        }

        $viewerRole = Role::where('name', 'viewer')->where('guard_name', 'sanctum')->first();
        if ($viewerRole !== null) {
            $viewerRole->givePermissionTo($settingsView);
        }

        // Clear cache again after updates
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // We don't remove permissions on rollback as they may be needed
    }
};
