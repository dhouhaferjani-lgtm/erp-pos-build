<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * How a company values its inventory — the parameter the Wave-3 COGS seam is
 * built ON, not the seam itself (DPA Wave 3, D-14).
 *
 * ## Why the enum lives in Inventory, not Company
 *
 * `Company` only STORES the value; every consumer is inventory- or
 * accounting-side. Precedent: `companies.tax_status` casts to
 * `Taxation\Domain\Enums\CompanyTaxStatus`, not to a Company enum.
 *
 * ## Perpetual is built; periodic is RESERVED AND REFUSED
 *
 * `Periodic` is a real, named case and the database CHECK admits it, so
 * enabling it later needs no DDL on a live tenant database. But
 * `isSupported()` is false for it and `InventoryValuationModeResolver` refuses
 * it, because under periodic valuation there is no COGS at exit at all — the
 * charge is derived from a physical count at period end, and every Wave-3
 * exit-seam journal entry would be wrong. Admitting the value without building
 * the machinery is how a setting becomes a silent mis-statement.
 *
 * That three-layer shape — enum case exists, CHECK admits it, resolver refuses
 * it — is deliberate and is reused verbatim by the pre-delivery-invoicing
 * policy's `allow` value.
 *
 * ## `companies.inventory_costing_method` is NOT this
 *
 * That column predates this one and is dead: it names the COST-FLOW assumption
 * (weighted average vs FIFO), not the valuation SYSTEM. Nothing reads it.
 */
enum InventoryValuationMode: string
{
    /**
     * Continuous: every stock exit relieves inventory and books COGS at the
     * moment it happens. This is what Wave 3 implements.
     */
    case Perpetual = 'perpetual';

    /**
     * Period-end: exits move no value; the charge is computed from opening
     * stock + purchases − closing count. RESERVED — see the class docblock.
     */
    case Periodic = 'periodic';

    /**
     * Is this mode actually implemented end to end?
     *
     * The ONE authority for that question. A caller that branches on
     * `=== self::Perpetual` instead is a second authority, and the next mode
     * added will not reach it.
     */
    public function isSupported(): bool
    {
        return $this === self::Perpetual;
    }

    public function label(): string
    {
        return match ($this) {
            self::Perpetual => 'Perpetual (inventaire permanent)',
            self::Periodic => 'Periodic (inventaire intermittent)',
        };
    }
}
