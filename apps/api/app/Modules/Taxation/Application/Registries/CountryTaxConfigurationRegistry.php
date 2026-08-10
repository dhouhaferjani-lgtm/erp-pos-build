<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Registries;

use Database\Seeders\FranceTaxConfigurationSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;

/**
 * Maps an ISO 3166-1 alpha-2 country code to the seeder that loads that
 * country's tax_configurations. Add new countries here only.
 */
final class CountryTaxConfigurationRegistry
{
    /**
     * A mis-shaped entry fails CLOSED at every reader (`?? null` / `?? false`),
     * which is the safe direction but is silent — the typed shape is what makes
     * the omission a static-analysis error instead of a runtime surprise
     * (F-12, 2026-08-10 tenancy gate).
     *
     * @var array<string, array{seeder: class-string, supports_stamp_duty: bool}>
     */
    private const MAP = [
        'TN' => [
            'seeder' => TunisiaTaxConfigurationSeeder::class,
            'supports_stamp_duty' => true,
        ],
        'FR' => [
            'seeder' => FranceTaxConfigurationSeeder::class,
            'supports_stamp_duty' => false,
        ],
    ];

    /**
     * @return class-string<TunisiaTaxConfigurationSeeder>|class-string<FranceTaxConfigurationSeeder>|null
     */
    public function seederFor(string $countryCode): ?string
    {
        return self::MAP[strtoupper($countryCode)]['seeder'] ?? null;
    }

    public function supports(string $countryCode): bool
    {
        return $this->seederFor($countryCode) !== null;
    }

    public function supportsStampDuty(string $countryCode): bool
    {
        return self::MAP[strtoupper($countryCode)]['supports_stamp_duty'] ?? false;
    }
}
