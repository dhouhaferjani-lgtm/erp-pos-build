<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use DomainException;

/**
 * An interactive adjustment was attempted on a payment repository that Treasury
 * has NO record of ever holding money: zero movements, zero prior adjustments
 * and a zero cached balance.
 *
 * ── What this refuses, and why it is not a nicety ────────────────────────────
 * Owner sheet B-2 (research:
 * docs/handoff/RESEARCH-opening-float-and-vat-doc-count-2026-08-23.md §1.2
 * Case 1/Case 3, Recommendation 2). At go-live an operator with a new till and
 * 500 TND of physical float reaches for the only cash-shaped control the UI
 * offers — Treasury → repository → "Adjust balance" — and enters it there.
 *
 * That dialog is a COUNT-VARIANCE instrument. An IN adjustment credits
 * `PaymentToleranceIncome` (`7580 Écart de règlement (produits)` on the seeded
 * TN chart), so seeding a float through it books the float as REVENUE and
 * seals it immutably into the fiscal hash chain. The float is an accounting
 * opening balance and belongs in the opening-balance batch
 * (Settings → Opening balances, Dr cash / Cr `119 Solde d'ouverture`).
 *
 * The remedy is documented in docs/handoff/RUNBOOK-opening-cash-float-2026-08-23.md;
 * this exception is what turns that runbook from discipline into enforcement.
 *
 * The predicate and the shift-variance carve-out are documented on
 * RepositoryAdjustmentService::post() / isNeverSeeded(). Deliberately named in
 * prose rather than an `@see` FQCN: pint's fully_qualified_strict_types fixer
 * turns an `@see` into a real `use` statement, which would make this Domain
 * class import an Application service and break the layering (CLAUDE.md rule 6).
 */
final class RepositoryNotSeededException extends DomainException
{
    /**
     * Machine-readable discriminator returned to the client alongside the
     * translated message, so the UI can route the operator to the
     * opening-balance wizard rather than re-render a generic 422.
     */
    public const ERROR_CODE = 'REPOSITORY_NOT_SEEDED';

    public function __construct(
        public readonly string $repositoryId,
        public readonly string $repositoryName,
        public readonly string $repositoryCode,
    ) {
        parent::__construct(
            "Cannot post an adjustment: payment repository '{$repositoryName}' ({$repositoryCode}) ".
            'has never held money (no movements, no prior adjustments, zero balance). '.
            'An opening cash float is an accounting opening balance and must be entered in the '.
            'opening-balance batch (Settings → Opening balances), not as a balance adjustment — '.
            'an adjustment would book it as revenue.'
        );
    }
}
