<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

require_once __DIR__.'/LotActionPermissionDeltaTest.php';

final class RoleIndexResponseContractTest extends LotActionRoleFixture
{
    public function test_roles_index_wire_keys_are_unchanged_and_is_provisioned_read_only_is_marker_derived(): void
    {
        $this->provisionMarkedRole();
        $rows = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/roles')->assertOk()->json('data');
        $role = collect($rows)->firstWhere('name', 'general_manager');
        self::assertNotNull($role);
        self::assertSame(['id', 'name', 'guard_name', 'permissions', 'users_count', 'created_at', 'updated_at', 'is_provisioned_read_only'], array_keys($role));
        self::assertTrue($role['is_provisioned_read_only']);
        self::assertFalse(collect($rows)->firstWhere('name', 'manager')['is_provisioned_read_only']);
    }
}
