<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use DomainException;

/**
 * Thrown when a MovementIntent's currency does not match the target
 * repository's (and thus the company's) currency.
 *
 * Extends \DomainException so bootstrap/app.php maps it to HTTP 422
 * BUSINESS_ERROR rather than a 500.
 */
final class CurrencyMismatchException extends DomainException
{
    public function __construct(
        public readonly string $repositoryId,
        public readonly string $repositoryCurrency,
        public readonly string $intentCurrency,
    ) {
        parent::__construct(
            "Movement currency {$intentCurrency} does not match repository {$repositoryId} currency {$repositoryCurrency}.",
        );
    }
}
