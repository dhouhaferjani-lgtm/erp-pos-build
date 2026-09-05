<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use App\Modules\Document\Domain\Services\DocumentStatusService;
use DomainException;

/**
 * Raised when a draft is asked to become a real, numbered document while
 * carrying a `due_date` (or `valid_until`) that precedes its own
 * `document_date`.
 *
 * DEV-QA-008/057, gate r2 finding N-1. The FormRequest guards on create,
 * update and auto-save are the primary defence, but none of them is on the
 * path a draft actually travels to become a document: every `confirm()` action
 * takes a bare `Illuminate\Http\Request` and re-validates no dates
 * (`QuoteController.php:488` and its seven siblings). A row that reached the
 * database through any other route — a legacy draft, an importer, a future
 * writer, or an auto-save arm nobody has thought of yet — was therefore
 * confirmed and NUMBERED with the exact inconsistency this lane exists to
 * refuse. The reviewer reproduced that end-to-end on the shipped UI path.
 *
 * This is the defence in depth for it, raised from
 * {@see DocumentStatusService::transition()} — the ONE place a `documents` row
 * changes lifecycle status and the one place it is numbered — on the
 * `Draft -> Confirmed` edge only. It is deliberately NOT raised on
 * `Draft -> Posted` (expense, income and supplier invoice post directly from
 * draft through their own already-guarded request classes) nor on any later
 * edge: a document that is already confirmed must stay correctable through the
 * credit-note path, never bricked by a guard added after it was sealed.
 *
 * Extends `DomainException` on purpose. Every confirm action already ends in
 * `catch (\DomainException $e) => validationErrorResponse(...)`, so the refusal
 * surfaces as HTTP 422 carrying this message on all of them without touching
 * eight controllers; `bootstrap/app.php` renders the typed
 * `DOCUMENT_DATES_INCONSISTENT` envelope with a `due_date` / `valid_until`
 * error bag for any caller that does not catch it.
 */
final class DocumentDatesInconsistentException extends DomainException
{
    /**
     * @param  string  $attribute  `due_date` or `valid_until` — the field the error bag is keyed on.
     */
    public function __construct(
        string $message,
        public readonly string $documentId,
        public readonly string $attribute,
        public readonly string $documentDate,
        public readonly string $offendingDate,
    ) {
        parent::__construct($message);
    }

    public static function dueDateBeforeDocumentDate(
        string $documentId,
        string $documentDate,
        string $dueDate,
    ): self {
        return new self(
            (string) __('documents.dates.due_date_before_document_date', ['document_date' => $documentDate]),
            $documentId,
            'due_date',
            $documentDate,
            $dueDate,
        );
    }

    public static function validUntilBeforeDocumentDate(
        string $documentId,
        string $documentDate,
        string $validUntil,
    ): self {
        return new self(
            (string) __('documents.dates.valid_until_before_document_date', ['document_date' => $documentDate]),
            $documentId,
            'valid_until',
            $documentDate,
            $validUntil,
        );
    }
}
