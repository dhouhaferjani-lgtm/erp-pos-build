<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/LotActionPermissionDeltaTest.php';

final class GeneralManagerAssignmentTest extends LotActionRoleFixture
{
    public function test_concurrent_assignment_and_membership_narrowing_are_serialized(): void
    {
        self::assertSame('APPLIED', $this->applyDelta()->outcome->value);
        $target = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create(['user_id' => $target->id, 'company_id' => $this->company->id,
            'role' => 'viewer', 'allowed_location_ids' => null, 'status' => 'active']);
        $job = ['actor' => $this->user->id, 'company' => $this->company->id, 'target' => $target->id, 'location' => $this->location->id];
        $results = $this->race([['action' => 'assign'] + $job, ['action' => 'narrow'] + $job],
            'SELECT id FROM users WHERE id = ? FOR UPDATE', [$target->id]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame([200, 422], $statuses, json_encode($results, JSON_THROW_ON_ERROR));
        foreach ($results as $result) {
            if ($result['status'] === 422) {
                self::assertStringContainsString('GENERAL_MANAGER_REQUIRES_UNRESTRICTED_MEMBERSHIP', $result['body']);
            }
        }
    }

    public function test_create_update_and_dedicated_assignment_reject_restricted_membership(): void
    {
        $this->provisionMarkedRole();
        $target = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create(['user_id' => $target->id, 'company_id' => $this->company->id,
            'role' => 'viewer', 'allowed_location_ids' => [$this->location->id], 'status' => 'active']);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/users', ['name' => 'Restricted creation', 'email' => 'restricted-create@example.test', 'role' => 'general_manager', 'allowed_location_ids' => [$this->location->id]])
            ->assertStatus(422)->assertJsonPath('error.code', 'GENERAL_MANAGER_REQUIRES_UNRESTRICTED_MEMBERSHIP');
        $this->assertDatabaseMissing('users', ['email' => 'restricted-create@example.test']);
        $this->postJson('/api/v1/users/'.$target->id.'/roles', ['role' => 'general_manager'])
            ->assertStatus(422)->assertJsonPath('error.code', 'GENERAL_MANAGER_REQUIRES_UNRESTRICTED_MEMBERSHIP');
        $this->patchJson('/api/v1/users/'.$target->id, ['role' => 'general_manager'])
            ->assertStatus(422)->assertJsonPath('error.code', 'GENERAL_MANAGER_REQUIRES_UNRESTRICTED_MEMBERSHIP');
    }

    public function test_general_manager_cannot_later_be_location_narrowed(): void
    {
        $this->provisionMarkedRole();
        $target = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create(['user_id' => $target->id, 'company_id' => $this->company->id,
            'role' => 'viewer', 'allowed_location_ids' => null, 'status' => 'active']);
        $target->assignRole('general_manager');
        $this->actingAs($this->user, 'sanctum')->patchJson('/api/v1/users/'.$target->id, ['allowed_location_ids' => [$this->location->id]])
            ->assertStatus(422)->assertJsonPath('error.code', 'GENERAL_MANAGER_REQUIRES_UNRESTRICTED_MEMBERSHIP');
    }

    public function test_unrestricted_assignment_succeeds_after_membership_creation(): void
    {
        Notification::fake();
        $this->provisionMarkedRole();
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Unrestricted creation', 'email' => 'unrestricted-create@example.test',
            'role' => 'general_manager', 'allowed_location_ids' => null,
        ])->assertCreated();
        $target = User::findOrFail($response->json('data.id'));
        self::assertTrue($target->hasRole('general_manager'));
        self::assertNull(UserCompanyMembership::where('user_id', $target->id)->firstOrFail()->allowed_location_ids);
    }

    public function test_atomic_demotion_and_location_narrowing_succeeds(): void
    {
        $this->provisionMarkedRole();
        $target = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create(['user_id' => $target->id, 'company_id' => $this->company->id,
            'role' => 'viewer', 'allowed_location_ids' => null, 'status' => 'active']);
        $target->assignRole('general_manager');
        $this->actingAs($this->user, 'sanctum')->patchJson('/api/v1/users/'.$target->id, [
            'role' => 'viewer', 'allowed_location_ids' => [$this->location->id],
        ])->assertOk();
        self::assertFalse($target->fresh()->hasRole('general_manager'));
        self::assertSame([$this->location->id], UserCompanyMembership::where('user_id', $target->id)->firstOrFail()->allowed_location_ids);
    }

    public function test_second_company_selected_b2_membership_must_also_be_unrestricted(): void
    {
        $this->provisionMarkedRole();
        $other = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        Location::factory()->create(['company_id' => $other->id]);
        $b2 = Location::factory()->create(['company_id' => $other->id, 'pos_enabled' => true]);
        $target = User::factory()->create(['tenant_id' => $this->tenant->id]);
        foreach ([$this->company->id => null, $other->id => [$b2->id]] as $companyId => $scope) {
            UserCompanyMembership::create(['user_id' => $target->id, 'company_id' => $companyId,
                'role' => 'viewer', 'allowed_location_ids' => $scope, 'status' => 'active']);
        }
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/users/'.$target->id.'/roles', ['role' => 'general_manager'])
            ->assertStatus(422)->assertJsonPath('error.code', 'GENERAL_MANAGER_REQUIRES_UNRESTRICTED_MEMBERSHIP');
        self::assertFalse($target->fresh()->hasRole('general_manager'));
    }

    public function test_dedicated_assignment_restores_previous_permission_team(): void
    {
        $this->provisionMarkedRole();
        $target = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create(['user_id' => $target->id, 'company_id' => $this->company->id,
            'role' => 'viewer', 'allowed_location_ids' => null, 'status' => 'active']);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/users/'.$target->id.'/roles', ['role' => 'general_manager'])->assertOk();
        self::assertSame($this->tenant->id, app(PermissionRegistrar::class)->getPermissionsTeamId());
        self::assertTrue($target->fresh()->hasRole('general_manager'));
    }
}
