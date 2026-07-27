<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Projections\Concerns;

use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait ResolvesTerminalLocation
{
    private function resolveTerminalLocationId(FiscalEvent $event): ?string
    {
        try {
            $locationId = DB::table('pos_terminals')
                ->where('tenant_id', $event->tenant_id)
                ->where('company_id', $event->company_id)
                ->where('id', $event->terminal_id)
                ->value('location_id');
        } catch (QueryException $exception) {
            Log::warning('Treasury terminal location lookup failed', [
                'fiscal_event_id' => $event->id,
                'terminal_id' => $event->terminal_id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        return is_string($locationId) ? $locationId : null;
    }
}
