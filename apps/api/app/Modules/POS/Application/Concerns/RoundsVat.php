<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Concerns;

use App\Shared\Domain\CurrencyScale;

/**
 * Shared VAT rounding used by BOTH the sale-creation and return paths.
 *
 * FISCAL SAFETY: a POS device computes fiscal hashes from canonicalized
 * amounts. The server-side return path MUST round VAT byte-for-byte the same
 * way the creation path does, or a refund VAT line could drift from the
 * original sale's VAT line and break the accounting identity / NF525 export.
 * Both ReceiptCreationService and ReceiptReturnService consume this single
 * helper so the two implementations can never diverge.
 *
 * The algorithm mirrors PostgreSQL's
 *   round((net_amount * tax_rate / 100)::numeric, scale)
 * using bcmath at extra precision for the intermediate product, then PHP
 * round() (half away from zero, same as PostgreSQL round()) for the final
 * quantization.
 *
 * Consuming classes MUST provide a `scale(): int` method returning the active
 * currency scale (declared abstract below so PHPStan can verify the contract).
 */
trait RoundsVat
{
    /**
     * The active currency decimal scale (resolved from CurrencyScaleResolver).
     */
    abstract private function scale(): int;

    /**
     * Round a VAT amount to match PostgreSQL:
     *   round((net_amount * tax_rate / 100)::numeric, scale).
     *
     * @return numeric-string
     */
    private function roundVat(string $netAmount, string $taxRate): string
    {
        $scale = $this->scale();

        // bcmath at extra precision for the intermediate product/quotient,
        // then PHP round() for half-away-from-zero (matching PostgreSQL).
        $extraPrecision = $scale + 4;
        /** @var numeric-string $netAmount */
        /** @var numeric-string $taxRate */
        $raw = bcdiv(bcmul($netAmount, $taxRate, $extraPrecision), '100', $extraPrecision);

        /** @var numeric-string */
        return CurrencyScale::bcformat((string) round((float) $raw, $scale), $scale);
    }
}
