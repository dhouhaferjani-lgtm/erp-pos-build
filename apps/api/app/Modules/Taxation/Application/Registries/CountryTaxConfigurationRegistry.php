<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Registries;

use Database\Seeders\FranceTaxConfigurationSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Illuminate\Database\Seeder;

/**
 * Maps an ISO 3166-1 alpha-2 country code to the seeder that loads that
 * country's tax_configurations. Add new countries here only.
 */
final class CountryTaxConfigurationRegistry
{
    private const MAP = [
        'TN' => TunisiaTaxConfigurationSeeder::class,
        'FR' => FranceTaxConfigurationSeeder::class,
    ];

    public function seederFor(string $countryCode): ?string
    {
        return self::MAP[strtoupper($countryCode)] ?? null;
    }

    public function supports(string $countryCode): bool
    {
        return $this->seederFor($countryCode) !== null;
    }
}
