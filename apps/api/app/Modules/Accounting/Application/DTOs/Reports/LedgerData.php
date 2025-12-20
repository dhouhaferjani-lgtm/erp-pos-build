<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * LedgerData
 *
 * Data Transfer Object representing a complete General Ledger report.
 *
 * The General Ledger is a chronological record of all transactions for
 * one or more accounts, showing running balances after each transaction.
 *
 * Properties:
 * - lines: Collection of ledger lines (individual transactions)
 * - opening_balance: Balance before the date range
 * - closing_balance: Balance after all transactions in range
 * - total_debits: Sum of all debits in the period
 * - total_credits: Sum of all credits in the period
 * - date_from: Start date of report (null = from beginning)
 * - date_to: End date of report
 * - account_filter: Account ID filter (null = all accounts)
 * - partner_filter: Partner ID filter (null = all partners)
 *
 * Data Integrity:
 * - closing_balance = opening_balance + total_debits - total_credits
 * - All monetary values use 4 decimal precision
 * - Chronological ordering is guaranteed by service
 *
 * Design Notes:
 * - Extends Spatie\LaravelData\Data for automatic serialization to JSON
 * - Uses DataCollection for type-safe array of LedgerLineData
 * - Immutable (readonly properties)
 * - Can be directly returned from controllers (auto-serializes to JSON)
 *
 * Example JSON Output:
 * ```json
 * {
 *   "opening_balance": "1000.0000",
 *   "closing_balance": "1500.0000",
 *   "total_debits": "800.0000",
 *   "total_credits": "300.0000",
 *   "lines": [
 *     {
 *       "id": "uuid",
 *       "date": "2025-12-19",
 *       "entry_number": "JE-2025-001",
 *       "description": "Cash sale",
 *       "account_code": "100",
 *       "account_name": "Cash",
 *       "partner_name": "John Doe",
 *       "debit": "500.0000",
 *       "credit": "0.0000",
 *       "balance": "1500.0000",
 *       "source_type": "invoice",
 *       "source_id": "inv-uuid"
 *     }
 *   ],
 *   "date_from": "2025-01-01",
 *   "date_to": "2025-12-19",
 *   "account_filter": "account-uuid",
 *   "partner_filter": null
 * }
 * ```
 *
 * @package App\Modules\Accounting\Application\DTOs\Reports
 */
final class LedgerData extends Data
{
    /**
     * @param numeric-string $opening_balance Balance before date_from
     * @param numeric-string $closing_balance Balance after all transactions
     * @param numeric-string $total_debits Sum of all debits in period
     * @param numeric-string $total_credits Sum of all credits in period
     * @param DataCollection<int, LedgerLineData> $lines Ledger transaction lines
     * @param string|null $date_from Report start date (YYYY-MM-DD) or null for all history
     * @param string $date_to Report end date (YYYY-MM-DD)
     * @param string|null $account_filter Account UUID filter or null for all accounts
     * @param string|null $partner_filter Partner UUID filter or null for all partners
     */
    public function __construct(
        public readonly string $opening_balance,
        public readonly string $closing_balance,
        public readonly string $total_debits,
        public readonly string $total_credits,
        #[DataCollectionOf(LedgerLineData::class)]
        public readonly DataCollection $lines,
        public readonly ?string $date_from,
        public readonly string $date_to,
        public readonly ?string $account_filter,
        public readonly ?string $partner_filter,
    ) {}

    /**
     * Create from service output array.
     *
     * Converts the array output from GeneralLedgerReportService into a typed DTO.
     *
     * @param array{
     *     opening_balance: numeric-string,
     *     closing_balance: numeric-string,
     *     total_debits: numeric-string,
     *     total_credits: numeric-string,
     *     lines: list<array>,
     *     date_from: string|null,
     *     date_to: string,
     *     account_filter: string|null,
     *     partner_filter: string|null
     * } $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        // Convert array of lines to DataCollection of DTOs
        $lines = array_map(
            fn (array $line) => LedgerLineData::fromArray($line),
            $data['lines']
        );

        return new self(
            opening_balance: $data['opening_balance'],
            closing_balance: $data['closing_balance'],
            total_debits: $data['total_debits'],
            total_credits: $data['total_credits'],
            lines: new DataCollection(LedgerLineData::class, $lines),
            date_from: $data['date_from'],
            date_to: $data['date_to'],
            account_filter: $data['account_filter'] ?? null,
            partner_filter: $data['partner_filter'] ?? null,
        );
    }

    /**
     * Convert to array with proper DataCollection serialization.
     *
     * Override to ensure lines DataCollection serializes as JSON array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'opening_balance' => $this->opening_balance,
            'closing_balance' => $this->closing_balance,
            'total_debits' => $this->total_debits,
            'total_credits' => $this->total_credits,
            'lines' => $this->lines->toArray(),
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'account_filter' => $this->account_filter,
            'partner_filter' => $this->partner_filter,
        ];
    }

    /**
     * Get the count of transactions in the ledger.
     *
     * @return int
     */
    public function getTransactionCount(): int
    {
        return $this->lines->count();
    }

    /**
     * Check if the ledger has any transactions.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->lines->isEmpty();
    }

    /**
     * Get the net change in the account balance during the period.
     *
     * Returns positive for net debit, negative for net credit.
     *
     * @return numeric-string
     */
    public function getNetChange(): string
    {
        return bcsub($this->closing_balance, $this->opening_balance, 4);
    }

    /**
     * Verify accounting integrity (closing balance matches calculation).
     *
     * Validates: closing_balance = opening_balance + total_debits - total_credits
     *
     * @return bool True if balances are consistent
     */
    public function isBalanced(): bool
    {
        $calculated = $this->opening_balance;
        $calculated = bcadd($calculated, $this->total_debits, 4);
        $calculated = bcsub($calculated, $this->total_credits, 4);

        // Allow 0.0001 threshold for rounding
        return bccomp($calculated, $this->closing_balance, 4) === 0;
    }
}
