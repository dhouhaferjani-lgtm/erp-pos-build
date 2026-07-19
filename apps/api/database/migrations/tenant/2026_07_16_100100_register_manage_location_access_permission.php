<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('permission:cache-reset');

        $permission = Permission::findOrCreate('users.manage_location_access', 'sanctum');

        $tenantId = tenant()?->getTenantKey();
        $teamColumn = (string) config('permission.column_names.team_foreign_key', 'tenant_id');
        $admin = Role::query()
            ->where('name', 'admin')
            ->where('guard_name', 'sanctum')
            ->when($tenantId !== null, static fn ($query) => $query->where($teamColumn, $tenantId))
            ->first();

        if ($admin !== null && ! $admin->hasPermissionTo($permission)) {
            $admin->givePermissionTo($permission);
        }

        Artisan::call('permission:cache-reset');
    }

    public function down(): void
    {
        // Permission-registration migrations are intentionally non-destructive.
    }
};
