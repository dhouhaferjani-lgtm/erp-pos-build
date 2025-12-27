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
    public function it_falls_back_to_france_for_unsupported_country(): void
    {
        $rules = $this->provider->getRulesForCountry('US');

        // Should get France rules as fallback
        $this->assertEquals('FR', $rules->countryCode);
        $this->assertEquals(1, $rules->defaultStartMonth);
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

    /** @test */
    public function it_supports_available_countries(): void
    {
        $countries = $this->provider->getAvailableCountries();

        $this->assertContains('TN', $countries);
        $this->assertContains('FR', $countries);
    }

    /** @test */
    public function it_checks_if_country_is_supported(): void
    {
        $this->assertTrue($this->provider->isCountrySupported('TN'));
        $this->assertTrue($this->provider->isCountrySupported('FR'));
        $this->assertFalse($this->provider->isCountrySupported('US'));
    }
}
