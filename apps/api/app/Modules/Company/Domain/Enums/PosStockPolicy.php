<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Enums;

use App\Enums\Vertical;

/**
 * What the POS does when a cashier tries to sell beyond the terminal
 * location's available stock (spec §4.2). Per-location stock AWARENESS is
 * unconditional — this only selects the enforcement behavior.
 */
enum PosStockPolicy: string
{
    case Block = 'block';
    case Warn = 'warn';
    case Off = 'off';

    /**
     * Made-to-order verticals have no finished-goods stock rows — hard blocking
     * would freeze their POS — so they default to Off; every other vertical
     * defaults to Block.
     *
     * "Made-to-order" mirrors the verticals whose default module set in
     * `config/verticals.php` (the single source of truth, read at runtime via
     * VerticalConfigService) includes the `Menu` module — today exactly
     * Restaurant and CoffeeShop. This default is deliberately a PURE,
     * dependency-free domain function: it runs on the registration hot path
     * (before a tenant's database / central `vertical_configs` overrides are
     * reachable under database-per-tenant) and from a pure unit test, so it
     * must resolve without the container, config, or any database. It used to
     * derive from the `Vertical::defaultModules()` enum helper, which the
     * parapharmacy reorg deleted to keep module lists config-only; this mapping
     * replaces that dangling call. The exhaustive
     * `PosStockPolicyTest::test_full_vertical_to_policy_map` pins the set
     * against config so a vertical gaining/losing `Menu` surfaces as a
     * deliberate decision here rather than silent drift.
     */
    public static function defaultForVertical(Vertical $vertical): self
    {
        return match ($vertical) {
            Vertical::Restaurant, Vertical::CoffeeShop => self::Off,
            default => self::Block,
        };
    }
}
