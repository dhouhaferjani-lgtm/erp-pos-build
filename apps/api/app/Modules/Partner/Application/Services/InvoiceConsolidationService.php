<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\Services;

use App\Modules\Partner\Domain\Enums\ConsolidationFrequency;
use App\Modules\Partner\Domain\Partner;
use Carbon\Carbon;

final class InvoiceConsolidationService
{
    /**
     * Determine whether invoices should be consolidated for the given partner.
     */
    public function shouldConsolidate(Partner $partner): bool
    {
        return $partner->invoice_consolidation
            && $partner->consolidation_frequency !== null;
    }

    /**
     * Calculate the next consolidation date based on the partner's frequency setting.
     *
     * Returns null if consolidation is not enabled or no frequency is set.
     */
    public function getNextConsolidationDate(Partner $partner): ?Carbon
    {
        if (! $this->shouldConsolidate($partner)) {
            return null;
        }

        /** @var ConsolidationFrequency $frequency */
        $frequency = $partner->consolidation_frequency;
        $now = Carbon::now();

        return match ($frequency) {
            ConsolidationFrequency::Weekly => $now->copy()->next(Carbon::MONDAY),
            ConsolidationFrequency::Monthly => $now->copy()->startOfMonth()->addMonth(),
        };
    }
}
