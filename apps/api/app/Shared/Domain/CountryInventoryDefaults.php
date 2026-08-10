<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Modules\Inventory\Domain\Enums\InventoryValuationMode;

/**
 * The PINNED per-country inventory-valuation defaults (DPA Wave 3, T8).
 *
 * Modelled on {@see CountryPaymentDefaults}, and for the same reason: the
 * provisioning path and any future ops path must read ONE table of literals or
 * they drift apart.
 *
 * An unknown country returns **null**, and NO caller may invent a mode for it.
 * A guessed valuation mode is not a cosmetic default — it decides whether the
 * whole exit seam books COGS for that tenant. Callers fall back to the SYSTEM
 * default explicitly, through `InventoryValuationModeResolver`, so the choice
 * is visible in the resolved `source`.
 */
final class CountryInventoryDefaults
{
    /**
     * Both supported countries are `perpetual` — France (PCG, inventaire
     * permanent for merchandising) and Tunisia (NC 01/NCT 03). They are listed
     * separately rather than collapsed into one default so that adding a
     * periodic-by-law jurisdiction is an edit here and nowhere else.
     *
     * @var array<string, InventoryValuationMode>
     */
    private const DEFAULTS = [
        'TN' => InventoryValuationMode::Perpetual,
        'FR' => InventoryValuationMode::Perpetual,
    ];

    /**
     * @return array<string, InventoryValuationMode>
     */
    public static function all(): array
    {
        return self::DEFAULTS;
    }

    /**
     * The pinned default for a country, or null when the country has none.
     */
    public static function forCountry(string $countryCode): ?InventoryValuationMode
    {
        return self::DEFAULTS[strtoupper(trim($countryCode))] ?? null;
    }
}
