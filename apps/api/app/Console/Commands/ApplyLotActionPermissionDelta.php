<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Application\Services\LotActionPermissionDelta;
use App\Modules\Identity\Domain\Enums\LotActionPermissionDeltaOutcome;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;

final class ApplyLotActionPermissionDelta extends Command
{
    protected $signature = 'permissions:apply-lot-action-delta
        {--apply : Apply the permission and role delta}
        {--verify : Verify the permission and role delta without writing}';

    protected $description = 'Apply or verify W-LOT-A-1a lot-action permissions and the seeded general-manager role.';

    public function __construct(private readonly LotActionPermissionDelta $delta)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = tenant('id') ?? getPermissionsTeamId();
        $tenantId = is_string($tenantId) && $tenantId !== '' ? $tenantId : 'none';
        $apply = $this->optionEnabled($this->option('apply'));
        $verify = $this->optionEnabled($this->option('verify'));
        $mode = $verify && ! $apply ? 'VERIFY' : 'APPLY';
        $prefix = "WLOTA1A-PERMISSIONS tenant={$tenantId} mode={$mode}";
        if ($apply === $verify) {
            $this->line($prefix.' outcome=FAILED reason=invalid_mode');

            return 2;
        }
        if ($tenantId === 'none') {
            $this->line($prefix.' outcome=FAILED reason=missing_tenant_context');

            return 1;
        }
        try {
            $result = $apply
                ? $this->delta->apply($tenantId, RolesAndPermissionsSeeder::permissionNames(), RolesAndPermissionsSeeder::rolePermissionGrants())
                : $this->delta->verify($tenantId, RolesAndPermissionsSeeder::permissionNames(), RolesAndPermissionsSeeder::rolePermissionGrants());
            $this->line($prefix." outcome={$result->outcome->value} reason={$result->reason}");

            return in_array($result->outcome, [LotActionPermissionDeltaOutcome::Applied, LotActionPermissionDeltaOutcome::AlreadyApplied], true) ? 0 : 1;
        } catch (\Throwable $exception) {
            report($exception);
            $this->line($prefix.' outcome=FAILED reason=invariant_failure');

            return 1;
        }
    }

    private function optionEnabled(bool|int|string|null $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
