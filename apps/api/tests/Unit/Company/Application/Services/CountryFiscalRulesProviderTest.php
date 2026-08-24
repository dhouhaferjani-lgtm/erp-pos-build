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

    // ── Session B lane Q-10 (a) ─────────────────────────────────────────
    //
    // hasDedicatedRules() is the predicate FiscalPeriodAutoLockService branches on
    // before it auto-locks a company's periods: TRUE means a real country entry,
    // FALSE means getRulesForCountry() would answer with the GENERIC guess, which is
    // fine for a settings form and not fine for a compliance statement.
    //
    // (Written after the implementation; the behaviour itself was driven red-first
    // through FiscalPeriodAutoLockServiceTest, where the DE/blank-country companies
    // were being locked on Tunisia's window before the fix.)

    public function test_has_dedicated_rules_is_true_only_for_countries_with_a_dedicated_method(): void
    {
        $this->assertTrue($this->provider->hasDedicatedRules('TN'));
        $this->assertTrue($this->provider->hasDedicatedRules('tn'));
        $this->assertTrue($this->provider->hasDedicatedRules('FR'));

        $this->assertFalse($this->provider->hasDedicatedRules('DE'));
        $this->assertFalse($this->provider->hasDedicatedRules('GB'));
    }

    public function test_has_dedicated_rules_is_false_for_a_blank_country_code(): void
    {
        $this->assertFalse($this->provider->hasDedicatedRules(''));
        $this->assertFalse($this->provider->hasDedicatedRules('  '));
    }
}
