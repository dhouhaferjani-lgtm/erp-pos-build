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

    public static function targetAlreadyWithdrawn(?string $documentNumber, ?string $targetNumber): self
    {
        return new self(
            sprintf(
                'Document %s has already been withdrawn — its ledger entry was reversed when it was '
                .'cancelled. Correcting entry %s cannot repair it: the reversal has already run and is '
                .'idempotent, so these legs would stand in the ledger with nothing left to mirror them '
                .'out. A withdrawn document is corrected by issuing a new one, never by adding to the '
                .'entry that was already unwound.',
                $targetNumber ?? '(unnumbered)',
                $documentNumber ?? '(unnumbered)',
            ),
            CorrectingEntryRefusalCode::TargetAlreadyWithdrawn,
            $documentNumber,
        );
    }

    public static function legAmountBeyondCurrencyScale(
        ?string $documentNumber,
        string $accountId,
        string $amount,
        string $currency,
        int $scale,
    ): self {
        return new self(
            sprintf(
                'Correcting entry %s posts %s to account %s, which carries more decimals than %s admits '
                .'(%d). The ledger truncates at that scale, so the amount actually posted would differ '
                .'from the amount stated — on a CORRECTION that means the repair made is not the repair '
                .'the accountant described. State the amount at %d decimals.',
                $documentNumber ?? '(unnumbered)',
                $amount,
                $accountId,
                $currency,
                $scale,
                $scale,
            ),
            CorrectingEntryRefusalCode::LegAmountBeyondCurrencyScale,
            $documentNumber,
        );
    }

    /**
     * @param  bool  $mayInheritFromTarget  Whether this control account is one that
     *                                      COULD have taken the target's partner —
     *                                      false for the supplier side, whose
     *                                      remedy is different and is spelled out.
     */
    public static function controlAccountLegWithoutPartner(
        ?string $documentNumber,
        string $accountCode,
        bool $mayInheritFromTarget = true,
    ): self {
        $remedy = $mayInheritFromTarget
            ? 'Name the partner on the leg, or post the correction to a non-control account.'
            : 'This is a SUPPLIER control account, so it does not inherit the corrected document\'s '
                .'partner: the target is a customer document, and inheriting would stamp a customer '
                .'into the supplier subledger — a balance that reconciles and is still wrong. Name the '
                .'supplier explicitly on the leg.';

        return new self(
            sprintf(
                'Correcting entry %s posts to partner control account %s without naming a partner. A '
                .'control-account leg with no partner breaks subledger reconciliation permanently: the '
                .'control balance moves and no partner statement moves with it. %s',
                $documentNumber ?? '(unnumbered)',
                $accountCode,
                $remedy,
            ),
            CorrectingEntryRefusalCode::ControlAccountLegWithoutPartner,
            $documentNumber,
        );
    }

    public static function unknownPartner(?string $documentNumber, string $partnerId): self
    {
        return new self(
            sprintf(
                'Correcting entry %s names partner %s on a leg, which does not belong to this company. '
                .'A partner is scoped exactly as an account is: nothing downstream would catch a foreign '
                .'one — the subledger reconciler does not scope partners by company, so the divergence '
                .'would be silent and, the journal being immutable, permanent.',
                $documentNumber ?? '(unnumbered)',
                $partnerId,
            ),
            CorrectingEntryRefusalCode::UnknownPartner,
            $documentNumber,
        );
    }

    public static function vatLegInFiledPeriod(
        ?string $documentNumber,
        ?string $targetNumber,
        string $accountCode,
    ): self {
        return new self(
            sprintf(
                'Correcting entry %s moves VAT control account %s, but the VAT period covering document '
                .'%s is already FILED. The declaration is with the tax authority; moving VAT inside that '
                .'period would diverge the ledger from the return with no reconciliation path. Correct '
                .'the non-VAT legs here and settle the VAT through the next declaration.',
                $documentNumber ?? '(unnumbered)',
                $accountCode,
                $targetNumber ?? '(unnumbered)',
            ),
            CorrectingEntryRefusalCode::VatLegInFiledPeriod,
            $documentNumber,
        );
    }
}
