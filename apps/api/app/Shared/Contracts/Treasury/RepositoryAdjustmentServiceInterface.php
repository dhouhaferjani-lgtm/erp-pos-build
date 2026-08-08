<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury;

use App\Modules\Treasury\Application\DTOs\RepositoryAdjustmentIntent;
use App\Modules\Treasury\Application\DTOs\RepositoryAdjustmentResult;
use App\Modules\Treasury\Domain\Exceptions\AdjustmentAmountBelowCurrencyPrecisionException;
use App\Modules\Treasury\Domain\Exceptions\AdjustmentToleranceAccountMissingException;
use App\Modules\Treasury\Domain\Exceptions\CurrencyMismatchException;
use App\Modules\Treasury\Domain\Exceptions\InsufficientRepositoryBalanceException;
use App\Modules\Treasury\Domain\Exceptions\RepositoryFrozenException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The single orchestration port for a repository (cash) adjustment —
 * document-per-action remediation lane V3/G3.
 *
 * ONE call writes, atomically and in this order:
 *   1. the justifying `repository_adjustments` DOCUMENT (document-first, so the
 *      `source_id` the next two artifacts carry addresses a row that exists),
 *   2. the balanced 658/758 journal entry, POSTED synchronously (the spine's
 *      recon-readiness invariant: a cash movement must carry a non-null
 *      `journal_entry_id` at the instant it is written),
 *   3. the `MovementSourceType::Adjustment` movement through the treasury write
 *      port,
 * then backfills the document's write-once linkage columns.
 *
 * Extracted out of `RepositoryAdjustmentController` as the V3 gate's BLOCKING
 * G3 precondition, so the POS shift-close cash-variance lane consumes this
 * contract instead of duplicating the orchestration or importing the Treasury
 * `RepositoryAdjustment` domain model across a module boundary (CLAUDE.md
 * rule 6).
 *
 * NO CompanyContext dependency, by design: every scope value arrives on the
 * intent and every scale is resolved from the target repository's OWN currency,
 * because the G3 consumer runs in a listener/queue context where
 * `CompanyContext::requireCompany()` and a bare no-arg `getScale()` both throw
 * (CLAUDE.md rules 19 + 20).
 */
interface RepositoryAdjustmentServiceInterface
{
    /**
     * Post one repository adjustment.
     *
     * Idempotent on `$intent->adjustmentId`: a replay resolves to the existing
     * document, REUSES the journal entry that document already justifies (no
     * second posted entry), and the write port returns the existing movement
     * with `wasIdempotentHit = true`. Callers that can legitimately replay
     * (offline Z-report re-sync) must therefore derive `adjustmentId`
     * deterministically from their own natural key.
     *
     * Opens its own `DB::transaction` — callers must NOT pre-open one merely to
     * gain atomicity, though nesting is safe.
     *
     * @throws AdjustmentToleranceAccountMissingException chart of accounts lacks the 658/758 purpose (nothing written)
     * @throws AdjustmentAmountBelowCurrencyPrecisionException amount normalizes to zero at the repository's scale (nothing written)
     * @throws \DomainException the repository has no linked GL account (nothing written)
     * @throws ModelNotFoundException repository or acting user not found in scope
     * @throws CurrencyMismatchException intent currency disagrees with the repository's
     * @throws RepositoryFrozenException the repository is frozen (adjustments are always interactive)
     * @throws InsufficientRepositoryBalanceException an OUT adjustment would take the repository below zero
     */
    public function post(RepositoryAdjustmentIntent $intent): RepositoryAdjustmentResult;
}
