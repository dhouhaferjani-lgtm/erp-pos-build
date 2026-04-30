<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by CustomerHistorySearchService when the search input does not meet
 * the minimum specificity requirement (spec §2.6).
 *
 * Accepted inputs: full email, E.164 phone, partner UUID (from QR scan).
 * Rejected inputs: partial name, short strings that could enumerate customers.
 *
 * Callers must write a `was_rejected = true` audit row before throwing.
 */
final class InsufficientSearchSpecificityException extends RuntimeException
{
    public function __construct(string $reason = '')
    {
        parent::__construct(
            $reason !== ''
                ? "Customer search rejected: {$reason}"
                : 'Customer search input does not meet the minimum specificity requirement. Provide a full email, E.164 phone number, or loyalty card UUID.'
        );
    }
}
