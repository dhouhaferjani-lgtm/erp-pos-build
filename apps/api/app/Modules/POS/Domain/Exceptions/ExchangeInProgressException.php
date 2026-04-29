<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

/**
 * Thrown when an exchange request with the same exchange_request_id is already
 * in the 'pending' state. The caller should retry after a short delay.
 *
 * Maps to HTTP 409 Conflict.
 */
final class ExchangeInProgressException extends \RuntimeException
{
    public function __construct(string $exchangeRequestId)
    {
        parent::__construct(
            "Exchange request '{$exchangeRequestId}' is already in progress. Retry after completion."
        );
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
