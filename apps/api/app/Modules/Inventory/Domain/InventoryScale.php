<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * Canonical bcmath scale for Inventory-domain quantity values.
 *
 * Matches the `decimal(15,4)` storage scale on `stock_movements` /
 * `stock_levels` / counting-item quantity columns (precision contract,
 * `docs/architecture/precision-contract.md`). Referenced by name from
 * `MovementReplayService` and `ReplayAuditDto` — a shared named constant so
 * every timestamp-replay consumer normalizes to the same scale instead of
 * each service re-declaring its own local literal (PHPStan
 * `ForbidHardcodedBcmathScale` forbids a bare int literal scale argument in
 * the service layer; this constant is the resolved reference for it).
 */
final class InventoryScale
{
    public const int QUANTITY_SCALE = 4;
}
