<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\PlansSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Psr\Log\LoggerInterface;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class LotActionReseedMarkerTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshInTransaction;
    }

    private ?Tenant $tenant = null;

    private function isRegistrationCase(): bool
    {
        return $this->name() === 'test_fresh_registration_writes_activated_applied_marker_to_stderr_channel_despite_artisan_buffering';
    }

    public function refreshDatabase(): void
    {
        if (! $this->isRegistrationCase()) {
            $this->refreshInTransaction();
        } elseif (! Schema::connection('central')->hasTable('tenants')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->isRegistrationCase()) {
            $this->tenant = Tenant::factory()->create();
            setPermissionsTeamId($this->tenant->id);
            $this->seed(RolesAndPermissionsSeeder::class);
        }
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        try {
            if ($this->isRegistrationCase() && $this->tenant !== null) {
                $database = $this->tenant->getDatabaseName();
                DB::purge('tenant');
                $this->tenant->database()->manager()->deleteDatabase($this->tenant);
                self::assertNull(DB::connection('central')->selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [$database]));
                foreach (['domains', 'central_identities', 'tenant_subscriptions'] as $table) {
                    DB::connection('central')->table($table)->where('tenant_id', $this->tenant->id)->delete();
                }
                DB::connection('central')->table('personal_access_tokens')->where('abilities', 'like', '%tenant:'.$this->tenant->id.'%')->delete();
                DB::connection('central')->table('tenants')->where('id', $this->tenant->id)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function orderedPermissionSnapshot(): array
    {
        return array_map(static fn (string $table): array => DB::table($table)->orderBy($table === 'role_has_permissions' ? 'role_id' : 'id')
            ->when($table === 'role_has_permissions', fn ($query) => $query->orderBy('permission_id'))->get()->map(static fn ($row): array => (array) $row)->all(),
            ['permissions', 'roles', 'role_has_permissions']);
    }

    public function test_fresh_registration_writes_activated_applied_marker_to_stderr_channel_despite_artisan_buffering(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        config(['tenancy_resolver.db_per_tenant' => true, 'lot_action_permissions.enforce' => true]);
        $this->seed(PlansSeeder::class);
        Notification::fake();
        $runId = (string) Str::uuid();
        Tenant::created(function (Tenant $tenant): void {
            $this->tenant = $tenant;
        });
        $stderrLogger = Mockery::spy(LoggerInterface::class);
        $logManager = Mockery::mock(Log::getFacadeRoot())->shouldDeferMissing();
        $logManager->shouldReceive('channel')->with('stderr')->andReturn($stderrLogger);
        Log::swap($logManager);
        ob_start();
        try {
            $response = $this->postJson('/api/v1/auth/register', [
                'name' => 'WLOTA registration', 'email' => "wlota-{$runId}@example.test",
                'password' => 'MyStr0ng!Pass', 'password_confirmation' => 'MyStr0ng!Pass',
                'company_name' => "WLOTA {$runId}", 'country_code' => 'FR', 'vertical' => 'retail',
            ]);
            $stdout = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $response->assertCreated();
        self::assertSame('', $stdout);
        $tenantId = $response->json('data.user.tenantId');
        self::assertSame($this->tenant->id, $tenantId);
        $stderrLogger->shouldHaveReceived('info')->once()->with("WLOTA1A-RESEED tenant={$tenantId} mode=ACTIVATED outcome=APPLIED reason=canonical_delta_applied");
        $this->tenant->run(function () use ($tenantId): void {
            $this->assertDatabaseHas('roles', ['name' => 'general_manager', 'tenant_id' => $tenantId, 'provisioning_source' => 'w-lot-a-1a']);
        });
    }

    private function emptyRoleFixture(): void
    {
        // Fresh-provisioning fixture. Never repairs or reassigns legacy rows.
        DB::table('model_has_roles')->delete();
        DB::table('role_has_permissions')->delete();
        DB::table('roles')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_flag_off_seeder_emits_one_legacy_marker_without_canonical_role_changes(): void
    {
        $before = $this->orderedPermissionSnapshot();
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('stderr')->once()->andReturn($logger);
        $logger->shouldReceive('info')->once()->with("WLOTA1A-RESEED tenant={$this->tenant->id} mode=LEGACY outcome=ALREADY_APPLIED reason=enforcement_off");
        $this->seed(RolesAndPermissionsSeeder::class);
        self::assertSame($before, $this->orderedPermissionSnapshot());
    }

    public function test_flag_on_fresh_seed_emits_one_activated_applied_marker(): void
    {
        $this->emptyRoleFixture();
        config(['lot_action_permissions.enforce' => true]);
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('stderr')->once()->andReturn($logger);
        $logger->shouldReceive('info')->once()->with("WLOTA1A-RESEED tenant={$this->tenant->id} mode=ACTIVATED outcome=APPLIED reason=canonical_delta_applied");
        self::assertSame(0, Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]));
        self::assertStringContainsString('outcome=APPLIED', Artisan::output());
        $this->assertDatabaseHas('roles', ['tenant_id' => $this->tenant->id, 'name' => 'general_manager', 'provisioning_source' => 'w-lot-a-1a']);
    }

    public function test_activated_reseed_emits_one_already_applied_marker(): void
    {
        $this->emptyRoleFixture();
        config(['lot_action_permissions.enforce' => true]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $before = $this->orderedPermissionSnapshot();
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('stderr')->once()->andReturn($logger);
        $logger->shouldReceive('info')->once()->with("WLOTA1A-RESEED tenant={$this->tenant->id} mode=ACTIVATED outcome=ALREADY_APPLIED reason=canonical_state_matches");
        $this->seed(RolesAndPermissionsSeeder::class);
        self::assertSame($before, $this->orderedPermissionSnapshot());
    }
}
