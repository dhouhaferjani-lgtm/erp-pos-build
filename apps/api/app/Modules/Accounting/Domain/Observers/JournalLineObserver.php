<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Observers;

use App\Modules\Accounting\Domain\Exceptions\ImmutableJournalEntryException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;

/**
 * Observer to enforce immutability of journal lines belonging to chained entries.
 */
final class JournalLineObserver
{
    /**
     * Handle the JournalLine "creating" event.
     *
     * Prevent adding new lines to journal entries that are part of the hash chain.
     * Only throw if the entry already has lines (preventing additional lines).
     *
     * @throws ImmutableJournalEntryException
     */
    public function creating(JournalLine $line): void
    {
        if ($line->journal_entry_id) {
            $entry = JournalEntry::find($line->journal_entry_id);

            if ($entry && $entry->isChained()) {
                // Check if entry already has lines
                // If it does, prevent adding more lines
                $existingLineCount = JournalLine::where('journal_entry_id', $entry->id)->count();

                if ($existingLineCount > 0) {
                    throw ImmutableJournalEntryException::cannotUpdateLine($entry->entry_number);
                }
            }
        }
    }

    /**
     * Handle the JournalLine "updating" event.
     *
     * Prevent updates to lines of journal entries in the hash chain.
     *
     * @throws ImmutableJournalEntryException
     */
    public function updating(JournalLine $line): void
    {
        // Load entry relationship if not already loaded
        if (! $line->relationLoaded('journalEntry')) {
            $line->load('journalEntry');
        }

        // Check if the entry exists and is chained
        // @phpstan-ignore-next-line - relationship may return null even if PHPDoc says otherwise
        if ($line->journalEntry?->isChained()) {
            throw ImmutableJournalEntryException::cannotUpdateLine(
                $line->journalEntry->entry_number
            );
        }
    }

    /**
     * Handle the JournalLine "deleting" event.
     *
     * Prevent deletion of lines of journal entries in the hash chain.
     *
     * @throws ImmutableJournalEntryException
     */
    public function deleting(JournalLine $line): void
    {
        // Load entry relationship if not already loaded
        if (! $line->relationLoaded('journalEntry')) {
            $line->load('journalEntry');
        }

        // Check if the entry exists and is chained
        // @phpstan-ignore-next-line - relationship may return null even if PHPDoc says otherwise
        if ($line->journalEntry?->isChained()) {
            throw ImmutableJournalEntryException::cannotDeleteLine(
                $line->journalEntry->entry_number
            );
        }
    }
}
