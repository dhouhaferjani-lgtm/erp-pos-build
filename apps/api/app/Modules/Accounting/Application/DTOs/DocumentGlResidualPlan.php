<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\GlResidualRefusal;

/**
 * The balance plan for a document's GL posting, computed from the document alone.
 *
 * Produced once and used twice, which is the point: the SAME numbers decide
 * whether the document may be sealed (pre-flight, inside the posting transaction)
 * and how the residual leg is written (the posting itself, in the post-commit
 * listener). Deriving them separately is how the two could drift.
 *
 * `$residual = total − Σline_total − Σ(positive per-rate recomputed VAT)`.
 *
 * @see AccountingService::residualPlan()
 */
final readonly class DocumentGlResidualPlan
{
    /**
     * @param  numeric-string  $revenue  Σ line_total
     * @param  numeric-string  $vat  Σ positive per-rate recomputed VAT
     * @param  numeric-string  $residual  arFacingTotal − revenue − vat (signed) — for a
     *                                    credit note, arFacingTotal already excludes
     *                                    $stampDutyAmount (Q1); for every other
     *                                    document type it is the plain `total`.
     * @param  array<numeric-string, numeric-string>  $taxByRate  rate => recomputed tax
     * @param  Account|null  $absorbingAccount  where a positive residual is booked
     * @param  GlResidualRefusal|null  $refusal  null when the document is postable
     * @param  bool  $balanceAssertable  false for a document with NO lines, whose GL
     *                                   entry has no revenue side to balance against.
     *                                   LEGACY-ONLY since owner ruling O-26
     *                                   (2026-08-21): posting a lineless document is
     *                                   now refused upstream
     *                                   ({@see GlResidualRefusal::LinelessDocument}),
     *                                   so the only remaining consumer of a `false`
     *                                   here is `AccountingService::reverseDocumentGl()`
     *                                   cancelling a document posted BEFORE that
     *                                   refusal. Delete this parameter with that
     *                                   carve-out once the last pre-O-26 tenant
     *                                   document is disposed. See `residualPlan()`.
     * @param  numeric-string  $stampDutyAmount  Q1 (2026-08-07 expert-comptable ruling):
     *                                           a CREDIT NOTE's own stamp duty
     *                                           (`documents.stamp_duty_amount`), peeled
     *                                           out of the AR-facing total and booked as
     *                                           a separate self-balancing pair
     *                                           ({@see $stampExpenseAccount} /
     *                                           {@see $stampPayableAccount}) instead of
     *                                           reducing what the customer owes. Always
     *                                           `'0'` for every other document type —
     *                                           Q1 does not change invoice treatment.
     * @param  Account|null  $stampExpenseAccount  DEBIT leg (fiscal charge borne by the
     *                                             company) for a positive
     *                                             $stampDutyAmount; null when the chart
     *                                             cannot represent it or $stampDutyAmount
     *                                             is `'0'`.
     * @param  Account|null  $stampPayableAccount  CREDIT leg (liability owed to the
     *                                             State) for a positive
     *                                             $stampDutyAmount; null under the same
     *                                             conditions as $stampExpenseAccount.
     */
    public function __construct(
        public string $revenue,
        public string $vat,
        public string $residual,
        public array $taxByRate,
        public ?Account $absorbingAccount,
        public ?GlResidualRefusal $refusal,
        public bool $balanceAssertable = true,
        public string $stampDutyAmount = '0',
        public ?Account $stampExpenseAccount = null,
        public ?Account $stampPayableAccount = null,
    ) {}

    public function isPostable(): bool
    {
        return $this->refusal === null;
    }
}
