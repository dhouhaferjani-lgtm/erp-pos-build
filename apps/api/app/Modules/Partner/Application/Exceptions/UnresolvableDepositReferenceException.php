<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\Exceptions;

use App\Modules\Treasury\Domain\Enums\DepositReferenceRefusal;
use DomainException;

/**
 * Thrown when a back-office deposit names Treasury references the projection
 * pipeline could not have resolved.
 *
 * W-5c D1 — `RecordCustomerDepositService::record()` used to author and SEAL the
 * `DEPOSIT_RECEIPT` fiscal event first and only discover the dangling reference
 * during the (post-commit) synchronous projection run, leaving a permanent,
 * undeletable hash-chained orphan that over-states the customer's deposit
 * history, plus a 500. The references are now resolved BEFORE
 * `appendDepositReceipt(...)`, so this exception is always raised with nothing
 * authored.
 *
 * **Extends `DomainException` deliberately** (gate finding I-6): nothing is
 * sealed and the caller's input is at fault, so `bootstrap/app.php` renders it
 * as a 422 `BUSINESS_ERROR`. A 500 here would reproduce the exact symptom the
 * D1 ticket calls out — "indistinguishable from an outage in monitoring".
 *
 * `RecordDepositRequest` carries the cheap subset of these predicates as
 * field-level `exists` rules, so an HTTP caller usually gets a field-scoped 422
 * first; this is the complete check, and the belt-and-braces guard for any
 * internal caller that bypasses the FormRequest.
 *
 * docs/superpowers/tickets/2026-08-03-w5c-expense-income-deposit-findings.md
 */
final class UnresolvableDepositReferenceException extends DomainException
{
    public function __construct(
        public readonly DepositReferenceRefusal $refusal,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forRefusal(
        DepositReferenceRefusal $refusal,
        string $methodCode,
        ?string $repositoryId,
        string $currencyCode,
        string $companyId,
    ): self {
        return new self($refusal, sprintf(
            '%s [%s] Refusing to author a DEPOSIT_RECEIPT (method_code=%s, repository_id=%s, currency=%s, company=%s).',
            $refusal->message(),
            $refusal->value,
            $methodCode,
            $repositoryId ?? '(null)',
            $currencyCode,
            $companyId,
        ));
    }
}
