<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Exceptions;

use DomainException;

/**
 * An ACCOUNTING opening batch debits a cash/bank account that payment
 * repositories are linked to, but the debit does not fully reach those
 * repositories (campaign wave-4 §W4-2, treasury gate r1 F-7 / PROBE B).
 *
 * ── The two shapes this catches, both measured ───────────────────────────────
 *
 * 1. THE LEGACY SHEET. A four-column CSV (`account_code,debit,credit,reference`)
 *    posts `Dr 53 1200.000` and names no repository at all. That is the P0 this
 *    lane exists to close, reproduced by an operator who simply has not heard of
 *    the new column: the ledger holds 1200.000 of cash and every till holds
 *    zero, permanently, because an opening batch locks itself at post.
 *
 * 2. THE MERGED ROW. A single `Dr 53 1200.000` row naming only `CASH-01` on a
 *    tenant whose drawer AND safe both hang off account `53`. It validates, it
 *    posts, and it yields drawer 1200.000 / safe 0.000 — and `treasury:reconcile`
 *    stays GREEN, because the one journal line equals the one movement. The
 *    handback originally claimed such a row "would freeze the drawer"; measured,
 *    it does not freeze anything, it SILENTLY OVER-SEEDS one till and leaves the
 *    other at zero. Prose in a runbook was the only thing standing against it.
 *
 * ── Why a refusal and not a warning ──────────────────────────────────────────
 * An opening batch is write-once: `postBatch` marks it Validated then Locked in
 * the same transaction, and a repository that already holds money refuses a
 * second float ({@see \App\Modules\Treasury\Domain\Exceptions\RepositoryAlreadySeededException}).
 * So a wrong opening cannot be corrected in-product — it has to be right the
 * first time or not happen at all.
 */
final class OpeningCashNotFullySeededException extends DomainException
{
    public const ERROR_CODE = 'OPENING_CASH_NOT_FULLY_SEEDED';

    /**
     * @param  list<string>  $gaps  human-readable, one per offending GL account
     */
    public function __construct(public readonly array $gaps)
    {
        parent::__construct(
            'This opening batch debits cash or bank accounts that payment repositories are linked to, '.
            'but the money does not fully reach them: '.implode(' ', $gaps).' '.
            'Name the repository on each cash line using the repository_code column, one line per '.
            'repository, so the ledger and the tills open at the same figure.'
        );
    }
}
