<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult;
use App\Modules\Identity\Application\Services\LotActionPermissionDelta;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\BatchExpiry\BatchPermissionFixture;

require_once __DIR__.'/../BatchExpiry/BatchReadLocationScopeTest.php';

abstract class LotActionRoleFixture extends BatchPermissionFixture
{
    protected function applyDelta(): LotActionPermissionDeltaResult
    {
        return $this->app->make(LotActionPermissionDelta::class)->apply($this->tenant->id,
            RolesAndPermissionsSeeder::permissionNames(), RolesAndPermissionsSeeder::rolePermissionGrants());
    }

    protected function provisionMarkedRole(): void
    {
        Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'general_manager',
            'guard_name' => 'sanctum', 'provisioning_source' => 'w-lot-a-1a']);
    }

    protected function orderedPermissionSnapshot(): array
    {
        return array_map(static fn (string $table): array => DB::table($table)->orderBy($table === 'role_has_permissions' ? 'role_id' : 'id')
            ->when($table === 'role_has_permissions', fn ($query) => $query->orderBy('permission_id'))->get()->map(static fn ($row): array => (array) $row)->all(),
            ['permissions', 'roles', 'role_has_permissions']);
    }
}

final class LotActionPermissionDeltaTest extends LotActionRoleFixture
{
    public function test_first_apply_and_second_apply_have_explicit_outcomes(): void
    {
        self::assertSame('APPLIED', $this->applyDelta()->outcome->value);
        self::assertSame('ALREADY_APPLIED', $this->applyDelta()->outcome->value);
    }

    public function test_rerun_preserves_role_id_and_custom_permissions(): void
    {
        $this->applyDelta();
        $role = Role::findByName('general_manager', 'sanctum');
        $id = $role->id;
        Permission::findOrCreate('custom.wlota1a', 'sanctum');
        $role->givePermissionTo('custom.wlota1a');
        $before = $this->orderedPermissionSnapshot();
        $this->applyDelta();
        self::assertSame($id, Role::findByName('general_manager', 'sanctum')->id);
        self::assertSame($before, $this->orderedPermissionSnapshot());
    }

    public function test_unmarked_general_manager_collision_fails_closed(): void
    {
        Role::create(['name' => 'general_manager', 'guard_name' => 'sanctum']);
        $before = $this->orderedPermissionSnapshot();
        self::assertSame('FAILED', $this->applyDelta()->outcome->value);
        self::assertSame($before, $this->orderedPermissionSnapshot());
    }

    public function test_verify_is_read_only(): void
    {
        $this->applyDelta();
        $before = $this->orderedPermissionSnapshot();
        $result = $this->app->make(LotActionPermissionDelta::class)->verify($this->tenant->id,
            RolesAndPermissionsSeeder::permissionNames(), RolesAndPermissionsSeeder::rolePermissionGrants());
        self::assertSame('ALREADY_APPLIED', $result->outcome->value);
        self::assertSame($before, $this->orderedPermissionSnapshot());
    }

    public function test_transaction_restores_previous_permission_team_on_success_and_exception(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId('33333333-3333-4333-8333-333333333333');
        $this->applyDelta();
        self::assertSame('33333333-3333-4333-8333-333333333333', $registrar->getPermissionsTeamId());
    }
}
