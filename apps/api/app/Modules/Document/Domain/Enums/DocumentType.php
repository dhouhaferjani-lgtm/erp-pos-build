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
    case PurchaseQuoteRequest = 'purchase_rfq';

    /**
     * R2-F4 — a correcting accounting entry, expressed as a DOCUMENT.
     *
     * Owner ruling c4 (branch (a), strengthened) in
     * `docs/superpowers/tickets/2026-08-07-round2-rulings-record.md`:
     * "corrections are DOCUMENTS, always. A correction requires creating a
     * correcting document LINKED to the original document it refers to
     * (`source_document_id`). No free-floating manual JEs as the correction
     * mechanism."
     *
     * It carries no product lines and no partner balance — its content is a set
     * of GL legs held in `documents.payload`, shaped by
     * `App\Modules\Document\Application\DTOs\CorrectingEntryPayload` (named in
     * prose, NOT imported: Domain may not depend on Application — deptrac
     * `ModuleDomain` allows only `SharedDomain` + `SharedContracts`) — and its
     * mandatory `source_document_id` names the document whose sealed journal
     * entry it repairs.
     *
     * DELIBERATELY NOT a fiscal (hash-chained) document type: it never reaches a
     * customer, has no fiscal number sequence obligation, and its integrity is
     * carried by the JOURNAL-entry hash chain (which its GL posting joins like
     * every other entry), not by the document chain. It therefore stays
     * `FiscalCategory::NonFiscal` — which is also what keeps this lane
     * zero-schema, since `chk_fiscal_category_enum` would otherwise need
     * widening.
     */
    case CorrectingEntry = 'correcting_entry';

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
            self::PurchaseQuoteRequest => 'DP',
            self::CorrectingEntry => 'CE',
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
            self::PurchaseQuoteRequest => 'Purchase Quote Request',
            self::CorrectingEntry => 'Correcting Entry',
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
     * Whether SETTLING this document type is a supplier-side (AP) act — i.e.
     * the payment it takes part in is money the company owes to, or has
     * advanced to, a SUPPLIER rather than money a customer owes the company.
     *
     * F-W2-14 residual (a). Deliberately broader than
     * {@see canTransitionToPaid()}: a purchase order and a purchase RFQ never
     * become Paid, but a payment allocated to one is still an outbound supplier
     * settlement and must carry `payments.pay-supplier`. A supplier CREDIT note
     * moves money the other way (the supplier refunds us) and is listed for the
     * same reason — the counterparty is a supplier, which is what the permission
     * is about.
     *
     * The AR twin is {@see affectsReceivable()}; the two are not complements —
     * delivery notes, return notes and expenses are neither.
     */
    public function isSupplierSettlement(): bool
    {
        return match ($this) {
            self::SupplierInvoice,
            self::SupplierCreditNote,
            self::PurchaseOrder,
            self::PurchaseQuoteRequest => true,
            default => false,
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
