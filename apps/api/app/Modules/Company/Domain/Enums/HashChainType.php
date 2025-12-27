<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Enums;

/**
 * Types of hash chains for fiscal compliance.
 *
 * Each chain type maintains its own sequence and hash chain
 * for regulatory compliance (NF525, e-invoicing, etc.).
 */
enum HashChainType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DeliveryNote = 'delivery_note';
    case ReturnNote = 'return_note';
    case Receipt = 'receipt';
    case Payment = 'payment';
    case JournalEntry = 'journal_entry';
    case ZReport = 'z_report';
}
