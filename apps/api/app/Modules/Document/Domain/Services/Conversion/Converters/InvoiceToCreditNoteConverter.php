<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Conversion\Converters;

use App\Modules\Document\Application\Services\CreditNoteService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterInterface;

/**
 * Converter for Invoice to Credit Note conversion.
 *
 * Supports two modes:
 * 1. Amount-based: Credit a fixed amount from an invoice
 * 2. Line-based: Credit specific line items with quantities
 *
 * Options:
 * - 'amount' (string): Fixed amount to credit (mutually exclusive with 'lines')
 * - 'lines' (array): Array of [{line_id, quantity}] for partial line credits
 * - 'reason' (string): CreditNoteReason enum value (required)
 * - 'notes' (string): Optional notes explaining the credit
 *
 * Validates:
 * - Source must be Invoice type
 * - Invoice must be posted (not draft or confirmed)
 * - Credit amount/lines cannot exceed invoice total
 * - Total credits (including existing) cannot exceed invoice total
 * - For line-based: All line IDs must belong to the invoice
 * - For line-based: Quantities must be positive and <= invoice line quantity
 *
 * On conversion:
 * - Creates a new CreditNote in Draft status
 * - Sets source_document_id to link back to invoice
 * - Copies vehicle context if present
 * - Credit note amounts are positive (not negative)
 * - Credit note must be confirmed and posted separately
 */
final class InvoiceToCreditNoteConverter implements DocumentConverterInterface
{
    public function __construct(
        private readonly CreditNoteService $creditNoteService
    ) {}

    public function sourceType(): DocumentType
    {
        return DocumentType::Invoice;
    }

    public function targetType(): DocumentType
    {
        return DocumentType::CreditNote;
    }

    public function canConvert(Document $source): bool
    {
        return empty($this->getConversionErrors($source));
    }

    /**
     * @return array<int, string>
     */
    public function getConversionErrors(Document $source): array
    {
        $errors = [];

        // Validate source type
        if ($source->type !== DocumentType::Invoice) {
            $errors[] = 'Source document must be an Invoice. Current type: '.$source->type->value;
        }

        // Validate invoice is posted
        if (! $source->isPosted()) {
            $errors[] = 'Only posted invoices can have credit notes. Current status: '.$source->status->value;
        }

        // Validate invoice has a positive total
        if (bccomp($source->total ?? '0', '0', 4) <= 0) {
            $errors[] = 'Invoice total must be greater than zero';
        }

        // Validate invoice is not cancelled
        if ($source->isCancelled()) {
            $errors[] = 'Cannot create credit note for cancelled invoice';
        }

        return $errors;
    }

    /**
     * Convert invoice to credit note.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws \InvalidArgumentException
     */
    public function convert(Document $source, array $options = []): Document
    {
        // Pre-conversion validation
        $errors = $this->getConversionErrors($source);
        if (! empty($errors)) {
            throw new \InvalidArgumentException(
                'Cannot convert invoice to credit note: '.implode('; ', $errors)
            );
        }

        // Validate required 'reason' option
        if (! isset($options['reason'])) {
            throw new \InvalidArgumentException("Option 'reason' is required for credit note creation");
        }

        // Parse reason enum
        $reason = $this->parseReason($options['reason']);

        // Extract notes
        $notes = $options['notes'] ?? null;

        // Determine conversion mode: amount-based or line-based
        $isLineBased = isset($options['lines']) && is_array($options['lines']);
        $isAmountBased = isset($options['amount']);

        // Validate mutually exclusive modes
        if ($isLineBased && $isAmountBased) {
            throw new \InvalidArgumentException(
                "Options 'amount' and 'lines' are mutually exclusive. Choose one."
            );
        }

        if (! $isLineBased && ! $isAmountBased) {
            throw new \InvalidArgumentException(
                "Either 'amount' or 'lines' option is required for credit note creation"
            );
        }

        // Perform conversion using appropriate method
        if ($isLineBased) {
            return $this->convertLineBased($source, $options['lines'], $reason, $notes);
        }

        return $this->convertAmountBased($source, $options['amount'], $reason, $notes);
    }

    /**
     * Create amount-based credit note.
     */
    private function convertAmountBased(
        Document $invoice,
        string $amount,
        CreditNoteReason $reason,
        ?string $notes
    ): Document {
        // Validate amount format
        if (! is_numeric($amount) || bccomp($amount, '0', 4) <= 0) {
            throw new \InvalidArgumentException('Credit note amount must be a positive number');
        }

        // Delegate to CreditNoteService
        return $this->creditNoteService->createCreditNote(
            sourceInvoiceId: $invoice->id,
            amount: $amount,
            reason: $reason,
            notes: $notes
        );
    }

    /**
     * Create line-based credit note.
     *
     * @param  array<array{line_id: string, quantity: numeric}>  $lines
     */
    private function convertLineBased(
        Document $invoice,
        array $lines,
        CreditNoteReason $reason,
        ?string $notes
    ): Document {
        // Validate lines format
        if (empty($lines)) {
            throw new \InvalidArgumentException('At least one line is required for line-based credit note');
        }

        // Note: PHPDoc type guarantees correct structure. CreditNoteService will validate business rules.

        // Delegate to CreditNoteService
        return $this->creditNoteService->createLineBasedCreditNote(
            sourceInvoiceId: $invoice->id,
            lines: $lines,
            reason: $reason,
            notes: $notes
        );
    }

    /**
     * Parse reason from string or enum.
     */
    private function parseReason(mixed $reason): CreditNoteReason
    {
        if ($reason instanceof CreditNoteReason) {
            return $reason;
        }

        if (is_string($reason)) {
            $creditNoteReason = CreditNoteReason::tryFrom($reason);
            if ($creditNoteReason === null) {
                $validValues = implode(', ', array_map(
                    fn (CreditNoteReason $r) => $r->value,
                    CreditNoteReason::cases()
                ));
                throw new \InvalidArgumentException(
                    "Invalid credit note reason: {$reason}. Valid values: {$validValues}"
                );
            }

            return $creditNoteReason;
        }

        throw new \InvalidArgumentException('Reason must be a string or CreditNoteReason enum');
    }
}
