<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

/**
 * Input for StockAdjustmentDocumentService::updateDraft() — the re-anchor target
 * of the persisted-draft staleness branch (plan §2's PATCH contract).
 *
 * `$lines`, when non-null, FULLY REPLACES the line set. That is safe because a
 * draft line always has `movement_id IS NULL` by construction, so
 * delete-and-recreate inside the transaction loses no back-link and cannot
 * orphan a movement. A null `$lines` edits the note only.
 *
 * `location_id` is deliberately NOT editable: changing it would invalidate every
 * line's `observed_before` and the whole lock set.
 */
final class UpdateStockAdjustmentData
{
    /**
     * @param  list<StockAdjustmentLineInput>|null  $lines
     */
    public function __construct(
        public readonly ?string $note = null,
        public readonly ?array $lines = null,
        public readonly bool $noteProvided = false,
    ) {}
}
