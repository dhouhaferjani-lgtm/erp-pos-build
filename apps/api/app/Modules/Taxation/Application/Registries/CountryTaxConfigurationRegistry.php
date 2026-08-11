<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Registries;

use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use Database\Seeders\FranceTaxConfigurationSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;

/**
 * Maps an ISO 3166-1 alpha-2 country code to the seeder that loads that
 * country's tax_configurations. Add new countries here only.
 */
final class CountryTaxConfigurationRegistry
{
    public function __construct(
        private readonly CountryAccountingCapabilities $capabilities,
    ) {}

    /**
     * A mis-shaped entry fails CLOSED at every reader (`?? null` / `?? false`),
     * which is the safe direction but is silent — the typed shape is what makes
     * the omission a static-analysis error instead of a runtime surprise
     * (F-12, 2026-08-10 tenancy gate).
     *
     * @var array<string, array{seeder: class-string}>
     */
    private const MAP = [
        'TN' => [
            'seeder' => TunisiaTaxConfigurationSeeder::class,
        ],
        'FR' => [
            'seeder' => FranceTaxConfigurationSeeder::class,
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
        return $this->capabilities->supportsStampDuty($countryCode);
    }
}
