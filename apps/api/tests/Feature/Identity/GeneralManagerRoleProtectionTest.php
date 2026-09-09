<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Spatie\Permission\Models\Role;

require_once __DIR__.'/LotActionPermissionDeltaTest.php';

final class GeneralManagerRoleProtectionTest extends LotActionRoleFixture
{
    public function test_marked_general_manager_permissions_cannot_be_synchronized_through_role_update(): void
    {
        $this->rejectUpdate(['permissions' => ['batches.view']]);
    }

    public function test_marked_general_manager_name_cannot_be_changed_through_role_update(): void
    {
        $this->rejectUpdate(['name' => 'renamed']);
    }

    public function test_marked_general_manager_cannot_be_deleted(): void
    {
        $this->provisionMarkedRole();
        $role = Role::findByName('general_manager', 'sanctum');
        $before = $this->orderedPermissionSnapshot();
        $this->actingAs($this->user, 'sanctum')->deleteJson('/api/v1/roles/'.$role->id)
            ->assertStatus(422)->assertJsonPath('error.code', 'PROVISIONED_ROLE_READ_ONLY');
        self::assertSame($before, $this->orderedPermissionSnapshot());
    }

    private function rejectUpdate(array $payload): void
    {
        $this->provisionMarkedRole();
        $role = Role::findByName('general_manager', 'sanctum');
        $before = $this->orderedPermissionSnapshot();
        $this->actingAs($this->user, 'sanctum')->patchJson('/api/v1/roles/'.$role->id, $payload)
            ->assertStatus(422)->assertJsonPath('error.code', 'PROVISIONED_ROLE_READ_ONLY');
        self::assertSame($before, $this->orderedPermissionSnapshot());
    }
}
