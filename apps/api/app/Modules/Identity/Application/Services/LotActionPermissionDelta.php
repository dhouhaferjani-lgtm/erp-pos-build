<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult;
use App\Modules\Identity\Domain\Enums\LotActionPermissionDeltaOutcome as Outcome;
use App\Modules\Identity\Domain\Enums\RoleProvisioningSource;
use App\Modules\Identity\Domain\Enums\SystemRoleName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final readonly class LotActionPermissionDelta
{
    public function __construct(private readonly PermissionRegistrar $permissionRegistrar) {}

    /**
     * @param  list<string>  $permissionNames
     * @param  array<string, list<string>>  $rolePermissionGrants
     */
    public function apply(string $tenantId, array $permissionNames, array $rolePermissionGrants): LotActionPermissionDeltaResult
    {
        return $this->execute($tenantId, $permissionNames, $rolePermissionGrants, true);
    }

    /**
     * @param  list<string>  $permissionNames
     * @param  array<string, list<string>>  $rolePermissionGrants
     */
    public function verify(string $tenantId, array $permissionNames, array $rolePermissionGrants): LotActionPermissionDeltaResult
    {
        return $this->execute($tenantId, $permissionNames, $rolePermissionGrants, false);
    }

    /**
     * @param  list<string>  $permissionNames
     * @param  array<string, list<string>>  $rolePermissionGrants
     */
    private function execute(string $tenantId, array $permissionNames, array $rolePermissionGrants, bool $write): LotActionPermissionDeltaResult
    {
        $previousTeamId = $this->permissionRegistrar->getPermissionsTeamId();
        $this->permissionRegistrar->setPermissionsTeamId($tenantId);
        try {
            return DB::transaction(function () use ($tenantId, $permissionNames, $rolePermissionGrants, $write): LotActionPermissionDeltaResult {
                if (! Schema::hasColumn('roles', 'provisioning_source')) {
                    throw new \DomainException('missing_schema');
                }
                $this->acquireTenantLock($tenantId);
                $team = config('permission.column_names.team_foreign_key');
                $roles = Role::query()->where($team, $tenantId)->where('guard_name', 'sanctum')->orderBy('name')->orderBy('id')->lockForUpdate()->get()->keyBy('name');
                $generalManager = $roles->get(SystemRoleName::GeneralManager->value);
                if ($generalManager !== null && $generalManager->getAttribute('provisioning_source') !== RoleProvisioningSource::Wlota1a->value) {
                    throw new \DomainException('unmarked_general_manager_collision');
                }
                if (Role::query()->whereNull($team)->whereNotNull('provisioning_source')->exists()) {
                    throw new \DomainException('invalid_global_marked_role');
                }
                $changed = false;
                foreach ($permissionNames as $name) {
                    if (! Permission::query()->where('name', $name)->where('guard_name', 'sanctum')->exists()) {
                        if (! $write) {
                            throw new \DomainException('canonical_state_mismatch');
                        }
                        Permission::create(['name' => $name, 'guard_name' => 'sanctum']);
                        $changed = true;
                    }
                }
                foreach ($rolePermissionGrants as $name => $permissions) {
                    $role = $roles->get($name);
                    $newRole = $role === null;
                    if ($name === SystemRoleName::GeneralManager->value) {
                        $role = $this->provisionGeneralManager($permissions, $write);
                    } elseif ($role === null) {
                        if (! $write) {
                            throw new \DomainException('canonical_state_mismatch');
                        }
                        $role = Role::create(['name' => $name, 'guard_name' => 'sanctum', $team => $tenantId]);
                        assert($role instanceof Role);
                    }
                    $existing = $role->permissions()->toBase()->pluck('name')->all();
                    $changed = $changed || $newRole || array_diff($permissions, $existing) !== []
                        || ($name === 'manager' && in_array('batches.recall', $existing, true));
                    $this->synchronizeSeededRole($role, $permissions, $newRole, $write);
                }
                $this->verifyCanonicalState($tenantId, $permissionNames, $rolePermissionGrants);

                return new LotActionPermissionDeltaResult($changed ? Outcome::Applied : Outcome::AlreadyApplied,
                    $changed ? 'canonical_delta_applied' : 'canonical_state_matches');
            });
        } catch (\DomainException $exception) {
            return new LotActionPermissionDeltaResult(Outcome::Failed, $exception->getMessage());
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId($previousTeamId);
        }
    }

    private function acquireTenantLock(string $tenantId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['wlota1a:'.$tenantId]);
        }
    }

    /** @param list<string> $permissions */
    private function provisionGeneralManager(array $permissions, bool $write): Role
    {
        $team = config('permission.column_names.team_foreign_key');
        $role = Role::query()->where($team, $this->permissionRegistrar->getPermissionsTeamId())
            ->where('name', SystemRoleName::GeneralManager->value)->where('guard_name', 'sanctum')->lockForUpdate()->first();
        if ($role !== null) {
            if ($role->getAttribute('provisioning_source') !== RoleProvisioningSource::Wlota1a->value) {
                throw new \DomainException('unmarked_general_manager_collision');
            }

            return $role;
        }
        if (! $write) {
            throw new \DomainException('canonical_state_mismatch');
        }
        $role = Role::create(['name' => SystemRoleName::GeneralManager->value, 'guard_name' => 'sanctum',
            $team => $this->permissionRegistrar->getPermissionsTeamId(), 'provisioning_source' => RoleProvisioningSource::Wlota1a->value]);
        assert($role instanceof Role);
        $role->givePermissionTo($permissions);

        return $role;
    }

    /** @param list<string> $permissions */
    private function synchronizeSeededRole(Role $role, array $permissions, bool $newRole, bool $write): void
    {
        $existing = $role->permissions()->toBase()->pluck('name')->all();
        $missing = array_values(array_diff($permissions, $existing));
        $removeRecall = $role->name === 'manager' && in_array('batches.recall', $existing, true);
        if ($missing === [] && ! $removeRecall) {
            return;
        }
        if (! $write) {
            throw new \DomainException('canonical_state_mismatch');
        }
        if ($newRole) {
            $role->syncPermissions($permissions);
        } elseif ($missing !== []) {
            $role->givePermissionTo($missing);
        }
        if ($removeRecall) {
            $role->revokePermissionTo('batches.recall');
        }
    }

    /**
     * @param  list<string>  $permissionNames
     * @param  array<string, list<string>>  $rolePermissionGrants
     */
    private function verifyCanonicalState(string $tenantId, array $permissionNames, array $rolePermissionGrants): void
    {
        $team = config('permission.column_names.team_foreign_key');
        if (Role::query()->where($team, $tenantId)->where('name', SystemRoleName::GeneralManager->value)
            ->where('guard_name', 'sanctum')->whereRaw('provisioning_source = ?', [RoleProvisioningSource::Wlota1a->value])->count() !== 1) {
            throw new \DomainException('canonical_state_mismatch');
        }
        foreach ($rolePermissionGrants as $name => $permissions) {
            $role = Role::query()->where($team, $tenantId)->where('name', $name)->where('guard_name', 'sanctum')->first();
            $actual = $role?->permissions()->toBase()->pluck('name')->all() ?? [];
            if (array_diff($permissions, $actual) !== [] || ($name === 'manager' && in_array('batches.recall', $actual, true))) {
                throw new \DomainException('canonical_state_mismatch');
            }
        }
        if (array_diff($permissionNames, Permission::query()->where('guard_name', 'sanctum')->pluck('name')->all()) !== []) {
            throw new \DomainException('canonical_state_mismatch');
        }
    }
}
