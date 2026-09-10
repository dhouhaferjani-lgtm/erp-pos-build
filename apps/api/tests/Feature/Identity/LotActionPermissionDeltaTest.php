<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult;
use App\Modules\Identity\Application\Services\LotActionPermissionDelta;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\Feature\BatchExpiry\BatchPermissionFixture;

require_once __DIR__.'/../BatchExpiry/BatchReadLocationScopeTest.php';

abstract class LotActionRoleFixture extends BatchPermissionFixture
{
    /**
     * Run real application processes against committed, disposable copies of
     * this test's rows. RefreshDatabase's uncommitted fixtures are otherwise
     * invisible to a second PostgreSQL session. No shared fixture is committed.
     *
     * @param  list<array<string, string>>  $jobs
     * @return list<array<string, int|string>>
     */
    protected function race(array $jobs, string $lockSql, array $bindings): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrency harness requires PostgreSQL advisory locks.');
        }
        $schema = 'wlota_race_'.bin2hex(random_bytes(6));
        config(['database.connections.wlota_race' => config('database.connections.'.DB::getDefaultConnection())]);
        $control = DB::connection('wlota_race');
        $processes = [];
        try {
            $control->statement('CREATE SCHEMA "'.$schema.'"');
            $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
            foreach ($tables as $table) {
                $quoted = '"'.str_replace('"', '""', $table->tablename).'"';
                $control->statement('CREATE TABLE "'.$schema.'".'.$quoted.' (LIKE public.'.$quoted.' INCLUDING ALL)');
                $generated = DB::table('information_schema.columns')->where('table_schema', 'public')
                    ->where('table_name', $table->tablename)->where('is_generated', 'ALWAYS')->pluck('column_name')->all();
                $rows = DB::table($table->tablename)->get()->map(static fn ($row): array => array_diff_key((array) $row, array_flip($generated)))->all();
                foreach (array_chunk($rows, 100) as $chunk) {
                    $control->table($schema.'.'.$table->tablename)->insert($chunk);
                }
            }
            $control->statement('SET search_path TO "'.$schema.'"');
            $control->beginTransaction();
            $control->select($lockSql, $bindings);
            $worker = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$job = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['tenancy_resolver.db_per_tenant' => false, 'lot_action_permissions.enforce' => true,
    'queue.default' => 'sync', 'cache.default' => 'array']);
foreach (['pgsql', 'central'] as $connection) {
    config(['database.connections.'.$connection.'.search_path' => $job['schema']]);
    Illuminate\Support\Facades\DB::purge($connection);
}
Illuminate\Support\Facades\DB::setDefaultConnection('pgsql');
Illuminate\Support\Facades\DB::statement("SET application_name = '".$job['application']."'");
setPermissionsTeamId($job['tenant']);
if ($job['action'] === 'delta') {
    $result = $app->make(App\Modules\Identity\Application\Services\LotActionPermissionDelta::class)
        ->apply($job['tenant'], Database\Seeders\RolesAndPermissionsSeeder::permissionNames(), Database\Seeders\RolesAndPermissionsSeeder::rolePermissionGrants());
    echo json_encode(['outcome' => $result->outcome->value], JSON_THROW_ON_ERROR);
} else {
    $actor = App\Modules\Identity\Domain\User::findOrFail($job['actor']);
    Illuminate\Support\Facades\Auth::guard('sanctum')->setUser($actor);
    $app->make(App\Modules\Company\Services\CompanyContext::class)->setCompanyId($job['company']);
    $assign = $job['action'] === 'assign';
    $request = Illuminate\Http\Request::create('/api/v1/users/'.$job['target'].($assign ? '/roles' : ''),
        $assign ? 'POST' : 'PATCH', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_X_COMPANY_ID' => $job['company']], json_encode($assign ? ['role' => 'general_manager'] : ['allowed_location_ids' => [$job['location']]], JSON_THROW_ON_ERROR));
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()], JSON_THROW_ON_ERROR);
    $kernel->terminate($request, $response);
}
PHP;
            foreach ($jobs as $index => $job) {
                $job += ['tenant' => $this->tenant->id, 'schema' => $schema, 'application' => $schema.'_'.$index];
                $process = new Process([PHP_BINARY, '-r', $worker], base_path(),
                    ['DB_CONNECTION' => 'pgsql', 'APP_ENV' => 'testing', 'LOT_ACTION_PERMISSIONS_ENFORCE' => 'false']);
                $process->setInput(json_encode($job, JSON_THROW_ON_ERROR));
                $process->setTimeout(40);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 20;
            do {
                $control->select('SELECT pg_stat_clear_snapshot()');
                $waiting = $control->selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type = 'Lock'", [$schema.'_%']);
                if ((int) $waiting->n === count($jobs)) {
                    break;
                }
                foreach ($processes as $process) {
                    self::assertTrue($process->isRunning(), $process->getErrorOutput().$process->getOutput());
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(count($jobs), (int) $waiting->n, 'Both real workers must reach the database lock barrier');
            $control->commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            if ($jobs[0]['action'] === 'delta') {
                self::assertSame(1, $control->table('roles')->where('tenant_id', $this->tenant->id)->where('provisioning_source', 'w-lot-a-1a')->count());
            } else {
                $target = $jobs[0]['target'];
                $marked = $control->table('roles')->where('provisioning_source', 'w-lot-a-1a')->value('id');
                $assigned = $control->table('model_has_roles')->where('model_id', $target)->where('role_id', $marked)->exists();
                $locations = $control->table('user_company_memberships')->where('user_id', $target)->value('allowed_location_ids');
                self::assertFalse($assigned && $locations !== null, 'Concurrent requests must never leave a location-restricted general manager');
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if ($control->transactionLevel() > 0) {
                $control->rollBack();
            }
            $control->statement('SET search_path TO public');
            $control->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
            DB::purge('wlota_race');
        }
    }

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
    public function test_real_legacy_catalogue_keeps_null_teams_ids_assignments_and_custom_grants(): void
    {
        $roles = DB::table('roles')->orderBy('id')->get(['id', 'tenant_id', 'name'])->all();
        self::assertNotEmpty($roles);
        foreach ($roles as $role) {
            self::assertNull($role->tenant_id);
        }
        $assignments = DB::table('model_has_roles')->orderBy('role_id')->get()->all();
        Permission::findOrCreate('custom.legacy', 'sanctum');
        Role::findByName('manager', 'sanctum')->givePermissionTo('custom.legacy');
        self::assertSame('APPLIED', $this->applyDelta()->outcome->value);
        self::assertEquals($roles, DB::table('roles')->where('name', '!=', 'general_manager')->orderBy('id')->get(['id', 'tenant_id', 'name'])->all());
        self::assertEquals($assignments, DB::table('model_has_roles')->orderBy('role_id')->get()->all());
        self::assertTrue(Role::findByName('manager', 'sanctum')->hasPermissionTo('custom.legacy'));
        $this->assertDatabaseHas('roles', ['name' => 'general_manager', 'tenant_id' => $this->tenant->id, 'provisioning_source' => 'w-lot-a-1a']);
        setPermissionsTeamId(null);
        self::assertSame($roles[0]->id, Role::findByName($roles[0]->name, 'sanctum')->id);
    }

    public function test_tenant_scoped_legacy_catalogue_uses_the_other_resolution_arm(): void
    {
        // Fixture for installations that already have scoped legacy rows.
        DB::table('roles')->update(['tenant_id' => $this->tenant->id]);
        $ids = DB::table('roles')->orderBy('id')->pluck('id')->all();
        self::assertSame('APPLIED', $this->applyDelta()->outcome->value);
        self::assertSame($ids, DB::table('roles')->where('name', '!=', 'general_manager')->orderBy('id')->pluck('id')->all());
        self::assertSame(0, DB::table('roles')->whereNull('tenant_id')->count());
    }

    public function test_mixed_null_and_scoped_legacy_role_collision_rolls_back_without_writes(): void
    {
        DB::table('roles')->insert(['name' => 'manager', 'guard_name' => 'sanctum', 'tenant_id' => $this->tenant->id]);
        $before = $this->orderedPermissionSnapshot();
        $result = $this->applyDelta();
        self::assertSame('FAILED', $result->outcome->value);
        self::assertSame('ambiguous_legacy_role_collision', $result->reason);
        self::assertSame($before, $this->orderedPermissionSnapshot());
    }

    public function test_null_team_unmarked_general_manager_is_never_adopted(): void
    {
        DB::table('roles')->insert(['name' => 'general_manager', 'guard_name' => 'sanctum', 'tenant_id' => null]);
        $before = $this->orderedPermissionSnapshot();
        $result = $this->applyDelta();
        self::assertSame('FAILED', $result->outcome->value);
        self::assertSame('unmarked_general_manager_collision', $result->reason);
        self::assertSame($before, $this->orderedPermissionSnapshot());
    }

    public function test_two_tenants_with_same_role_names_are_discriminated_by_team(): void
    {
        DB::table('roles')->update(['tenant_id' => $this->tenant->id]);
        $other = Tenant::factory()->create();
        $otherManager = Role::query()->create(['name' => 'manager', 'guard_name' => 'sanctum', 'tenant_id' => $other->id]);
        $otherManager->givePermissionTo('batches.recall');
        $before = $otherManager->permissions()->orderBy('name')->pluck('name')->all();
        self::assertSame('APPLIED', $this->applyDelta()->outcome->value);
        self::assertSame($before, $otherManager->fresh()->permissions()->orderBy('name')->pluck('name')->all());
        self::assertSame(0, Role::query()->where('tenant_id', $other->id)->where('name', 'general_manager')->count());
    }

    public function test_each_tenant_team_has_exactly_one_marked_general_manager(): void
    {
        DB::table('roles')->update(['tenant_id' => $this->tenant->id]);
        $other = Tenant::factory()->create();
        self::assertSame('APPLIED', $this->applyDelta()->outcome->value);
        $service = $this->app->make(LotActionPermissionDelta::class);
        self::assertSame('APPLIED', $service->apply($other->id, RolesAndPermissionsSeeder::permissionNames(), RolesAndPermissionsSeeder::rolePermissionGrants())->outcome->value);
        foreach ([$this->tenant->id, $other->id] as $teamId) {
            self::assertSame('ALREADY_APPLIED', $service->apply($teamId, RolesAndPermissionsSeeder::permissionNames(), RolesAndPermissionsSeeder::rolePermissionGrants())->outcome->value);
            self::assertSame(1, Role::query()->where('tenant_id', $teamId)->where('provisioning_source', 'w-lot-a-1a')->count());
        }
    }

    public function test_concurrent_first_apply_creates_one_marked_role(): void
    {
        $results = $this->race([['action' => 'delta'], ['action' => 'delta']],
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['wlota1a:'.$this->tenant->id]);
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        self::assertSame(['ALREADY_APPLIED', 'APPLIED'], $outcomes);
    }

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
        self::assertSame('APPLIED', $this->applyDelta()->outcome->value);
        self::assertSame('33333333-3333-4333-8333-333333333333', $registrar->getPermissionsTeamId());
        DB::table('roles')->insert(['name' => 'manager', 'guard_name' => 'sanctum', 'tenant_id' => $this->tenant->id]);
        $before = $this->orderedPermissionSnapshot();
        self::assertSame('FAILED', $this->applyDelta()->outcome->value);
        self::assertSame($before, $this->orderedPermissionSnapshot());
        self::assertSame('33333333-3333-4333-8333-333333333333', $registrar->getPermissionsTeamId());

    }
}
