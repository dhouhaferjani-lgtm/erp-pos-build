<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * TrialBalanceData
 *
 * Data Transfer Object representing a complete Trial Balance report.
 *
 * The Trial Balance is a fundamental accounting report that lists all
 * accounts with their debit and credit balances. It verifies that the
 * general ledger is in balance (total debits = total credits).
 *
 * Properties:
 * - lines: Collection of account lines with balances
 * - total_debit: Sum of all debit balances
 * - total_credit: Sum of all credit balances
 * - is_balanced: Whether debits equal credits (accounting integrity check)
 * - as_of_date: Point-in-time date for the report
 *
 * Data Integrity:
 * - is_balanced MUST be true for a valid trial balance
 * - If false, indicates data corruption or incomplete transactions
 * - total_debit and total_credit should match to the penny
 *
 * Design Notes:
 * - Extends Spatie\LaravelData\Data for automatic serialization to JSON
 * - Uses DataCollection for type-safe array of TrialBalanceLineData
 * - Immutable (readonly properties)
 * - Can be directly returned from controllers (auto-serializes to JSON)
 *
 * Example JSON Output:
 * ```json
 * {
 *   "lines": [
 *     {"account_code": "100", "account_name": "Assets", "debit": "10000.00", "credit": "0.00", "level": 0, "is_parent": true},
 *     {"account_code": "110", "account_name": "Cash", "debit": "5000.00", "credit": "0.00", "level": 1, "is_parent": false}
 *   ],
 *   "total_debit": "10000.00",
 *   "total_credit": "10000.00",
 *   "is_balanced": true,
 *   "as_of_date": "2025-12-31"
 * }
 * ```
 */
final class TrialBalanceData extends Data
{
    /**
     * @param  DataCollection<int, TrialBalanceLineData>  $lines  Trial balance lines
     * @param  numeric-string  $total_debit  Sum of all debits
     * @param  numeric-string  $total_credit  Sum of all credits
     * @param  bool  $is_balanced  Whether total_debit == total_credit (within threshold)
     * @param  string  $as_of_date  Report date in ISO 8601 format (YYYY-MM-DD)
     */
    public function __construct(
        #[DataCollectionOf(TrialBalanceLineData::class)]
        public readonly DataCollection $lines,
        public readonly string $total_debit,
        public readonly string $total_credit,
        public readonly bool $is_balanced,
        public readonly string $as_of_date,
    ) {}

    /**
     * Create from service output array.
     *
     * Converts the array output from TrialBalanceService into a typed DTO.
     *
     * @param  array{lines: list<array>, total_debit: numeric-string, total_credit: numeric-string, is_balanced: bool, as_of_date: string}  $data
     */
    public static function fromArray(array $data): self
    {
        // Convert array of lines to DataCollection of DTOs
        $lines = array_map(
            fn (array $line) => TrialBalanceLineData::fromArray($line),
            $data['lines']
        );

        return new self(
            lines: new DataCollection(TrialBalanceLineData::class, $lines),
            total_debit: $data['total_debit'],
            total_credit: $data['total_credit'],
            is_balanced: $data['is_balanced'],
            as_of_date: $data['as_of_date'],
        );
    }

    /**
     * Get the count of accounts in the trial balance.
     */
    public function getAccountCount(): int
    {
        return $this->lines->count();
    }

    /**
     * Check if the report has any data.
     */
    public function isEmpty(): bool
    {
        return $this->lines->count() === 0;
    }

    /**
     * Get the difference between total debits and credits.
     *
     * Returns '0.00' if perfectly balanced.
     * Non-zero value indicates accounting error.
     *
     * @return numeric-string
     */
    public function getBalanceDifference(): string
    {
        return bcsub($this->total_debit, $this->total_credit, 4);
    }
}
