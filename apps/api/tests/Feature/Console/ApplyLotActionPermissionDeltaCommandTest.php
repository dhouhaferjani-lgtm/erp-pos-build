<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

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

    public function test_neither_mode_emits_invalid_mode_and_exits_two(): void
    {
        $this->artisan('permissions:apply-lot-action-delta')->expectsOutputToContain('outcome=FAILED reason=invalid_mode')->assertExitCode(2);
    }

    public function test_both_modes_emit_invalid_mode_and_exit_two(): void
    {
        $this->artisan('permissions:apply-lot-action-delta', ['--apply' => true, '--verify' => true])->expectsOutputToContain('outcome=FAILED reason=invalid_mode')->assertExitCode(2);
    }
}
