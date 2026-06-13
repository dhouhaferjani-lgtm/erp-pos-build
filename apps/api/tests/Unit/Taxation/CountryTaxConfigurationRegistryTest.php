<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Taxation\Application\Registries\CountryTaxConfigurationRegistry;
use Database\Seeders\FranceTaxConfigurationSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Tests\TestCase;

final class CountryTaxConfigurationRegistryTest extends TestCase
{
    public function test_maps_known_countries_to_seeder_classes(): void
    {
        $registry = new CountryTaxConfigurationRegistry;

        $this->assertSame(TunisiaTaxConfigurationSeeder::class, $registry->seederFor('TN'));
        $this->assertSame(FranceTaxConfigurationSeeder::class, $registry->seederFor('fr')); // case-insensitive
        $this->assertNull($registry->seederFor('US'));
        $this->assertTrue($registry->supports('TN'));
        $this->assertFalse($registry->supports('US'));
    }
}
