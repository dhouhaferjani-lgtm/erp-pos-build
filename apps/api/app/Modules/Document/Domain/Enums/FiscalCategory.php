<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

enum FiscalCategory: string
{
    case NonFiscal = 'NON_FISCAL';
    case FiscalReceipt = 'FISCAL_RECEIPT';
    case TaxInvoice = 'TAX_INVOICE';
    case CreditNote = 'CREDIT_NOTE';
    case DeliveryNote = 'DELIVERY_NOTE';

    /**
     * Check if this category requires fiscal compliance
     */
    public function isFiscal(): bool
    {
        return $this !== self::NonFiscal;
    }

    /**
     * Check if this category requires hash chain
     */
    public function requiresHashChain(): bool
    {
        return match ($this) {
            self::TaxInvoice, self::CreditNote, self::FiscalReceipt, self::DeliveryNote => true,
            self::NonFiscal => false,
        };
    }

    /**
     * Get the category for a document type
     */
    public static function fromDocumentType(DocumentType $type): self
    {
        return match ($type) {
            DocumentType::Invoice => self::TaxInvoice,
            DocumentType::CreditNote => self::CreditNote,
            DocumentType::DeliveryNote => self::DeliveryNote,
            default => self::NonFiscal,
        };
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::NonFiscal => 'Non-Fiscal',
            self::FiscalReceipt => 'Fiscal Receipt',
            self::TaxInvoice => 'Tax Invoice',
            self::CreditNote => 'Credit Note',
            self::DeliveryNote => 'Delivery Note',
        };
    }
}
