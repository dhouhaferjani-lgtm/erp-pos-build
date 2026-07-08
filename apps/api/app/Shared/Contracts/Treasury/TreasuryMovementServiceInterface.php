<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury;

use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\MovementResult;

/**
 * The single write port every treasury money-movement converges onto
 * (Treasury Money-Movement Spine, Task 11).
 *
 * `record()` writes exactly one append-only `repository_movements` row,
 * advances the repository's gapless ordinal, and updates the cached balance —
 * atomically with the caller's GL post. It is concurrency- and
 * idempotency-critical: the caller MUST already own an outer `DB::transaction`
 * (spec §5.4 / MED-9) inside which the GL entry was posted via `postEntryNow`
 * (which takes the GL company advisory lock BEFORE this port takes the repo
 * row lock — the global lock order, BLOCKER-1).
 *
 * Module boundaries are sacred: cross-module consumers depend on this Shared
 * contract, never on the concrete service or the Treasury domain models.
 */
interface TreasuryMovementServiceInterface
{
    /**
     * Record one movement leg against a treasury repository.
     *
     * Idempotent on `MovementIntent::idempotencyKey()`: a replayed intent with
     * an already-recorded key returns the existing movement
     * (`wasIdempotentHit = true`) with no new row and no ordinal gap; a replay
     * whose semantic fields disagree with the stored row throws loudly.
     *
     * @throws \LogicException When called outside a DB transaction (MED-9).
     */
    public function record(MovementIntent $intent): MovementResult;
}
