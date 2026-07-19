<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Projections\Concerns;

use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
        } catch (QueryException) {
            return null;
        }

        return is_string($locationId) ? $locationId : null;
    }
}
