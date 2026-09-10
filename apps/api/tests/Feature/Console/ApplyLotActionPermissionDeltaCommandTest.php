<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Identity\LotActionRoleFixture;

require_once __DIR__.'/../Identity/LotActionPermissionDeltaTest.php';

final class ApplyLotActionPermissionDeltaCommandTest extends LotActionRoleFixture
{
    public function test_apply_emits_exact_applied_marker_and_exits_zero(): void
    {
        $this->artisan('permissions:apply-lot-action-delta', ['--apply' => true])
            ->expectsOutputToContain("WLOTA1A-PERMISSIONS tenant={$this->tenant->id} mode=APPLY outcome=APPLIED reason=canonical_delta_applied")->assertExitCode(0);
    }

    public function test_verify_emits_exact_already_applied_marker_and_is_read_only(): void
    {
        $this->applyDelta();
        $before = $this->orderedPermissionSnapshot();
        $this->artisan('permissions:apply-lot-action-delta', ['--verify' => true])
            ->expectsOutputToContain("WLOTA1A-PERMISSIONS tenant={$this->tenant->id} mode=VERIFY outcome=ALREADY_APPLIED reason=canonical_state_matches")->assertExitCode(0);
        self::assertSame($before, $this->orderedPermissionSnapshot());
    }

    public function test_tenants_run_apply_string_one_selects_apply_mode(): void
    {
        $this->artisan('tenants:run', ['commandname' => 'permissions:apply-lot-action-delta',
            '--tenants' => [$this->tenant->id], '--option' => ['apply=1']])
            ->expectsOutputToContain("WLOTA1A-PERMISSIONS tenant={$this->tenant->id} mode=APPLY outcome=APPLIED reason=canonical_delta_applied")
            ->assertExitCode(0);
    }

    public function test_tenants_run_verify_string_one_selects_verify_mode(): void
    {
        self::assertSame('APPLIED', $this->applyDelta()->outcome->value);
        $before = $this->orderedPermissionSnapshot();
        $this->artisan('tenants:run', ['commandname' => 'permissions:apply-lot-action-delta',
            '--tenants' => [$this->tenant->id], '--option' => ['verify=1']])
            ->expectsOutputToContain("WLOTA1A-PERMISSIONS tenant={$this->tenant->id} mode=VERIFY outcome=ALREADY_APPLIED reason=canonical_state_matches")
            ->assertExitCode(0);
        self::assertSame($before, $this->orderedPermissionSnapshot());
    }

    public function test_unmarked_collision_emits_failed_marker_and_exits_one(): void
    {
        DB::table('roles')->insert(['name' => 'general_manager', 'guard_name' => 'sanctum', 'tenant_id' => null]);
        $before = $this->orderedPermissionSnapshot();
        $this->artisan('permissions:apply-lot-action-delta', ['--apply' => true])
            ->expectsOutputToContain("WLOTA1A-PERMISSIONS tenant={$this->tenant->id} mode=APPLY outcome=FAILED reason=unmarked_general_manager_collision")
            ->assertExitCode(1);
        self::assertSame($before, $this->orderedPermissionSnapshot());
    }

    public function test_mixed_legacy_collision_emits_failed_marker_and_exits_one(): void
    {
        DB::table('roles')->insert(['name' => 'manager', 'guard_name' => 'sanctum', 'tenant_id' => $this->tenant->id]);
        $before = $this->orderedPermissionSnapshot();
        $this->artisan('permissions:apply-lot-action-delta', ['--apply' => true])
            ->expectsOutputToContain('outcome=FAILED reason=ambiguous_legacy_role_collision')->assertExitCode(1);
        self::assertSame($before, $this->orderedPermissionSnapshot());
    }

    public function test_missing_schema_emits_failed_marker_and_exits_one(): void
    {
        Schema::table('roles', fn ($table) => $table->dropColumn('provisioning_source'));
        $before = $this->orderedPermissionSnapshot();
        $this->artisan('permissions:apply-lot-action-delta', ['--apply' => true])
            ->expectsOutputToContain("WLOTA1A-PERMISSIONS tenant={$this->tenant->id} mode=APPLY outcome=FAILED reason=missing_schema")
            ->assertExitCode(1);
        self::assertSame($before, $this->orderedPermissionSnapshot());
    }

    public function test_neither_mode_emits_invalid_mode_and_exits_two(): void
    {
        $this->artisan('permissions:apply-lot-action-delta')->expectsOutputToContain('outcome=FAILED reason=invalid_mode')->assertExitCode(2);
    }

    public function test_both_modes_emit_invalid_mode_and_exit_two(): void
    {
        $this->artisan('permissions:apply-lot-action-delta', ['--apply' => true, '--verify' => true])->expectsOutputToContain('outcome=FAILED reason=invalid_mode')->assertExitCode(2);
    }
}
