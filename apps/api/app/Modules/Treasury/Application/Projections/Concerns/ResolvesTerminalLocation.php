<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Projections\Concerns;

use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Treasury\Application\Services\TenderRepositoryResolver;
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

    /**
     * The location a REPOSITORY should be resolved against — never null while
     * the company has any location at all.
     *
     * N-12 gate r1 finding D. `resolveTerminalLocationId()` above answers a
     * different question: what location does this payment BELONG to, for
     * `payments.location_id`. Null there is honest — a server-authored deposit
     * has no terminal and falls back to the repository's own location. But null
     * reaching {@see TenderRepositoryResolver}
     * means "no location known", which is the historical company-wide set,
     * which post-backfill is ordered `cash_register` first — i.e. MAIN's till.
     * So the one degradation path the lane left open produced exactly the
     * cross-branch commingling the lane exists to prevent, silently.
     *
     * A terminal that cannot be read therefore resolves against the company's
     * DEFAULT location — the same location day-one attribution uses — and if
     * that location has no drawer, the caller refuses loudly and replayably
     * instead of borrowing one.
     */
    private function resolveRepositoryLocationId(FiscalEvent $event): ?string
    {
        $terminalLocationId = $this->resolveTerminalLocationId($event);
        if (is_string($terminalLocationId) && $terminalLocationId !== '') {
            return $terminalLocationId;
        }

        try {
            // `is_default`, then an active one, then a POS-enabled one, then the
            // oldest — byte-for-byte `PaymentRepositorySeeder::defaultLocationId()`
            // and the N-12 backfill, so "the default location" means one thing.
            $locationId = DB::table('locations')
                ->where('company_id', $event->company_id)
                ->orderByDesc('is_default')
                ->orderByDesc('is_active')
                ->orderByDesc('pos_enabled')
                ->orderBy('created_at')
                ->value('id');
        } catch (QueryException $exception) {
            Log::warning('Treasury default location lookup failed', [
                'fiscal_event_id' => $event->id,
                'company_id' => $event->company_id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        return is_string($locationId) ? $locationId : null;
    }
}
