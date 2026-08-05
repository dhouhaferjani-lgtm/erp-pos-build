<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\Exceptions;

/**
 * Thrown when a back-office deposit names a payment method or payment repository
 * that the Treasury bridge could not project the receipt against.
 *
 * W-5c D1 — `RecordCustomerDepositService::record()` used to author and SEAL the
 * `DEPOSIT_RECEIPT` fiscal event first and only discover the dangling reference
 * during the (post-commit) synchronous projection run, leaving a permanent,
 * undeletable hash-chained orphan that over-states the customer's deposit
 * history, plus a 500. The references are now resolved BEFORE
 * `appendDepositReceipt(...)`, so this exception is always raised with nothing
 * authored.
 *
 * `RecordDepositRequest` carries the same predicates as tenant/company-scoped
 * `exists` rules, so an HTTP caller gets a 422 and never reaches here; this is
 * the belt-and-braces guard for any internal caller that bypasses the FormRequest.
 *
 * docs/superpowers/tickets/2026-08-03-w5c-expense-income-deposit-findings.md
 */
final class UnresolvableDepositReferenceException extends \RuntimeException
{
    public static function paymentMethod(string $methodCode, string $companyId): self
    {
        return new self(sprintf(
            'Refusing to author a DEPOSIT_RECEIPT: no active payment method with code "%s" exists for company %s.',
            $methodCode,
            $companyId,
        ));
    }

    public static function repository(?string $repositoryId, string $companyId): self
    {
        return new self(sprintf(
            'Refusing to author a DEPOSIT_RECEIPT: no active payment repository "%s" exists for company %s.',
            $repositoryId ?? '(null)',
            $companyId,
        ));
    }
}
