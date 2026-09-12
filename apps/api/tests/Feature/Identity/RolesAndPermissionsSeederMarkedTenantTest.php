<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LotActionPermissionDeltaTest.php';

final class RolesAndPermissionsSeederMarkedTenantTest extends LotActionRoleFixture
{
    public function test_flag_off_reseed_after_delta_preserves_canonical_grants_for_marked_tenant(): void
    {
        $this->applyDelta();
        Permission::findOrCreate('custom.wlota1a', 'sanctum');
        Role::findByName('general_manager', 'sanctum')->givePermissionTo('custom.wlota1a');
        $before = $this->orderedPermissionSnapshot();
        config(['lot_action_permissions.enforce' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);
        self::assertSame($before, $this->orderedPermissionSnapshot());
        self::assertFalse(Role::findByName('manager', 'sanctum')->hasPermissionTo('batches.recall'));
    }

    public function test_flag_off_reseed_after_rollback_preserves_post_activation_state(): void
    {
        config(['lot_action_permissions.enforce' => true]);
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::findOrCreate('custom.wlota1a', 'sanctum');
        Role::findByName('manager', 'sanctum')->givePermissionTo('custom.wlota1a');
        $before = $this->orderedPermissionSnapshot();
        config(['lot_action_permissions.enforce' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);
        self::assertSame($before, $this->orderedPermissionSnapshot());
        self::assertFalse(Role::findByName('manager', 'sanctum')->hasPermissionTo('batches.recall'));
    }
}
