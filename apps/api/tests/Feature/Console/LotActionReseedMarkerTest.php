<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Identity\LotActionRoleFixture;

require_once __DIR__.'/../Identity/LotActionPermissionDeltaTest.php';

final class LotActionReseedMarkerTest extends LotActionRoleFixture
{
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
