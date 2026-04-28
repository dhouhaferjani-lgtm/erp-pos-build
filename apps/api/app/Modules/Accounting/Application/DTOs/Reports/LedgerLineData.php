<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * LedgerLineData
 *
 * Data Transfer Object representing a single line in a General Ledger report.
 *
 * Each line represents one journal line (transaction) with its running balance.
 * This is different from TrialBalanceLineData which shows aggregated account balances.
 *
 * The General Ledger shows chronological transaction detail, making it suitable for:
 * - Account reconciliation
 * - Audit trails
 * - Transaction history
 * - Customer/supplier statements
 *
 * Design Notes:
 * - Extends Spatie\LaravelData\Data for automatic serialization
 * - Immutable (readonly properties)
 * - Represents display format, not domain model
 * - Balance is a running cumulative total (not just this transaction)
 *
 * Data Integrity:
 * - All monetary values are numeric-strings (precision-safe)
 * - Debit and credit are mutually exclusive in most cases
 * - Balance reflects cumulative effect of all prior transactions
 * - Source tracking enables drill-down to originating documents
 *
 * Example Usage:
 * ```php
 * $line = LedgerLineData::from([
 *     'id' => 'uuid',
 *     'date' => '2025-12-19',
 *     'entry_number' => 'JE-2025-001',
 *     'description' => 'Cash sale',
 *     'account_code' => '100',
 *     'account_name' => 'Cash',
 *     'partner_name' => 'John Doe',
 *     'debit' => '1000.0000',
 *     'credit' => '0.0000',
 *     'balance' => '5000.0000',
 *     'source_type' => 'invoice',
 *     'source_id' => 'inv-uuid',
 * ]);
 * ```
 */
#[TypeScript]
final class LedgerLineData extends Data
{
    /**
     * @param  string  $id  Journal line UUID
     * @param  string  $date  Transaction date (YYYY-MM-DD format)
     * @param  string  $entry_number  Journal entry number (e.g., "JE-2025-001")
     * @param  string  $description  Line or entry description
     * @param  string  $account_code  Account code (e.g., "100", "411")
     * @param  string  $account_name  Account name (e.g., "Cash", "Revenue")
     * @param  string|null  $partner_name  Partner name (customer/supplier) if applicable
     * @param  numeric-string  $debit  Debit amount (0.0000 if credit transaction)
     * @param  numeric-string  $credit  Credit amount (0.0000 if debit transaction)
     * @param  numeric-string  $balance  Running balance after this transaction
     * @param  string|null  $source_type  Source document type (e.g., "invoice", "payment")
     * @param  string|null  $source_id  Source document UUID
     */
    public function __construct(
        public readonly string $id,
        public readonly string $date,
        public readonly string $entry_number,
        public readonly string $description,
        public readonly string $account_code,
        public readonly string $account_name,
        public readonly ?string $partner_name,
        public readonly string $debit,
        public readonly string $credit,
        public readonly string $balance,
        public readonly ?string $source_type,
        public readonly ?string $source_id,
    ) {}

    /**
     * Create from service output array.
     *
     * Converts the array output from GeneralLedgerReportService into a typed DTO.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            date: $data['date'],
            entry_number: $data['entry_number'],
            description: $data['description'],
            account_code: $data['account_code'],
            account_name: $data['account_name'],
            partner_name: $data['partner_name'] ?? null,
            debit: $data['debit'],
            credit: $data['credit'],
            balance: $data['balance'],
            source_type: $data['source_type'] ?? null,
            source_id: $data['source_id'] ?? null,
        );
    }

    /**
     * Check if this is a debit transaction.
     */
    public function isDebit(): bool
    {
        return bccomp($this->debit, '0.0000', 4) > 0;
    }

    /**
     * Check if this is a credit transaction.
     */
    public function isCredit(): bool
    {
        return bccomp($this->credit, '0.0000', 4) > 0;
    }

    /**
     * Get the net amount of this transaction (debit - credit).
     *
     * Positive values indicate debit, negative indicate credit.
     *
     * @return numeric-string
     */
    public function getNetAmount(): string
    {
        return bcsub($this->debit, $this->credit, 4);
    }
}
