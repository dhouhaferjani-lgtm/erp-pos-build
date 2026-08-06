<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use App\Modules\Treasury\Domain\PaymentRepository;
use DomainException;

/**
 * Thrown when an interactive OUTFLOW movement (MovementIntent::$allowNegative
 * = false, the default) would take a repository's balance below zero AND the
 * repository itself does not permit it ({@see PaymentRepository::$allow_negative}
 * = false).
 *
 * W-5b Option B (owner ruling, 2026-08-05 —
 * docs/sessions/RULINGS-RESEARCH-2026-08-05-negative-repository-balance.md):
 * a physical/pseudo-physical repository (cash_register/safe/virtual) cannot
 * hold negative cash; a bank_account defaults `allow_negative = true` (an
 * authorised overdraft) and never reaches this exception. Queued/replay/
 * bridge writers set `MovementIntent::$allowNegative` instead of hitting
 * this guard — see that flag's docblock, which mirrors `allowWhileFrozen`.
 *
 * Extends \DomainException so bootstrap/app.php maps it to HTTP 422.
 */
final class InsufficientRepositoryBalanceException extends DomainException
{
    /**
     * @param  numeric-string  $available  Repository balance BEFORE this movement.
     * @param  numeric-string  $requested  The outflow amount that was refused.
     * @param  numeric-string  $resultingBalance  What the balance would have become (negative).
     */
    public function __construct(
        public readonly string $repositoryId,
        public readonly string $available,
        public readonly string $requested,
        public readonly string $resultingBalance,
        public readonly string $currency,
    ) {
        parent::__construct(
            "Repository {$repositoryId} has insufficient balance ({$available} {$currency}) to record an outflow ".
            "of {$requested} {$currency}; the resulting balance ({$resultingBalance} {$currency}) would be ".
            'negative and this repository does not allow a negative balance.',
        );
    }
}
