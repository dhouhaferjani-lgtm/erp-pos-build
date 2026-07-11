<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

/**
 * Controls WHEN a drafted journal entry is sealed into the hash chain.
 *
 * - AfterCommit: the historical default. When inside a transaction, the entire
 *   post (status -> Posted, hash seal, event) is deferred to DB::afterCommit,
 *   so the caller commits a Draft entry inside its transaction and the GL post
 *   happens post-commit. Zero behaviour change for every existing caller.
 * - SynchronousInTransaction: seals + persists the entry INSIDE the caller's
 *   transaction (only the JournalEntryPosted event stays deferred to afterCommit),
 *   so a money movement and its GL posting are atomic — they commit or roll back
 *   together. Used by the Treasury money-movement spine.
 */
enum PostingMode: string
{
    case AfterCommit = 'after_commit';
    case SynchronousInTransaction = 'synchronous_in_transaction';
}
