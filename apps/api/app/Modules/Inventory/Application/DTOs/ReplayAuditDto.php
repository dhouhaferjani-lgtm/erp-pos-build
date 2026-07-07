<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Snapshot of the timestamp-replay math behind an InventoryCountingItem's
 * `replay_audit` jsonb column (written by the MovementReplayService,
 * consumed by B3/D3 to explain a flagged or resolved item).
 *
 * All quantity fields are canonical numeric strings at
 * InventoryScale::QUANTITY_SCALE (scale 4) — never floats. Timestamps are
 * ISO 8601 UTC strings.
 */
#[TypeScript]
final class ReplayAuditDto extends Data
{
    public function __construct(
        // The item's final_qty_as_of — the instant the shelf was physically
        // counted (skew-corrected estimate); replay window start (exclusive).
        public string $windowFrom,
        // The apply instant (now at finalize); replay window end (inclusive).
        public string $windowTo,
        // Σ(quantity_after − quantity_before) of movements in (windowFrom, windowTo]
        // — net stock change since the count, at quantity scale 4.
        public string $replayedDelta,
        // System on-hand at apply time, read under lock.
        public string $onHandAtApply,
        // final_qty + replayedDelta (spec §4 expected_now) — NOT theoretical_qty-based.
        public string $expectedAtApply,
    ) {}
}
