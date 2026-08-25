<?php

declare(strict_types=1);

namespace App\Modules\Expense\Domain\Exceptions;

use DomainException;

/**
 * An expense was declared PAID in cash without naming the treasury repository
 * the money left (campaign wave-4 §W4-10).
 *
 * ── What this refuses, and why it is not a nicety ────────────────────────────
 * `ExpenseService::create()` defaults `is_paid` to true. A create payload that
 * sets neither `payment_repository_id` nor `payment_date` therefore minted an
 * expense that was BORN PAID with no repository. Posting it settled against the
 * default cash GL account (Dr 6xx + Dr 4456 / Cr 53) and wrote NO
 * `repository_movements` row, so GL cash fell while the drawer never moved —
 * permanently, because `/pay` (the only endpoint that accepts a repository)
 * then refuses the expense as already paid.
 *
 * Document-per-action: money leaving a till is a treasury movement on THAT
 * till, and the expense is the document that justifies it. An expense that
 * cannot name the till cannot claim to have been paid from one.
 *
 * The carve-out is deliberate: a NON-CASH payment method (card, transfer —
 * `payment_methods.is_cash_tender = false`) settles through its own rail and is
 * not a till withdrawal, so it may be born paid without a repository.
 */
final class ExpensePaidWithoutRepositoryException extends DomainException
{
    /**
     * Machine-readable discriminator so the expense form can highlight the
     * repository field rather than re-render a generic 422.
     */
    public const ERROR_CODE = 'EXPENSE_PAID_WITHOUT_REPOSITORY';

    public function __construct()
    {
        parent::__construct(
            'An expense marked as paid in cash must name the payment repository the money left, '
            .'so the till and the ledger move together. Choose a payment repository, '
            .'choose a non-cash payment method, or leave the expense unpaid and settle it later.'
        );
    }
}
