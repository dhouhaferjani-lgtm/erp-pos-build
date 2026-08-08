<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury;

use App\Modules\Treasury\Application\DTOs\RepositoryAdjustmentIntent;
use App\Shared\Contracts\Treasury\DTOs\RepositoryAdjustmentResult;

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
     * Refusals, every one of which writes NOTHING. They are DESCRIBED rather
     * than `@throws`-typed on purpose: naming Treasury `Domain\Exceptions`
     * classes from this Shared contract is a deptrac `SharedContracts on
     * ModuleDomain` boundary violation, and a contract that leaks its
     * implementation's internals is exactly what the rule-6 story here is meant
     * to avoid. The concrete classes are on the implementation
     * (`Treasury\Application\Services\RepositoryAdjustmentService`), and the
     * HTTP adapter catches the two it renders as 422s.
     *
     *  - tolerance-account missing — the chart of accounts has no account for
     *    the required 658/758 purpose;
     *  - amount-below-currency-precision — the amount normalizes to zero at the
     *    repository's currency scale;
     *  - `\DomainException` — the repository has no linked GL account;
     *  - model-not-found — the repository or the acting user is not in scope;
     *  - currency-mismatch — the intent currency disagrees with the repository's;
     *  - repository-frozen — adjustments are always interactive
     *    (`allowWhileFrozen` is false);
     *  - insufficient-balance — an OUT adjustment would take a repository that
     *    forbids negative balances below zero.
     */
    public function post(RepositoryAdjustmentIntent $intent): RepositoryAdjustmentResult;
}
