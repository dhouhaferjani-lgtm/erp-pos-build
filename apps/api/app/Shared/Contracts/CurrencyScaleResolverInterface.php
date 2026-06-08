<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\Exceptions\UnboundCompanyContextException;

/**
 * Resolves the decimal scale for monetary values based on currency.
 *
 * Module boundaries are sacred: Cross-module communication ONLY via interfaces.
 */
interface CurrencyScaleResolverInterface
{
    /**
     * Get the decimal scale for a currency code.
     *
     * When $currencyCode is null, resolves from the current company's currency.
     * Falls back to ISO 4217 static map.
     *
     * @throws UnboundCompanyContextException When $currencyCode is null and no CompanyContext is bound.
     *                                        Use getScaleSafe() for callers that want an explicit fallback.
     */
    public function getScale(?string $currencyCode = null): int;

    /**
     * Get the decimal scale for a currency code, returning $fallback instead of throwing.
     *
     * Safe variant for callers that may legitimately run without a bound CompanyContext
     * (e.g. queued notifications, console commands that derive scale from a stored model
     * field rather than from the current request context).
     *
     * When an explicit $currencyCode is provided the ISO 4217 map is used normally;
     * $fallback is only returned when both $currencyCode is null AND CompanyContext
     * has no bound company.
     *
     * @param  int  $fallback  Scale to return when context is unavailable (default: 3 — safe maximum).
     */
    public function getScaleSafe(?string $currencyCode = null, int $fallback = 3): int;
}
