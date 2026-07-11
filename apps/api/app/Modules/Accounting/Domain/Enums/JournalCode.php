<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

/**
 * FEC-readiness journal code (French Fichier des Écritures Comptables export
 * requires a "code journal" per entry). This is the ONLY source of truth for
 * the journal_entries.journal_code column — never a magic string.
 *
 * Every GeneralLedgerService::JournalEntry::create() call site stamps this
 * from the entry's source_type via {@see self::fromSourceType()} (Treasury
 * spine Task 9).
 */
enum JournalCode: string
{
    case Sales = 'VT';
    case Purchase = 'AC';
    case Bank = 'BQ';
    case Cash = 'CA';
    case Misc = 'OD';

    /**
     * Map a journal_entries.source_type literal to its FEC journal code.
     *
     * Any source_type not explicitly listed here falls through to Misc (OD) —
     * this is the deliberate default for every non-sales/purchase/bank/cash
     * GL flow (advances, tolerances, COGS, GR-IR, vouchers, write-offs, etc.).
     */
    public static function fromSourceType(string $sourceType): self
    {
        return match ($sourceType) {
            'invoice', 'credit_note' => self::Sales,
            'supplier_invoice' => self::Purchase,
            'payment', 'customer_payment', 'supplier_payment', 'customer_payment_refund' => self::Bank,
            'pos_payment', 'pos_receipt', 'pos_receipt_refund' => self::Cash,
            default => self::Misc,
        };
    }
}
