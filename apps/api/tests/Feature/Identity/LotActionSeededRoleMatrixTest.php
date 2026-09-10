<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LotActionPermissionDeltaTest.php';

final class LotActionSeededRoleMatrixTest extends LotActionRoleFixture
{
    public function test_flag_off_generic_seeder_retains_exact_legacy_role_behavior(): void
    {
        self::assertSame(0, DB::table('roles')->whereNotNull('tenant_id')->count());
        // Exact shipped matrix at dispatch base 4373ba2f6, SHA-256 of the
        // sorted, unique permission-name JSON for each role (independent of IDs).
        $expected = [
            'accountant' => 'fa1ad154d2bf5e816f36a1f1474fc835d03f8fc17edbdd6234f51d3f1dccd2fa',
            'admin' => '67ad3379103b9e6ffe17beb46caabb71550fed15c81affa6862705177355a42a',
            'cashier' => 'fc716351442ed4ade558896165996f91234166b19294a67cc44a26c8a6045b3d',
            'manager' => '4ccf872e913295fedf2ef190023ca2fb6b751a27cb0e177f1b40e09393e0ba18',
            'operator' => 'e52e8d3a1a4ec8ab04911f225e09490e47714660ee5693819294b993b9c77d7e',
            'technician' => '12096fa5a0b062750c112524ab27430ec1f2bdb2d38d9577fafec0460e4b4350',
            'viewer' => '1f0fa1c16af2f87127665aabe6e8911a60aa606e142a31c8ebe124ae17c497cf',
        ];
        $actual = [];
        foreach (Role::query()->orderBy('name')->get() as $role) {
            $actual[$role->name] = hash('sha256', json_encode($role->permissions()->orderBy('name')->pluck('name')->all(), JSON_THROW_ON_ERROR));
        }
        self::assertSame($expected, $actual);

        self::assertFalse(Role::query()->where('name', 'general_manager')->exists());
        self::assertTrue(Role::findByName('manager', 'sanctum')->hasPermissionTo('batches.recall'));
        foreach (['viewer', 'operator'] as $name) {
            self::assertFalse(Role::findByName($name, 'sanctum')->hasPermissionTo('batches.view'));
        }
        $before = $this->orderedPermissionSnapshot();
        $this->seed(RolesAndPermissionsSeeder::class);
        self::assertSame($before, $this->orderedPermissionSnapshot());
        self::assertSame('APPLIED', $this->applyDelta()->outcome->value);
        $this->assertCanonicalMatrix();
    }

    public function test_flag_on_fresh_provisioning_uses_canonical_matrix(): void
    {
        DB::table('model_has_roles')->delete();
        DB::table('role_has_permissions')->delete();
        DB::table('roles')->delete();
        DB::table('permissions')->delete();
        config(['lot_action_permissions.enforce' => true]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertCanonicalMatrix();
        $before = $this->orderedPermissionSnapshot();
        $this->seed(RolesAndPermissionsSeeder::class);
        self::assertSame($before, $this->orderedPermissionSnapshot());
    }

    private function assertCanonicalMatrix(): void
    {
        $expectedNames = array_keys(RolesAndPermissionsSeeder::rolePermissionGrants());
        sort($expectedNames);
        self::assertSame($expectedNames, Role::query()->orderBy('name')->pluck('name')->all());
        foreach (RolesAndPermissionsSeeder::rolePermissionGrants() as $name => $expected) {
            $expected = array_values(array_unique($expected));
            sort($expected);
            self::assertSame($expected, Role::findByName($name, 'sanctum')->permissions()->orderBy('name')->pluck('name')->all(), $name);
        }
        $permissions = RolesAndPermissionsSeeder::permissionNames();
        sort($permissions);
        self::assertSame($permissions, DB::table('permissions')->orderBy('name')->pluck('name')->all());
    }

    public function test_viewer_and_operator_gain_batch_view_in_canonical_matrix(): void
    {
        foreach (['viewer', 'operator', 'cashier'] as $role) {
            self::assertContains('batches.view', RolesAndPermissionsSeeder::rolePermissionGrants()[$role]);
        }
    }

    public function test_manager_loses_global_recall_and_gains_request_in_canonical_matrix(): void
    {
        $grants = RolesAndPermissionsSeeder::rolePermissionGrants()['manager'];
        self::assertNotContains('batches.recall', $grants);
        self::assertContains('batches.recall.request', $grants);
    }

    public function test_general_manager_has_the_ruled_permission_delta(): void
    {
        $grants = RolesAndPermissionsSeeder::rolePermissionGrants();
        self::assertArrayHasKey('general_manager', $grants);
        self::assertSame([], array_diff($grants['manager'], $grants['general_manager']));
        self::assertContains('batches.recall', $grants['general_manager']);
        self::assertContains('treasury.manage_all_locations', $grants['general_manager']);
    }
}
