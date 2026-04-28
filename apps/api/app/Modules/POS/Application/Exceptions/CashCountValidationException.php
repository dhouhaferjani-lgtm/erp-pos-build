<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Exceptions;

use App\Modules\POS\Application\DTOs\ValidationError;
use RuntimeException;

/**
 * Thrown when a cash-count submission cannot be persisted as-is.
 *
 * Two flavours, both surfaced to the caller via the same exception class so the controller
 * (Task 22) maps them uniformly to HTTP 422:
 *
 *  1. Domain validation errors collected by CashCountValidationService (currency mismatch,
 *     amount format, method-not-physical, duplicate payment_method_id, etc.) — built via
 *     {@see self::fromValidationErrors()}.
 *
 *  2. Orchestration gates enforced by ReportGenerationService (variance_reason_required when
 *     severity > Info, manager_pin_required when severity = Critical and the company config
 *     requires a PIN) — built via {@see self::missingRequirement()}.
 *
 * Internal programming contract violations (e.g. caller passes an inputs array but no
 * corresponding entry in expectedPerMethod) should also throw this with no errors() —
 * the empty errors array is the signal it's a programming bug, not a user-input error.
 */
final class CashCountValidationException extends RuntimeException
{
    /**
     * @param  array<int, ValidationError>  $errors
     */
    public function __construct(
        string $message = '',
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Build from a list of ValidationErrors collected by CashCountValidationService.
     *
     * @param  array<int, ValidationError>  $errors
     */
    public static function fromValidationErrors(array $errors): self
    {
        $codes = array_map(static fn (ValidationError $e): string => $e->code, $errors);
        $summary = $codes === [] ? 'cash-count validation failed' : 'cash-count validation failed: '.implode(', ', $codes);

        return new self($summary, $errors);
    }

    /**
     * Build from a single missing requirement (gate that the orchestrator enforces).
     */
    public static function missingRequirement(string $code, string $field, string $message): self
    {
        return new self($message, [new ValidationError(code: $code, field: $field, message: $message)]);
    }

    /**
     * @return array<int, ValidationError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Convenience accessor for the first error code, useful in tests/assertions.
     */
    public function firstCode(): ?string
    {
        return $this->errors[0]->code ?? null;
    }
}
