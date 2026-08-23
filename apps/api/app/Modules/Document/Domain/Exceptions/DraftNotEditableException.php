<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use App\Modules\Document\Domain\Enums\DocumentStatus;

/**
 * Raised when auto-save is pointed at a document that is no longer a draft.
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
}
