<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Shared\Domain\Validation\CountryTaxNumberRules;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class CountryTaxNumberRulesTest extends TestCase
{
    public function test_matches_fr_siren_and_siret(): void
    {
        $this->assertTrue(CountryTaxNumberRules::matches('FR', '732829320'));
        $this->assertTrue(CountryTaxNumberRules::matches('FR', '73282932000074'));
        $this->assertFalse(CountryTaxNumberRules::matches('FR', '1234'));
    }

    public function test_matches_tn_compact_matricule_two_letters(): void
    {
        $this->assertTrue(CountryTaxNumberRules::matches('TN', '1234567AM000'));
        $this->assertTrue(CountryTaxNumberRules::matches('TN', '1234567/A/M/000'));
        $this->assertFalse(CountryTaxNumberRules::matches('TN', '1234567A000'));
        $this->assertFalse(CountryTaxNumberRules::matches('TN', '1234567am000'));
    }

    public function test_unknown_country_is_permissive(): void
    {
        $this->assertTrue(CountryTaxNumberRules::matches('XX', 'anything'));
    }

    public function test_matching_does_not_canonicalize_more_than_fiscal_validator(): void
    {
        $this->assertFalse(CountryTaxNumberRules::matches('DE', 'de123456789'));
        $this->assertFalse(CountryTaxNumberRules::matches('FR', ' 732829320 '));
    }

    public function test_patterns_stay_in_parity_with_fiscal_validator(): void
    {
        $ref = new ReflectionClass(FiscalPayloadConstraintValidator::class);
        $fiscal = $ref->getConstant('TAX_NUMBER_PATTERNS');

        $this->assertIsArray($fiscal);

        /** @var array<string, string> $fiscal */
        $this->assertEqualsCanonicalizing(
            array_keys($fiscal),
            array_keys(CountryTaxNumberRules::PATTERNS)
        );

        foreach ($fiscal as $country => $pattern) {
            $this->assertArrayHasKey(
                $country,
                CountryTaxNumberRules::PATTERNS,
                "Shared rules missing fiscal country {$country}"
            );
            $this->assertSame(
                $pattern,
                CountryTaxNumberRules::PATTERNS[$country],
                "Pattern drift for {$country} vs fiscal validator"
            );
        }
    }
}
