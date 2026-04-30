<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a goodwill voucher above the named-customer threshold is issued
 * without identifying a recipient partner.
 *
 * Per tenant policy, goodwill amounts above a configured threshold must be
 * tied to a named customer (issued_to_partner_id) to prevent abuse.
 */
final class GoodwillRequiresNamedCustomerException extends RuntimeException
{
    /**
     * @param  numeric-string  $amount
     * @param  numeric-string  $threshold
     */
    public function __construct(string $amount, string $threshold)
    {
        parent::__construct(sprintf(
            'Goodwill voucher of %s exceeds the named-customer threshold of %s. '
            .'Please identify the recipient partner (issued_to_partner_id) for amounts above this threshold.',
            $amount,
            $threshold
        ));
    }
}
