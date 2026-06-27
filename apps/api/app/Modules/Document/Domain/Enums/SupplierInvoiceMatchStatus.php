<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

/**
 * 3-way matching status for supplier invoices.
 *
 * Lives in the Document module (not Procurement) to keep the dependency
 * direction sane: Procurement imports Document, not the reverse. The
 * match_status column lives on the Document model, which casts to this enum;
 * the C2 matcher (Procurement module) will import this enum from here.
 */
enum SupplierInvoiceMatchStatus: string
{
    case Unmatched = 'unmatched';
    case Matched = 'matched';
    case PriceVariance = 'price_variance';
    case QuantityVariance = 'quantity_variance';
    case Exception = 'exception';
}
