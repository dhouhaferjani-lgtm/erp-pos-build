<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

/**
 * Represents a single user-input validation error returned by CashCountValidationService.
 * Internal-only — not exposed as a TypeScript type.
 */
final readonly class ValidationError
{
    public function __construct(
        /** @var string e.g. 'currency_mismatch', 'method_not_physical', 'amount_format' */
        public string $code,
        /** @var string e.g. 'cash_counts.0.currency_code' */
        public string $field,
        /** @var string Human-readable message in English; translated downstream. */
        public string $message,
    ) {}
}
