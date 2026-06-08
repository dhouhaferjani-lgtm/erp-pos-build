<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Shared\Exceptions\UnboundCompanyContextException;
use App\Shared\Infrastructure\CurrencyScaleResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Task 0.2: CurrencyScaleResolver context-guard tests.
 *
 * Verifies that getScale() fails loudly when no CompanyContext is bound
 * and no explicit currency code is provided, and that getScaleSafe()
 * returns a caller-supplied fallback instead of throwing.
 */
final class CurrencyScaleResolverContextTest extends TestCase
{
    #[Test]
    public function test_get_scale_throws_when_context_empty_and_no_currency_code(): void
    {
        $resolver = $this->makeEmptyContextResolver();

        $this->expectException(UnboundCompanyContextException::class);
        $this->expectExceptionMessageMatches('/CompanyContext/i');

        $resolver->getScale();
    }

    #[Test]
    public function test_get_scale_with_explicit_currency_code_works_without_company_context(): void
    {
        $resolver = $this->makeEmptyContextResolver();

        // Explicit code bypasses context lookup entirely
        $this->assertSame(3, $resolver->getScale('TND'));
        $this->assertSame(0, $resolver->getScale('JPY'));
        $this->assertSame(2, $resolver->getScale('EUR'));
    }

    #[Test]
    public function test_get_scale_safe_returns_fallback_when_context_empty_and_no_currency_code(): void
    {
        $resolver = $this->makeEmptyContextResolver();

        $this->assertSame(3, $resolver->getScaleSafe(null, 3));
        $this->assertSame(2, $resolver->getScaleSafe(null, 2));
        $this->assertSame(0, $resolver->getScaleSafe(null, 0));
    }

    #[Test]
    public function test_get_scale_safe_with_explicit_currency_code_ignores_fallback(): void
    {
        $resolver = $this->makeEmptyContextResolver();

        // Explicit code should resolve normally even in getScaleSafe
        $this->assertSame(3, $resolver->getScaleSafe('TND', 2));
        $this->assertSame(0, $resolver->getScaleSafe('JPY', 2));
    }

    #[Test]
    public function test_get_scale_works_when_company_override_is_provided(): void
    {
        $company = $this->createMock(Company::class);
        $company->method('__get')->willReturnMap([
            ['currency', 'TND'],
            ['country_code', 'TN'],
        ]);

        $country = $this->createMock(Country::class);
        $country->method('__get')->willReturnMap([
            ['currency_decimal_places', 3],
        ]);

        $companyContext = new CompanyContext;

        $resolver = new CurrencyScaleResolver(
            $companyContext,
            fn (string $code): ?Country => $code === 'TN' ? $country : null,
            $company,
        );

        $this->assertSame(3, $resolver->getScale());
    }

    #[Test]
    public function test_get_scale_safe_works_when_company_override_is_provided(): void
    {
        $company = $this->createMock(Company::class);
        $company->method('__get')->willReturnMap([
            ['currency', 'TND'],
            ['country_code', 'TN'],
        ]);

        $country = $this->createMock(Country::class);
        $country->method('__get')->willReturnMap([
            ['currency_decimal_places', 3],
        ]);

        $companyContext = new CompanyContext;

        $resolver = new CurrencyScaleResolver(
            $companyContext,
            fn (string $code): ?Country => $code === 'TN' ? $country : null,
            $company,
        );

        // Fallback should be ignored when company provides the scale
        $this->assertSame(3, $resolver->getScaleSafe(null, 2));
    }

    #[Test]
    public function test_get_scale_safe_does_not_swallow_database_failures(): void
    {
        $context = new CompanyContext;
        $context->setCompanyId('00000000-0000-0000-0000-000000000001');

        // Simulate a DB-side failure by having the countryFinder throw a RuntimeException
        // that is NOT an UnboundCompanyContextException.
        $resolver = new CurrencyScaleResolver(
            $context,
            function (string $code): never {
                throw new \RuntimeException('Simulated DB connection failure');
            },
            // companyOverride: minimal Company stub so getCompany() returns non-null
            companyOverride: $this->makeCompanyStub('TND', 'TN'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Simulated DB connection failure');

        $resolver->getScaleSafe(fallback: 3);
    }

    private function makeEmptyContextResolver(): CurrencyScaleResolver
    {
        $companyContext = new CompanyContext;

        return new CurrencyScaleResolver(
            $companyContext,
            fn (string $code): ?Country => null,
        );
    }

    private function makeCompanyStub(string $currency, string $countryCode): Company
    {
        $company = $this->createMock(Company::class);
        $company->method('__get')->willReturnMap([
            ['currency', $currency],
            ['country_code', $countryCode],
        ]);

        return $company;
    }
}
