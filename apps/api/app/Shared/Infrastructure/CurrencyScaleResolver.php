<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Exceptions\UnboundCompanyContextException;
use Closure;

/**
 * Resolves currency decimal scale from DB (Country) with ISO 4217 fallback.
 *
 * Resolution order:
 * 1. If explicit $currencyCode passed → use static ISO 4217 map
 * 2. Company's country → country.currency_decimal_places column
 * 3. Company's currency code → static ISO 4217 map
 * 4. No company bound → UnboundCompanyContextException (use getScaleSafe() for a silent fallback)
 */
final class CurrencyScaleResolver implements CurrencyScaleResolverInterface
{
    /**
     * @param  Closure(string): ?Country  $countryFinder  Injected for testability
     * @param  Company|null  $companyOverride  For unit testing without DB (CompanyContext is final)
     */
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly Closure $countryFinder,
        private readonly ?Company $companyOverride = null,
    ) {}

    public function getScale(?string $currencyCode = null): int
    {
        // If an explicit currency code is provided, use the static map directly
        if ($currencyCode !== null) {
            return CurrencyScale::for($currencyCode);
        }

        $company = $this->companyOverride ?? $this->companyContext->getCompany();

        if ($company === null) {
            throw new UnboundCompanyContextException(
                'CurrencyScaleResolver::getScale() called with no currency code and no CompanyContext bound. '
                .'Ensure CompanyContextMiddleware is applied or pass an explicit $currencyCode. '
                .'For callers that intentionally run outside request context (queued jobs, console commands), '
                .'use getScaleSafe($currencyCode, $fallback) instead. See audit finding F-RES-1.',
            );
        }

        // Try country record's currency_decimal_places first
        /** @var string $countryCode */
        $countryCode = $company->country_code;
        $country = ($this->countryFinder)($countryCode);

        if ($country !== null) {
            /** @var int $decimalPlaces */
            $decimalPlaces = $country->currency_decimal_places;

            return $decimalPlaces;
        }

        // Fall back to static ISO 4217 map using company's currency
        /** @var string $currency */
        $currency = $company->currency;

        return CurrencyScale::for($currency);
    }

    public function getScaleSafe(?string $currencyCode = null, int $fallback = 3): int
    {
        try {
            return $this->getScale($currencyCode);
        } catch (UnboundCompanyContextException $e) {
            return $fallback;
        }
    }
}
