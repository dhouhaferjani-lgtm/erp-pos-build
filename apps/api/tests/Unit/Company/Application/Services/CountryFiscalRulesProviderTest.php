<?php

declare(strict_types=1);

namespace Tests\Unit\Company\Application\Services;

use App\Modules\Company\Application\Services\CountryFiscalRulesProvider;
use PHPUnit\Framework\TestCase;

class CountryFiscalRulesProviderTest extends TestCase
{
    private CountryFiscalRulesProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new CountryFiscalRulesProvider;
    }

    /** @test */
    public function it_provides_tunisia_rules(): void
    {
        $rules = $this->provider->getRulesForCountry('TN');

        $this->assertEquals('TN', $rules->countryCode);
        $this->assertEquals(1, $rules->defaultStartMonth);
        $this->assertTrue($rules->allowCustomStartMonth);
        $this->assertEquals(1, $rules->pastYearsToCreate);
        $this->assertEquals(1, $rules->futureYearsToCreate);
        $this->assertFalse($rules->autoClosePastYears);
        $this->assertEquals(1, $rules->periodAutoLockMonths);
    }

    /** @test */
    public function it_provides_france_rules(): void
    {
        $rules = $this->provider->getRulesForCountry('FR');

        $this->assertEquals('FR', $rules->countryCode);
        $this->assertEquals(1, $rules->defaultStartMonth);
        $this->assertTrue($rules->allowCustomStartMonth);
        $this->assertEquals(1, $rules->pastYearsToCreate);
        $this->assertEquals(1, $rules->futureYearsToCreate);
        $this->assertFalse($rules->autoClosePastYears);
        $this->assertEquals(1, $rules->periodAutoLockMonths);
    }

    /** @test */
    public function it_provides_generic_rules_for_country_without_dedicated_config(): void
    {
        $rules = $this->provider->getRulesForCountry('US');

        // Generic rules use the actual country code, not France's
        $this->assertEquals('US', $rules->countryCode);
        $this->assertEquals(1, $rules->defaultStartMonth);
        $this->assertTrue($rules->allowCustomStartMonth);
        $this->assertEquals(1, $rules->pastYearsToCreate);
        $this->assertEquals(1, $rules->futureYearsToCreate);
        $this->assertFalse($rules->autoClosePastYears);
        $this->assertEquals(1, $rules->periodAutoLockMonths);
        $this->assertEquals('monthly', $rules->periodStructure);
    }

    /** @test */
    public function it_calculates_correct_years_for_january_start(): void
    {
        $rules = $this->provider->getRulesForCountry('TN');
        $date = new \DateTimeImmutable('2025-06-15');

        $years = $rules->getYearsToCreate(1, $date);

        $this->assertEquals(2024, $years['past']);
        $this->assertEquals(2025, $years['current']);
        $this->assertEquals(2026, $years['future']);
    }

    /** @test */
    public function it_calculates_correct_years_for_july_start(): void
    {
        $rules = $this->provider->getRulesForCountry('FR');

        // March is before July, so we're still in fiscal year 2024 (July 2024 - June 2025)
        $date = new \DateTimeImmutable('2025-03-15');

        $years = $rules->getYearsToCreate(7, $date);

        $this->assertEquals(2023, $years['past']);
        $this->assertEquals(2024, $years['current']);
        $this->assertEquals(2025, $years['future']);
    }

    // Note: getAvailableCountries() and isCountrySupported() now query the DB
    // and require a Feature test with RefreshDatabase. See Feature tests.
}
