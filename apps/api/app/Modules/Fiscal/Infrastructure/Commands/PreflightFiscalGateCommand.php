<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * @cross-tenant-by-design Audits all POS fiscal surfaces before schema-destructive fiscal rebuild tasks.
 */
final class PreflightFiscalGateCommand extends Command
{
    protected $signature = 'fiscal:preflight-gate';

    protected $description = 'Verify fiscal surfaces before POS fiscal event rebuild work begins.';

    public function handle(): int
    {
        try {
            $serverFindings = $this->serverSurfaceFindings();
        } catch (Throwable $exception) {
            $this->line('SERVER SURFACE: unable to verify - database query failed');
            $this->line('DEVICE SURFACE: requires manual inventory - record per-terminal SQLite findings in the sign-off');
            $this->line($this->webPosPathLive()
                ? 'WEB-POS SURFACE: live receipt-creation path detected - disposition per section 14.2'
                : 'WEB-POS SURFACE: no live receipt-creation path');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $serverClear = array_sum($serverFindings) === 0;

        $this->line($serverClear ? 'SERVER SURFACE: clear' : 'SERVER SURFACE: NON-EMPTY - see report');
        $this->reportServerFindings($serverFindings);
        $this->line('DEVICE SURFACE: requires manual inventory - record per-terminal SQLite findings in the sign-off');
        $this->line($this->webPosPathLive()
            ? 'WEB-POS SURFACE: live receipt-creation path detected - disposition per section 14.2'
            : 'WEB-POS SURFACE: no live receipt-creation path');

        return $serverClear ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, int>
     */
    private function serverSurfaceFindings(): array
    {
        return [
            'pos_receipts' => $this->tableCount('pos_receipts'),
            'pos_z_reports' => $this->tableCount('pos_z_reports'),
            'pos_receipt_prints' => $this->tableCount('pos_receipt_prints'),
            'pos_terminals_with_chain_state' => $this->terminalChainStateCount(),
        ];
    }

    /**
     * @param  array<string, int>  $serverFindings
     */
    private function reportServerFindings(array $serverFindings): void
    {
        foreach ($serverFindings as $source => $count) {
            if ($count === 0) {
                continue;
            }

            $this->line(sprintf('  - %s: %d', $source, $count));
        }
    }

    private function tableCount(string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return DB::table($table)->count();
    }

    private function terminalChainStateCount(): int
    {
        if (! Schema::hasTable('pos_terminals')) {
            return 0;
        }

        return DB::table('pos_terminals')
            ->whereNotNull('last_hash')
            ->orWhere('current_sequence', '>', 0)
            ->count();
    }

    private function webPosPathLive(): bool
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('POST', $route->methods(), true)) {
                continue;
            }

            if (in_array($route->uri(), ['pos/receipts', 'api/v1/pos/receipts'], true)) {
                return true;
            }
        }

        return false;
    }
}
