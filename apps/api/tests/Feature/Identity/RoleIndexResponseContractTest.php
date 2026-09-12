<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LotActionPermissionDeltaTest.php';

final class RoleIndexResponseContractTest extends LotActionRoleFixture
{
    public function test_roles_index_wire_keys_are_unchanged_and_is_provisioned_read_only_is_marker_derived(): void
    {
        self::assertSame('APPLIED', $this->applyDelta()->outcome->value);
        $rows = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/roles')->assertOk()->json('data');
        $role = collect($rows)->firstWhere('name', 'general_manager');
        self::assertNotNull($role);
        self::assertSame(['id', 'name', 'guard_name', 'permissions', 'users_count', 'created_at', 'updated_at', 'is_provisioned_read_only'], array_keys($role));
        self::assertTrue($role['is_provisioned_read_only']);
        self::assertFalse(collect($rows)->firstWhere('name', 'manager')['is_provisioned_read_only']);
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        UserCompanyMembership::create(['user_id' => $otherUser->id,
            'company_id' => $otherCompany->id, 'role' => 'admin', 'status' => 'active']);
        setPermissionsTeamId($otherTenant->id);
        $otherUser->assignRole('admin');
        $unmarked = Role::query()->create(['tenant_id' => $otherTenant->id,
            'name' => 'general_manager', 'guard_name' => 'sanctum']);
        app(CompanyContext::class)->setCompanyId($otherCompany->id);
        $otherRows = $this->actingAs($otherUser, 'sanctum')->withHeader('X-Company-ID', $otherCompany->id)
            ->getJson('/api/v1/roles')->assertOk()->json('data');
        $nameOnly = collect($otherRows)->firstWhere('id', $unmarked->id);
        self::assertNotNull($nameOnly);
        self::assertSame(array_keys($role), array_keys($nameOnly));
        self::assertFalse($nameOnly['is_provisioned_read_only']);
        $this->getJson('/api/v1/roles/'.$unmarked->id)->assertOk()->assertJsonPath('data.is_provisioned_read_only', false);
        // API query-wide team filtering is explicitly deferred by the ruling;
        // this asserts wire/marker semantics, not a nonexistent global scope.

    }
}
