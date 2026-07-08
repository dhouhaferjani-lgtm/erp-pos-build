<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury;

use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\MovementResult;
use App\Modules\Treasury\Application\DTOs\TransferIntent;
use App\Modules\Treasury\Application\DTOs\TransferResult;
use App\Modules\Treasury\Domain\Exceptions\CurrencyMismatchException;

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
     * CONTRACT — journal_entry_id is NOT compared on replay: `handleIdempotentHit`
     * validates repository/direction/amount/currency/source_type/source_id but
     * does not check `journal_entry_id` against the stored row. Callers MUST
     * NOT post a new GL entry and then replay the same idempotency key — doing
     * so orphans the freshly-posted GL entry, because the replay silently
     * returns the OLD movement (pointing at the OLD journal entry) instead of
     * linking the new one. The caller's GL post (`postEntryNow` or equivalent)
     * must itself be idempotent/keyed alongside the movement, so a retried
     * intent never produces a second, unlinked GL entry in the first place.
     *
     * @throws \LogicException When called outside a DB transaction (MED-9).
     */
    public function record(MovementIntent $intent): MovementResult;

    /**
     * Move funds between two treasury repositories as an atomic paired out/in
     * leg (Task 12 — spec §5.3).
     *
     * Unlike {@see record()}, `transfer()` OWNS its dedicated `DB::transaction`.
     * Inside it, the global lock order is honoured: the GL company advisory lock
     * is taken FIRST, then BOTH repositories are locked in `sort([fromId, toId])`
     * order (deadlock-free against opposing concurrent transfers), then the two
     * legs are written — an `out` leg on the source and an `in` leg on the
     * destination, sharing the intent's `transfer_group_id`, each advancing its
     * own repository's gapless ordinal and cached balance.
     *
     * Exactly ONE journal entry is posted (synchronously in-transaction via
     * `postEntryNow`) — and only when the two repositories map to DIFFERENT
     * `gl_account_id`. A transfer between two repositories backed by the same GL
     * account has no net GL effect, so no entry is posted and both legs carry a
     * null `journal_entry_id` (the spec's nullable exemption for same-GL-account
     * transfer legs).
     *
     * @throws CurrencyMismatchException
     *                                   When the intent currency does not match both repositories.
     */
    public function transfer(TransferIntent $intent): TransferResult;
}
