<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

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
     * Falls back to ISO 4217 static map, then to 2 (the most common scale).
     */
    public function getScale(?string $currencyCode = null): int;
}
