<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTO;

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
        // Start of the replay window (typically the counting's activation
        // or the count's device timestamp).
        public string $windowFrom,
        // End of the replay window (final_qty_as_of / apply time).
        public string $windowTo,
        // Sum of signed stock_movement deltas that occurred inside the
        // replay window, at quantity scale 4.
        public string $replayedDelta,
        // Actual on-hand quantity observed at apply time.
        public string $onHandAtApply,
        // theoretical_qty replayed forward through the window
        // (theoretical_qty + replayedDelta), for comparison against
        // onHandAtApply.
        public string $expectedAtApply,
    ) {}
}
