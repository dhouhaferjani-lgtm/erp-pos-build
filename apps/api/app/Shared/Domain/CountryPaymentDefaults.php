<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * The PINNED per-country payment defaults (spec §4.2).
 *
 * Single source for the two tolerance ceilings and the sanctioned cash-rounding
 * denomination. Read by:
 *
 *  - `CountryPaymentSettingsSeeder` — the provisioning path that creates/repairs
 *    the `country_payment_settings` rows;
 *  - `ConfigureCashRoundingCommand` — whose INSERT branch must reproduce the
 *    same ceilings, because a row created with the raw column defaults would
 *    ship `max_payment_tolerance_amount = 0.50` to the POS where 0.100 is
 *    sanctioned for TN — a silent 5x loosening from a command whose contract is
 *    that it never moves the tolerance numbers.
 *
 * Both MUST read the table from here. Duplicating the literals is how the
 * provisioning path and the ops path drift apart.
 *
 * ONE deliberate exclusion: migration A2
 * (`2026_07_28_100100_add_cash_rounding_to_country_payment_settings.php`) keeps
 * its own frozen TN literals. A migration is a point-in-time record of what it
 * actually wrote — pointing it at a mutable map would silently rewrite history
 * on re-run. Editing a value here therefore does NOT retroactively change what
 * that migration applied; the seeder re-pins live rows on its next run.
 *
 * The ceilings are PINNED (rewritten on every seeder run). The denomination is
 * a one-time BACKFILL value only — operator state is never overwritten.
 */
final class CountryPaymentDefaults
{
    /**
     * @var array<string, array{payment_tolerance_percentage: numeric-string, max_payment_tolerance_amount: numeric-string, cash_rounding_denomination: numeric-string|null}>
     */
    private const DEFAULTS = [
        'TN' => [
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.1000',
            'cash_rounding_denomination' => '0.0500',
        ],
        'FR' => [
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.5000',
            'cash_rounding_denomination' => null,
        ],
    ];

    /**
     * @return array<string, array{payment_tolerance_percentage: numeric-string, max_payment_tolerance_amount: numeric-string, cash_rounding_denomination: numeric-string|null}>
     */
    public static function all(): array
    {
        return self::DEFAULTS;
    }

    /**
     * The pinned defaults for a country, or null when the country has none —
     * in which case NO caller may invent tolerance numbers for it.
     *
     * @return array{payment_tolerance_percentage: numeric-string, max_payment_tolerance_amount: numeric-string, cash_rounding_denomination: numeric-string|null}|null
     */
    public static function forCountry(string $countryCode): ?array
    {
        return self::DEFAULTS[strtoupper(trim($countryCode))] ?? null;
    }
}
