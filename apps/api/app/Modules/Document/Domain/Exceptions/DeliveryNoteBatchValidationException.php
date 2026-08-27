<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use DomainException;

final class DeliveryNoteBatchValidationException extends DomainException
{
    /**
     * R-2 / LEDGER D-T9-1 — `document_number` is NULLABLE here, and deliberately.
     * The `not_confirmed` arm exists precisely to refuse a DRAFT delivery note,
     * and a draft carries no number until it is confirmed. Naming it by a
     * fabricated string would be worse than naming it by nothing: the caller
     * has the `id`, and the presentation layer renders the draft placeholder.
     *
     * @param list<array{
     *     id: string,
     *     document_number: string|null,
     *     reason: 'already_invoiced'|'wrong_partner'|'wrong_currency'|'not_confirmed'|'cancelled'|'no_lines'|'partial_selection_incomplete'
     * }> $documents
     */
    public function __construct(public readonly array $documents)
    {
        $reasons = array_values(array_unique(array_column($documents, 'reason')));
        $message = count($reasons) === 1
            ? match ($reasons[0]) {
                'already_invoiced' => 'Delivery note has already been invoiced',
                'wrong_partner' => 'All delivery notes must belong to the same partner',
                'wrong_currency' => 'All delivery notes must have the same currency',
                'not_confirmed' => 'Delivery note must be confirmed before invoicing',
                'cancelled' => 'Cannot invoice cancelled delivery note',
                'no_lines' => 'Delivery note must have at least one line item',
                'partial_selection_incomplete' => 'Selected order lines must cover every line on the delivery note',
            }
        : 'One or more delivery notes cannot be consolidated.';

        parent::__construct($message);
    }

    public function containsAlreadyInvoiced(): bool
    {
        foreach ($this->documents as $document) {
            if ($document['reason'] === 'already_invoiced') {
                return true;
            }
        }

        return false;
    }
}
