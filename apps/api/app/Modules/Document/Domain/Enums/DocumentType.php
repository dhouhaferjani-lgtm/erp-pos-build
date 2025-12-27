<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

enum DocumentType: string
{
    case Quote = 'quote';
    case SalesOrder = 'sales_order';
    case PurchaseOrder = 'purchase_order';
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DeliveryNote = 'delivery_note';
    case ReturnNote = 'return_note';
    case Expense = 'expense';

    /**
     * Get the prefix for document numbering
     */
    public function getPrefix(): string
    {
        return match ($this) {
            self::Quote => 'QT',
            self::SalesOrder => 'SO',
            self::PurchaseOrder => 'PO',
            self::Invoice => 'INV',
            self::CreditNote => 'CN',
            self::DeliveryNote => 'DN',
            self::ReturnNote => 'RN',
            self::Expense => 'EXP',
        };
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::Quote => 'Quote',
            self::SalesOrder => 'Sales Order',
            self::PurchaseOrder => 'Purchase Order',
            self::Invoice => 'Invoice',
            self::CreditNote => 'Credit Note',
            self::DeliveryNote => 'Delivery Note',
            self::ReturnNote => 'Return Note',
            self::Expense => 'Expense',
        };
    }

    /**
     * Check if this document type affects accounts receivable
     */
    public function affectsReceivable(): bool
    {
        return match ($this) {
            self::Invoice => true,
            self::CreditNote => true,
            default => false,
        };
    }

    /**
     * Get the direction of receivable impact (+1 for increase, -1 for decrease, 0 for no impact)
     */
    public function receivableDirection(): int
    {
        return match ($this) {
            self::Invoice => 1,       // Increases AR
            self::CreditNote => -1,   // Decreases AR
            default => 0,
        };
    }
}
