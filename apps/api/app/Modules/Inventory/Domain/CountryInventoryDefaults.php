<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Inventory\Application\Services\CountCorrectionGlPostingResolver;
use App\Modules\Inventory\Domain\Enums\InventoryValuationMode;
use App\Shared\Domain\CountryPaymentDefaults;

/**
 * The PINNED per-country inventory-valuation defaults (DPA Wave 3, T8).
 *
 * Modelled on {@see CountryPaymentDefaults}, and for the same
 * reason: the provisioning path and any future ops path must read ONE table of
 * literals or they drift apart.
 *
 * ## Why it lives in `Inventory\Domain`, not `Shared\Domain`
 *
 * The plan (T8) put it beside `CountryPaymentDefaults` in `Shared\Domain`. That
 * placement is unavailable here and the difference is not cosmetic: this map's
 * values are `InventoryValuationMode` cases, and `Shared\Domain` is the PURE
 * kernel — deptrac gives it no allowed dependencies at all, so importing a
 * module enum into it opens a brand-new violation category
 * (`SharedDomain on ModuleDomain`, 0 -> 4, measured). `CountryPaymentDefaults`
 * has no such problem because its values are bare numeric strings.
 *
 * The alternative — keep the file in `Shared\Domain` and store raw strings —
 * would trade a typed authority for magic strings, which house rule 9 exists to
 * prevent. Both consumers (the seeder and `InventoryValuationModeResolver`) are
 * inventory-side, so `Inventory\Domain` is where it belongs.
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
     * Whether a count correction posts a shrinkage/gain journal entry, per
     * country (lane P-1, owner ruling 2026-08-25).
     *
     * It is a SECOND map rather than a value derived from `DEFAULTS`, even
     * though today both countries are perpetual and both post. Perpetual
     * valuation says COGS is booked at the exit seam; it does not by itself say
     * that a stock-take difference is booked as 6586/7586 rather than absorbed
     * in a period-end adjustment, and a jurisdiction that wants the latter must
     * be expressible as an edit HERE and nowhere else. Collapsing the two would
     * make that edit a code change to the valuation map, which is the wrong
     * lever.
     *
     * Both entries are `true`: the owner ruled the flag ships ON, with the
     * expert-comptable reviewing the Option A account choice (6586 shortage /
     * 7586 overage) later at onboarding rather than as a gate before the flip.
     *
     * @var array<string, bool>
     */
    private const COUNT_CORRECTION_GL_POSTING = [
        'TN' => true,
        'FR' => true,
    ];

    /**
     * @return array<string, bool>
     */
    public static function allCountCorrectionGlPosting(): array
    {
        return self::COUNT_CORRECTION_GL_POSTING;
    }

    /**
     * The pinned posting default for a country, or null when it has none —
     * callers fall through to the SYSTEM default explicitly, through
     * {@see CountCorrectionGlPostingResolver},
     * so the choice stays visible in the resolved `source`.
     */
    public static function countCorrectionGlPostingForCountry(string $countryCode): ?bool
    {
        return self::COUNT_CORRECTION_GL_POSTING[strtoupper(trim($countryCode))] ?? null;
    }

    /**
     * The pinned default for a country, or null when the country has none.
     */
    public static function forCountry(string $countryCode): ?InventoryValuationMode
    {
        return self::DEFAULTS[strtoupper(trim($countryCode))] ?? null;
    }
}
