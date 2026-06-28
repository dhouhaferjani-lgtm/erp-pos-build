<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Defense-in-depth: partial UNIQUE index on journal_entries (source_type, source_id)
 * scoped to the two procurement source types where exactly ONE journal entry per source
 * is the invariant:
 *
 *   supplier_invoice    — one GR-IR clearing entry per posted supplier invoice
 *   supplier_credit_note — one reversing entry per posted supplier credit note
 *
 * WHY PARTIAL (not global):
 *   A global UNIQUE on (source_type, source_id) is NOT safe because several other source
 *   types legitimately produce MULTIPLE journal entries per source:
 *
 *   • prepayment_application  – source_id = invoice_id; multiple partial advance-clearings
 *                               may be applied to the same invoice.
 *   • pos_receipt             – source_id = receipt_id; each payment leg (cash, card, …)
 *                               of a split-tender receipt produces its own entry.
 *   • payment                 – source_id is NULL for all entries (no unique risk, but rules
 *                               out a global index on (source_type, source_id NOT NULL)).
 *
 *   A partial index limited to the procurement types is safe: in the current codebase only
 *   one GL method writes to each:
 *     supplier_invoice      → GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry
 *     supplier_credit_note  → GeneralLedgerService::createSupplierCreditNoteEntry
 *   The legacy createSupplierInvoiceJournalEntry also uses source_type='supplier_invoice',
 *   but it is NOT called in the procurement posting flow; including supplier_invoice in the
 *   partial index acts as a structural guard against accidentally calling both paths for the
 *   same invoice.
 *
 * PostgreSQL supports partial (conditional) UNIQUE indexes natively (see:
 * https://www.postgresql.org/docs/current/indexes-partial.html). Laravel Schema builder
 * does not expose conditional index syntax, so the index is managed via raw DB::statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX uniq_je_source_procurement
                ON journal_entries (source_type, source_id)
                WHERE source_type IN ('supplier_invoice', 'supplier_credit_note')
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uniq_je_source_procurement');
    }
};
