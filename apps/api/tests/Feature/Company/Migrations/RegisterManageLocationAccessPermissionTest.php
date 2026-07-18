<?php

declare(strict_types=1);

namespace Tests\Feature\Company\Migrations;

use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class RegisterManageLocationAccessPermissionTest extends TestCase
{
    use RefreshDatabase;

    private PermissionRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Permission Migration Tenant',
            'slug' => 'permission-migration-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->registrar = $this->app->make(PermissionRegistrar::class);
        $this->registrar->setPermissionsTeamId($tenant->id);

        Permission::where('name', 'users.manage_location_access')
            ->where('guard_name', 'sanctum')
            ->delete();

        $admin = Role::findOrCreate('admin', 'sanctum');
        $admin->givePermissionTo(Permission::findOrCreate('users.view', 'sanctum'));
    }

    public function test_up_registers_grants_and_cache_refreshes_idempotently(): void
    {
        $this->assertSame(0, Permission::where('name', 'users.manage_location_access')->count());

        // Warm the registrar's tenant-blind cache before the migration runs.
        $this->assertCount(1, $this->registrar->getPermissions(['name' => 'users.view']));
        $this->assertCount(0, $this->registrar->getPermissions(['name' => 'users.manage_location_access']));

        $this->runMigration();

        $this->assertDatabaseHas('permissions', [
            'name' => 'users.manage_location_access',
            'guard_name' => 'sanctum',
        ]);
        $this->assertSame(1, Permission::where('name', 'users.manage_location_access')->count());
        $this->assertTrue(Role::findByName('admin', 'sanctum')->hasPermissionTo('users.manage_location_access'));

        // The same registrar instance must see the permission after its cache was warmed above.
        $this->assertCount(1, $this->registrar->getPermissions(['name' => 'users.manage_location_access']));

        $this->runMigration();

        $this->assertSame(1, Permission::where('name', 'users.manage_location_access')->count());
        $this->assertTrue(Role::findByName('admin', 'sanctum')->hasPermissionTo('users.manage_location_access'));
        $this->assertCount(1, $this->registrar->getPermissions(['name' => 'users.manage_location_access']));
    }

    private function runMigration(): void
    {
        $migration = require __DIR__.'/../../../../database/migrations/tenant/2026_07_16_100100_register_manage_location_access_permission.php';
        $migration->up();
    }
}
