<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

use App\Modules\Accounting\Domain\Exceptions\ImmutableJournalEntryException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Custom query builder for JournalEntry to intercept mass updates.
 *
 * @extends Builder<JournalEntry>
 */
class JournalEntryBuilder extends Builder
{
    /**
     * Update records in the database.
     *
     * Intercepts mass updates to prevent modification of chained entries.
     * Special case: Allow setting the fiscal_hash for the first time.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ImmutableJournalEntryException
     */
    public function update(array $values): int
    {
        // Special case: If this update is ONLY setting fiscal_hash fields for the first time, allow it
        // This allows the hash service to add the hash to a newly posted entry
        $isHashingOperation = isset($values['fiscal_hash'])
            && (count($values) === 1 || (count($values) === 2 && isset($values['chain_sequence'])) || (count($values) === 3 && isset($values['chain_sequence']) && isset($values['previous_hash'])));

        if ($isHashingOperation) {
            // Allow hash assignment - this is the posting operation
            return parent::update($values);
        }

        // For other updates, check if any of the records being updated are already chained
        $chainedEntries = (clone $this)->whereNotNull('fiscal_hash')->get();

        if ($chainedEntries->isNotEmpty()) {
            /** @var JournalEntry $firstEntry */
            $firstEntry = $chainedEntries->first();
            throw ImmutableJournalEntryException::cannotUpdate($firstEntry->entry_number);
        }

        // If no chained entries, proceed with normal update
        return parent::update($values);
    }

    /**
     * Delete records from the database.
     *
     * Intercepts mass deletes to prevent deletion of chained entries.
     *
     *
     * @throws ImmutableJournalEntryException
     */
    public function delete(): int
    {
        // Check if any of the records being deleted are chained
        $chainedEntries = (clone $this)->whereNotNull('fiscal_hash')->get();

        if ($chainedEntries->isNotEmpty()) {
            /** @var JournalEntry $firstEntry */
            $firstEntry = $chainedEntries->first();
            throw ImmutableJournalEntryException::cannotDelete($firstEntry->entry_number);
        }

        // If no chained entries, proceed with normal delete
        return parent::delete();
    }
}
