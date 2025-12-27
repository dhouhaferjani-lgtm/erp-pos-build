<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Exceptions;

/**
 * Exception thrown when attempting to modify or delete an immutable journal entry.
 *
 * Journal entries become immutable once they are assigned a fiscal_hash as part
 * of the hash chain. This ensures fiscal compliance and prevents tampering with
 * accounting records.
 */
class ImmutableJournalEntryException extends \RuntimeException
{
    /**
     * Create exception for attempted update of immutable entry.
     */
    public static function cannotUpdate(string $entryNumber): self
    {
        return new self(
            "Cannot update journal entry {$entryNumber} because it is part of an immutable hash chain. "
            .'Immutable entries cannot be modified for fiscal compliance reasons.'
        );
    }

    /**
     * Create exception for attempted deletion of immutable entry.
     */
    public static function cannotDelete(string $entryNumber): self
    {
        return new self(
            "Cannot delete journal entry {$entryNumber} because it is part of an immutable hash chain. "
            .'Immutable entries cannot be deleted for fiscal compliance reasons. Use reversals instead.'
        );
    }

    /**
     * Create exception for attempted update of lines belonging to immutable entry.
     */
    public static function cannotUpdateLine(string $entryNumber): self
    {
        return new self(
            "Cannot update journal lines for entry {$entryNumber} because the entry is part of an immutable hash chain. "
            .'Lines of immutable entries cannot be modified for fiscal compliance reasons.'
        );
    }

    /**
     * Create exception for attempted deletion of lines belonging to immutable entry.
     */
    public static function cannotDeleteLine(string $entryNumber): self
    {
        return new self(
            "Cannot delete journal lines for entry {$entryNumber} because the entry is part of an immutable hash chain. "
            .'Lines of immutable entries cannot be deleted for fiscal compliance reasons.'
        );
    }
}
