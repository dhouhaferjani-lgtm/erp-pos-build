<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;

/**
 * Raised when auto-save refuses to touch the document it was pointed at —
 * because that document is no longer a draft, is fiscally sealed, or is not of
 * the type the request claimed.
 *
 * P1 (ticket 2026-08-22 §1): `DraftPersistenceService::saveDraft()` had no
 * status filter, so a `draft_id` naming an already-`Confirmed` — or `Posted`,
 * or `Cancelled` — document had its ENTIRE line set replaced by whatever the
 * caller sent, including nothing at all. That is how a correctly-authored
 * invoice became lineless after the fact.
 *
 * The predicate is the module's own `Document::isDraft()` /
 * `Document::isFiscallyImmutable()` — the same pair every other DRAFT-scoped
 * service in this module guards on (`DraftPurchaseOrderService::appendLines()`,
 * `POSAccountChargeDraftService::assertExistingDraftMatches()`,
 * `CorrectingEntryService`) — not a list re-derived here.
 *
 * It is a `\DomainException` rather than an `AuthorizationException` on purpose:
 * `DraftController::autoSave()` catches `\Throwable` and answers 200 so a failed
 * auto-save never interrupts typing, and this refusal must NOT be swallowed by
 * that arm. The controller catches this type FIRST and renders the module's
 * standard `{error: {code, message}}` envelope.
 */
final class DraftNotEditableException extends \DomainException
{
    public const CODE_NOT_EDITABLE = 'DOCUMENT_NOT_EDITABLE';

    public const CODE_SEALED = 'DOCUMENT_SEALED';

    public const CODE_TYPE_MISMATCH = 'DOCUMENT_TYPE_MISMATCH';

    private function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function statusIsNotDraft(DocumentStatus $status): self
    {
        return new self(
            self::CODE_NOT_EDITABLE,
            sprintf(
                'Auto-save only applies to draft documents; this document is %s.',
                $status->label()
            ),
        );
    }

    public static function fiscallySealed(): self
    {
        return new self(
            self::CODE_SEALED,
            'Sealed fiscal documents cannot be modified. Use credit notes for corrections.',
        );
    }

    /**
     * The request's `type` does not describe the document it is aimed at.
     *
     * Gate R2-1: this is an AUTHORIZATION guard wearing a contract's clothes.
     * `AutoSaveDraftRequest::authorize()` resolves the per-type `*.create`
     * ability from the CLIENT-supplied `type`, and on the update branch the
     * service otherwise ignores that field — so without this check a caller who
     * held `quotes.create` and nothing else could name an INVOICE draft, claim
     * `type: quote`, pass the gate, and have every line stripped. Requiring the
     * supplied type to equal the persisted one makes "authorized for the claimed
     * type" equivalent to "authorized for the actual type", which is what the
     * create branch gets for free (there, the claimed type IS the new
     * document's type).
     *
     * It closes the same hole for correcting entries: they are created
     * `DocumentStatus::Draft` / `FiscalStatus::Draft`, so the draft-editability
     * guard alone would wave a spoofed request through at a document whose every
     * route is admin-tier by owner ruling.
     */
    public static function typeMismatch(?DocumentType $supplied, DocumentType $persisted): self
    {
        return new self(
            self::CODE_TYPE_MISMATCH,
            sprintf(
                'This draft is a %s document; auto-save was sent %s.',
                $persisted->value,
                $supplied === null ? 'no document type' : $supplied->value,
            ),
        );
    }
}
