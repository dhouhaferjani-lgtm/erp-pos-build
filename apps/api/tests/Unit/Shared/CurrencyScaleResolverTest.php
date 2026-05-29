<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Shared\Infrastructure\CurrencyScaleResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CurrencyScaleResolverTest extends TestCase
{
    #[Test]
    public function it_returns_scale_from_country_decimal_places(): void
    {
        $resolver = $this->makeResolver('TND', 'TN', $this->makeCountry(3));

        $this->assertSame(3, $resolver->getScale());
    }

    #[Test]
    public function it_returns_2_for_eur_company(): void
    {
        $resolver = $this->makeResolver('EUR', 'FR', $this->makeCountry(2));

        $this->assertSame(2, $resolver->getScale());
    }

    #[Test]
    public function it_falls_back_to_static_map_when_no_country_record(): void
    {
        $resolver = $this->makeResolver('TND', 'TN', null);

        $this->assertSame(3, $resolver->getScale());
    }

    #[Test]
    public function it_uses_explicit_currency_code_over_company_default(): void
    {
        $resolver = $this->makeResolver('EUR', 'FR', $this->makeCountry(2));

        // Pass explicit TND — should return 3 even though company is EUR
        $this->assertSame(3, $resolver->getScale('TND'));
    }

    #[Test]
    public function it_throws_when_no_company_context_and_no_currency(): void
    {
        $companyContext = new CompanyContext;

        $resolver = new CurrencyScaleResolver(
            $companyContext,
            fn (string $code): ?Country => null,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/CompanyContext/');

        $resolver->getScale();
    }

    #[Test]
    public function it_returns_0_for_jpy_via_explicit_code(): void
    {
        $resolver = $this->makeResolver('EUR', 'FR', $this->makeCountry(2));

        $this->assertSame(0, $resolver->getScale('JPY'));
    }

    private function makeCountry(int $decimalPlaces): Country
    {
        $country = $this->createMock(Country::class);
        $country->method('__get')->willReturnMap([
            ['currency_decimal_places', $decimalPlaces],
        ]);

        return $country;
    }

    private function makeResolver(string $currency, string $countryCode, ?Country $country): CurrencyScaleResolver
    {
        $companyContext = new CompanyContext;

        $company = $this->createMock(Company::class);
        $company->method('__get')->willReturnMap([
            ['currency', $currency],
            ['country_code', $countryCode],
        ]);

        return new CurrencyScaleResolver(
            $companyContext,
            fn (string $code): ?Country => $code === $countryCode ? $country : null,
            $company,
        );
    }
}
