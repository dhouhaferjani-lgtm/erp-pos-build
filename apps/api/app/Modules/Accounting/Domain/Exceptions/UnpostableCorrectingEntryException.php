<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Exceptions;

use App\Modules\Accounting\Domain\Enums\CorrectingEntryRefusalCode;
use DomainException;

/**
 * Thrown when a correcting-entry DOCUMENT cannot be posted to the ledger (R2-F4).
 *
 * Owner ruling c4 (`docs/superpowers/tickets/2026-08-07-round2-rulings-record.md`)
 * made the correction a document linked to the original. This exception carries
 * every reason such a document may be refused, each with a stable
 * {@see CorrectingEntryRefusalCode} the API and the front end can branch on.
 *
 * Extending `DomainException` already renders as a 422 through the generic
 * `bootstrap/app.php` handler; a dedicated renderer is registered before it so
 * the typed code survives into the envelope instead of collapsing to
 * `BUSINESS_ERROR`.
 *
 * The refusal is raised INSIDE the posting transaction, so nothing is ever
 * half-written: neither the journal entry nor the document's status change
 * survives a refusal.
 */
final class UnpostableCorrectingEntryException extends DomainException
{
    private function __construct(
        string $message,
        public readonly CorrectingEntryRefusalCode $refusalCode,
        public readonly ?string $documentNumber,
    ) {
        parent::__construct($message);
    }

    public static function missingSourceDocument(?string $documentNumber): self
    {
        return new self(
            sprintf(
                'Correcting entry %s names no original document. A correction must always be linked to '
                .'the document it repairs — an unlinked correction is a free-floating journal entry, '
                .'which is exactly what the correction mechanism may not be.',
                $documentNumber ?? '(unnumbered)',
            ),
            CorrectingEntryRefusalCode::MissingSourceDocument,
            $documentNumber,
        );
    }

    public static function targetNotFound(?string $documentNumber, string $targetId): self
    {
        return new self(
            sprintf(
                'Correcting entry %s points at document %s, which does not exist in this company.',
                $documentNumber ?? '(unnumbered)',
                $targetId,
            ),
            CorrectingEntryRefusalCode::TargetNotFound,
            $documentNumber,
        );
    }

    public static function unsupportedTargetType(?string $documentNumber, string $targetType): self
    {
        return new self(
            sprintf(
                'A correcting entry can only repair an invoice or a credit note; document type "%s" keeps '
                .'its ledger entries under a different source key, so the balance invariant this '
                .'correction enforces would not describe it. Correcting entry: %s.',
                $targetType,
                $documentNumber ?? '(unnumbered)',
            ),
            CorrectingEntryRefusalCode::UnsupportedTargetType,
            $documentNumber,
        );
    }

    public static function targetHasNoLedgerEntry(?string $documentNumber, ?string $targetNumber): self
    {
        return new self(
            sprintf(
                'Document %s has no posted journal entry, so there is nothing for correcting entry %s '
                .'to repair.',
                $targetNumber ?? '(unnumbered)',
                $documentNumber ?? '(unnumbered)',
            ),
            CorrectingEntryRefusalCode::TargetHasNoLedgerEntry,
            $documentNumber,
        );
    }

    public static function unknownAccount(?string $documentNumber, string $accountId): self
    {
        return new self(
            sprintf(
                'Correcting entry %s posts to account %s, which does not belong to this company\'s chart '
                .'of accounts.',
                $documentNumber ?? '(unnumbered)',
                $accountId,
            ),
            CorrectingEntryRefusalCode::UnknownAccount,
            $documentNumber,
        );
    }

    /**
     * @param  numeric-string  $totalDebits
     * @param  numeric-string  $totalCredits
     */
    public static function leavesTargetUnbalanced(
        ?string $documentNumber,
        ?string $targetNumber,
        string $totalDebits,
        string $totalCredits,
    ): self {
        return new self(
            sprintf(
                'Correcting entry %s would leave document %s out of balance (debits %s, credits %s across '
                .'its ledger entry and every correction applied to it). A correcting entry must bring the '
                .'document it repairs back into balance — otherwise it adds an entry to the chain without '
                .'fixing anything.',
                $documentNumber ?? '(unnumbered)',
                $targetNumber ?? '(unnumbered)',
                $totalDebits,
                $totalCredits,
            ),
            CorrectingEntryRefusalCode::LeavesTargetUnbalanced,
            $documentNumber,
        );
    }

    public static function alreadyPosted(?string $documentNumber): self
    {
        return new self(
            sprintf('Correcting entry %s has already been posted to the ledger.', $documentNumber ?? '(unnumbered)'),
            CorrectingEntryRefusalCode::AlreadyPosted,
            $documentNumber,
        );
    }

    public static function malformedPayload(?string $documentNumber, string $detail): self
    {
        return new self(
            sprintf(
                'Correcting entry %s does not carry a well-formed set of legs: %s',
                $documentNumber ?? '(unnumbered)',
                $detail,
            ),
            CorrectingEntryRefusalCode::MalformedPayload,
            $documentNumber,
        );
    }
}
