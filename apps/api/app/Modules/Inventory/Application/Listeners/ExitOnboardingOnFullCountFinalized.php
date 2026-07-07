<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Listeners;

use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Task C3 — onboarding auto-exit.
 *
 * A location in `onboarding_mode` (sell-before-count) exits that mode on its
 * own once a whole-location count has been finalized against the FULL active
 * catalog: a finalized `full_inventory` or `location` scoped counting whose
 * `includes_zero_stock` flag is true (set at generation time — C1) proves
 * every product at the location, including never-received and negative-stock
 * lines, was accounted for. Zone/product/category scoped counts only ever
 * cover a partial slice of the catalog and never auto-exit onboarding.
 *
 * Queued like its sibling {@see ApplyStockAdjustmentsOnCountingCompleted}.
 * Deliberately reads no `CompanyContext` (rule 20) — every value it needs
 * (company id, target locations) comes from the counting/its items, which is
 * also more robust than re-deriving scope_filters location ids: it reflects
 * exactly which locations the counting actually seeded items for.
 */
final class ExitOnboardingOnFullCountFinalized implements ShouldQueue
{
    /**
     * The number of times the queued listener may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 10;

    public function handle(InventoryCountingCompleted $event): void
    {
        $counting = InventoryCounting::find($event->countingId);

        if ($counting === null) {
            Log::error('ExitOnboardingOnFullCountFinalized: Counting not found', [
                'counting_id' => $event->countingId,
            ]);

            return;
        }

        if (! in_array($counting->scope_type, [CountingScopeType::FullInventory, CountingScopeType::Location], true)) {
            return;
        }

        if (! $counting->includes_zero_stock) {
            return;
        }

        /** @var list<string> $locationIds */
        $locationIds = InventoryCountingItem::query()
            ->where('counting_id', $counting->id)
            ->distinct()
            ->pluck('location_id')
            ->all();

        if ($locationIds === []) {
            return;
        }

        $exited = Location::query()
            ->where('company_id', $counting->company_id)
            ->whereIn('id', $locationIds)
            ->where('onboarding_mode', true)
            ->pluck('id');

        if ($exited->isEmpty()) {
            return;
        }

        Location::query()
            ->where('company_id', $counting->company_id)
            ->whereIn('id', $exited->all())
            ->update(['onboarding_mode' => false]);

        Log::info('ExitOnboardingOnFullCountFinalized: Onboarding mode exited', [
            'counting_id' => $event->countingId,
            'counting_number' => $counting->counting_number,
            'location_ids' => $exited->all(),
        ]);
    }
}
