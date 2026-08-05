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
     * @param  numeric-string  $residual  total − revenue − vat (signed)
     * @param  array<numeric-string, numeric-string>  $taxByRate  rate => recomputed tax
     * @param  Account|null  $absorbingAccount  where a positive residual is booked
     * @param  GlResidualRefusal|null  $refusal  null when the document is postable
     * @param  bool  $balanceAssertable  false for a document with NO lines, whose GL
     *                                   entry has no revenue side to balance against
     *                                   — a pre-existing shape this lane does not
     *                                   change. See `residualPlan()`.
     */
    public function __construct(
        public string $revenue,
        public string $vat,
        public string $residual,
        public array $taxByRate,
        public ?Account $absorbingAccount,
        public ?GlResidualRefusal $refusal,
        public bool $balanceAssertable = true,
    ) {}

    public function isPostable(): bool
    {
        return $this->refusal === null;
    }
}
