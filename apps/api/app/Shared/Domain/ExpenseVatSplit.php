<?php

declare(strict_types=1);

namespace App\Shared\Domain;

final class ExpenseVatSplit
{
    /**
     * Calculate deductible input VAT at the currency posting boundary.
     *
     * @param  numeric-string  $vatAmount
     * @param  numeric-string  $percent
     * @return numeric-string
     */
    public static function deductible(string $vatAmount, string $percent, int $scale): string
    {
        return CurrencyScale::bcround(
            bcdiv(bcmul($vatAmount, $percent, $scale + 2), '100', $scale + 2),
            $scale,
        );
    }
}
