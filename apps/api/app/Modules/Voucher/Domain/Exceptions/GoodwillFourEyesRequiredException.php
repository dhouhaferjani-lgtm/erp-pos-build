<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a goodwill voucher above the four-eyes threshold is issued
 * without a second-level authorizer.
 *
 * Per tenant policy, high-value goodwill amounts above a configured threshold
 * require a second authorizing user (authorized_by_user_id) to prevent fraud.
 */
final class GoodwillFourEyesRequiredException extends RuntimeException
{
    /**
     * @param  numeric-string  $amount
     * @param  numeric-string  $threshold
     */
    public function __construct(string $amount, string $threshold)
    {
        parent::__construct(sprintf(
            'Goodwill voucher of %s exceeds the four-eyes approval threshold of %s. '
            .'Please supply an authorized_by_user_id from a supervisor or manager.',
            $amount,
            $threshold
        ));
    }
}
