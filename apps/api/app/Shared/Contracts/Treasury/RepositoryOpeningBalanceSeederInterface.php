<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury;

use App\Shared\Contracts\Treasury\DTOs\OpeningFloatIntent;
use App\Shared\Contracts\Treasury\DTOs\OpeningFloatRepositoryDescriptor;
use App\Shared\Contracts\Treasury\DTOs\OpeningFloatResult;

/**
 * The ONE sanctioned way a treasury repository receives its day-one cash float
 * (campaign wave-4 §W4-2, P0).
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 * Before this contract there was NO working path. Treasury → "Adjust balance"
 * refuses a never-seeded repository (RepositoryNotSeededException) and would
 * book the float as REVENUE if it did not; the ACCOUNTING opening-balance
 * wizard posted the GL leg only; a transfer out of an empty till fails
 * INSUFFICIENT_REPOSITORY_BALANCE. `MovementSourceType::OpeningBalance` was
 * read by the reconciler and written by nothing. A day-one tenant could not
 * pay a supplier in cash, and GL cash exceeded treasury cash by the whole float.
 *
 * ── The path ─────────────────────────────────────────────────────────────────
 * The operator names a repository on the cash/bank line of the ACCOUNTING
 * opening batch (`repository_code` column). Posting that batch writes the GL
 * leg (Dr the repository's own cash/bank account / Cr Opening Balance Equity)
 * AND, through this port, the repository's opening movement — in ONE
 * transaction. The batch IS the justifying document (document-per-action): it
 * carries the cutover date and a Draft→Validated→Locked lifecycle.
 *
 * Module boundaries are sacred: Accounting depends on this Shared contract,
 * never on the Treasury service or its domain models.
 */
interface RepositoryOpeningBalanceSeederInterface
{
    /**
     * Describe a repository by its operator-facing code, for VALIDATION.
     *
     * Returns null when no ACTIVE repository with that code exists for the
     * tenant+company — the caller turns that into a row-level validation error.
     */
    public function describeByCode(
        string $tenantId,
        string $companyId,
        string $repositoryCode,
    ): ?OpeningFloatRepositoryDescriptor;

    /**
     * Seed the repository's opening float.
     *
     * MUST be called inside the caller's own `DB::transaction`, AFTER the GL
     * opening entry (and its line on the repository's account) has been
     * written, so the movement and its journal entry commit or roll back
     * together.
     *
     * Idempotent on `(batchId, repositoryId)`: a replay returns the existing
     * movement with `wasIdempotentHit = true` and writes nothing.
     *
     * Throws RepositoryAlreadySeededException (Treasury domain) when the
     * repository already holds treasury money from any OTHER source — an opening
     * float on a till that has already traded is never honest. Named in prose,
     * not an @throws FQCN: pint's fully_qualified_strict_types fixer turns those
     * into real `use` statements, which would make this Shared contract import a
     * module's Domain namespace and break the layering (CLAUDE.md rule 6).
     *
     * @throws \LogicException When called outside a DB transaction.
     */
    public function seed(OpeningFloatIntent $intent): OpeningFloatResult;
}
