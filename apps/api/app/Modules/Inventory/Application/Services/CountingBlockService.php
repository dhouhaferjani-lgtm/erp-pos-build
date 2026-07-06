<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\LocationZone;

/**
 * Resolves the ACTIVE sales-blocking counting (if any) and the soft zone
 * advisories for a POS location (live inventory counting task C2, spec §4.2 /
 * §4.5). Pure domain function — no dependencies — so it can be instantiated
 * directly (`new CountingBlockService`) from a JsonResource without container
 * access, and constructor-injected into the POS projection.
 *
 * **Block lifetime (spec — normative).** A `block_sales = true` counting blocks
 * from `count_1_in_progress` THROUGH `pending_review` (the explicit list in
 * {@see self::ACTIVE_BLOCK_STATUSES}). This is deliberately NOT the model's
 * `scopeActive()`, which excludes `pending_review` — the block must persist
 * through the review gate until the count is finalized or cancelled.
 *
 * **Scope coverage.** A blocking count only exists for location-covering
 * scopes (`location`, `full_inventory`, `product_location`). Zone-scoped counts
 * can never carry `block_sales` (enforced at creation by task C1) and produce
 * ADVISORIES only — sales don't declare shelves, so a zone count never hard-
 * blocks the till.
 */
final class CountingBlockService
{
    /**
     * Statuses during which a `block_sales` counting hard-blocks sales:
     * count_1_in_progress THROUGH pending_review, inclusive. Ends at
     * finalized / cancelled. Explicit — do NOT reuse `scopeActive()`.
     *
     * @var array<int, CountingStatus>
     */
    private const array ACTIVE_BLOCK_STATUSES = [
        CountingStatus::Count1InProgress,
        CountingStatus::Count1Completed,
        CountingStatus::Count2InProgress,
        CountingStatus::Count2Completed,
        CountingStatus::Count3InProgress,
        CountingStatus::Count3Completed,
        CountingStatus::PendingReview,
    ];

    /**
     * The active sales-blocking counting covering this location, or null.
     *
     * Contract consumed by TerminalResource (payload) and
     * PosCoreReceiptProjection (late-sale flagging) — keep the signature exact.
     *
     * @param  string|null  $companyId  Pass the caller's already-known company id
     *   (e.g. `Location::company_id`, already loaded) to skip the internal
     *   Location lookup. Null (default) preserves the original behavior of
     *   resolving it from `$locationId`.
     */
    public function activeBlockFor(string $locationId, ?string $companyId = null): ?InventoryCounting
    {
        $companyId ??= $this->resolveCompanyId($locationId);
        if ($companyId === null) {
            return null;
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, InventoryCounting> $candidates */
        $candidates = InventoryCounting::query()
            ->where('company_id', $companyId)
            ->where('block_sales', true)
            ->whereIn('status', self::ACTIVE_BLOCK_STATUSES)
            ->whereIn('scope_type', [
                CountingScopeType::Location->value,
                CountingScopeType::FullInventory->value,
                CountingScopeType::ProductLocation->value,
            ])
            ->orderBy('activated_at')
            ->get();

        foreach ($candidates as $counting) {
            if ($this->scopeCoversLocation($counting, $locationId)) {
                return $counting;
            }
        }

        return null;
    }

    /**
     * Soft zone-count advisories for this location: one entry per zone (of this
     * location) currently under an active zone-scoped count. Never blocks.
     *
     * @param  string|null  $companyId  Same skip-the-lookup contract as
     *   {@see self::activeBlockFor()}.
     *
     * @return array<int, array{zone_name: string, counting_number: string|null}>
     */
    public function zoneAdvisoriesFor(string $locationId, ?string $companyId = null): array
    {
        $companyId ??= $this->resolveCompanyId($locationId);
        if ($companyId === null) {
            return [];
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, InventoryCounting> $countings */
        $countings = InventoryCounting::query()
            ->where('company_id', $companyId)
            ->where('scope_type', CountingScopeType::Zone->value)
            ->whereIn('status', self::ACTIVE_BLOCK_STATUSES)
            ->get();

        if ($countings->isEmpty()) {
            return [];
        }

        // Only zones belonging to THIS location can advise this terminal.
        $zoneNames = LocationZone::query()
            ->where('location_id', $locationId)
            ->pluck('name', 'id');

        if ($zoneNames->isEmpty()) {
            return [];
        }

        $advisories = [];
        foreach ($countings as $counting) {
            $zoneIds = $counting->scope_filters['zone_ids'] ?? [];
            if (! is_array($zoneIds)) {
                continue;
            }

            foreach ($zoneIds as $zoneId) {
                if (is_string($zoneId) && $zoneNames->has($zoneId)) {
                    $advisories[] = [
                        'zone_name' => (string) $zoneNames->get($zoneId),
                        'counting_number' => $counting->counting_number,
                    ];
                }
            }
        }

        return $advisories;
    }

    private function scopeCoversLocation(InventoryCounting $counting, string $locationId): bool
    {
        $filters = $counting->scope_filters;

        return match ($counting->scope_type) {
            CountingScopeType::FullInventory => true,
            CountingScopeType::Location => in_array(
                $locationId,
                array_filter((array) ($filters['location_ids'] ?? []), 'is_string'),
                true,
            ),
            CountingScopeType::ProductLocation => ($filters['location_id'] ?? null) === $locationId,
            default => false,
        };
    }

    private function resolveCompanyId(string $locationId): ?string
    {
        $companyId = Location::query()->whereKey($locationId)->value('company_id');

        return is_string($companyId) ? $companyId : null;
    }
}
