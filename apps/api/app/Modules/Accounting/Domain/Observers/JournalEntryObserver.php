<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Observers;

use App\Modules\Accounting\Domain\Exceptions\ImmutableJournalEntryException;
use App\Modules\Accounting\Domain\JournalEntry;

/**
 * Observer to enforce immutability of journal entries in hash chain.
 *
 * Once a journal entry has a fiscal_hash (is part of the hash chain),
 * it cannot be modified or deleted to maintain fiscal compliance and
 * chain integrity.
 */
final class JournalEntryObserver
{
    /**
     * Handle the JournalEntry "updating" event.
     *
     * Only throw if the entry ALREADY HAD a hash (was already chained).
     * This allows the initial hash assignment but prevents subsequent modifications.
     *
     * @throws ImmutableJournalEntryException
     */
    public function updating(JournalEntry $entry): void
    {
        // Check if the entry had a hash in its ORIGINAL state (before this update)
        // If it did, it's already chained and cannot be modified
        $originalHash = $entry->getOriginal('fiscal_hash');

        if ($originalHash !== null) {
            throw ImmutableJournalEntryException::cannotUpdate($entry->entry_number);
        }
    }

    /**
     * Handle the JournalEntry "deleting" event.
     *
     * @throws ImmutableJournalEntryException
     */
    public function deleting(JournalEntry $entry): void
    {
        if ($entry->isChained()) {
            throw ImmutableJournalEntryException::cannotDelete($entry->entry_number);
        }
    }
}
