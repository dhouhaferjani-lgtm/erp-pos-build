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
    case SupplierInvoice = 'supplier_invoice';
    case SupplierCreditNote = 'supplier_credit_note';
    case Income = 'income';

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
            self::SupplierInvoice => 'SI',
            self::SupplierCreditNote => 'SCN',
            self::Income => 'INC',
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
            self::SupplierInvoice => 'Supplier Invoice',
            self::SupplierCreditNote => 'Supplier Credit Note',
            self::Income => 'Income',
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

    /**
     * Whether this document type should transition to Paid status when fully paid.
     * Sales orders / purchase orders retain their workflow status (confirmed)
     * because they still need to go through conversion (to invoice, delivery note, etc.).
     */
    public function canTransitionToPaid(): bool
    {
        return match ($this) {
            self::Invoice, self::CreditNote, self::SupplierInvoice => true,
            default => false,
        };
    }
}
