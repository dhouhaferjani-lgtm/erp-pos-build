<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * ProfitLossData
 *
 * Data Transfer Object representing a complete Profit & Loss report.
 *
 * The Profit & Loss statement (also called Income Statement) shows a company's
 * financial performance over a period, displaying revenues, expenses, and
 * the resulting net income or loss.
 *
 * Properties:
 * - revenue: Collection of revenue account lines
 * - expenses: Collection of expense account lines
 * - total_revenue: Sum of all revenue
 * - total_expenses: Sum of all expenses
 * - net_income: Profit (revenue - expenses), negative if loss
 * - date_from: Start date of the period
 * - date_to: End date of the period
 *
 * Data Integrity:
 * - net_income = total_revenue - total_expenses
 * - All monetary values use 4 decimal precision
 * - Positive net_income = profit, negative = loss
 *
 * Design Notes:
 * - Extends Spatie\LaravelData\Data for automatic serialization to JSON
 * - Uses DataCollection for type-safe arrays of ProfitLossLineData
 * - Immutable (readonly properties)
 * - Can be directly returned from controllers (auto-serializes to JSON)
 *
 * Example JSON Output:
 * ```json
 * {
 *   "revenue": [
 *     {
 *       "account_code": "400",
 *       "account_name": "Sales Revenue",
 *       "account_type": "revenue",
 *       "amount": "50000.0000",
 *       "level": 0,
 *       "is_parent": false
 *     }
 *   ],
 *   "expenses": [
 *     {
 *       "account_code": "600",
 *       "account_name": "Rent Expense",
 *       "account_type": "expense",
 *       "amount": "5000.0000",
 *       "level": 0,
 *       "is_parent": false
 *     }
 *   ],
 *   "total_revenue": "50000.0000",
 *   "total_expenses": "5000.0000",
 *   "net_income": "45000.0000",
 *   "date_from": "2025-01-01",
 *   "date_to": "2025-12-31"
 * }
 * ```
 */
final class ProfitLossData extends Data
{
    /**
     * @param  DataCollection<int, ProfitLossLineData>  $revenue  Revenue account lines
     * @param  DataCollection<int, ProfitLossLineData>  $expenses  Expense account lines
     * @param  numeric-string  $total_revenue  Sum of all revenue
     * @param  numeric-string  $total_expenses  Sum of all expenses
     * @param  numeric-string  $net_income  Profit or loss (revenue - expenses)
     * @param  string  $date_from  Period start date (YYYY-MM-DD)
     * @param  string  $date_to  Period end date (YYYY-MM-DD)
     */
    public function __construct(
        #[DataCollectionOf(ProfitLossLineData::class)]
        public readonly DataCollection $revenue,
        #[DataCollectionOf(ProfitLossLineData::class)]
        public readonly DataCollection $expenses,
        public readonly string $total_revenue,
        public readonly string $total_expenses,
        public readonly string $net_income,
        public readonly string $date_from,
        public readonly string $date_to,
    ) {}

    /**
     * Create from service output array.
     *
     * Converts the array output from ProfitLossService into a typed DTO.
     *
     * @param array{
     *     revenue: list<array>,
     *     expenses: list<array>,
     *     total_revenue: numeric-string,
     *     total_expenses: numeric-string,
     *     net_income: numeric-string,
     *     date_from: string,
     *     date_to: string
     * } $data
     */
    public static function fromArray(array $data): self
    {
        // Convert arrays to DataCollections of DTOs
        $revenue = array_map(
            fn (array $line) => ProfitLossLineData::fromArray($line),
            $data['revenue']
        );

        $expenses = array_map(
            fn (array $line) => ProfitLossLineData::fromArray($line),
            $data['expenses']
        );

        return new self(
            revenue: new DataCollection(ProfitLossLineData::class, $revenue),
            expenses: new DataCollection(ProfitLossLineData::class, $expenses),
            total_revenue: $data['total_revenue'],
            total_expenses: $data['total_expenses'],
            net_income: $data['net_income'],
            date_from: $data['date_from'],
            date_to: $data['date_to'],
        );
    }

    /**
     * Check if the company is profitable (net income > 0).
     *
     * @return bool True if profitable, false if loss
     */
    public function isProfitable(): bool
    {
        return bccomp($this->net_income, '0.0000', 4) > 0;
    }

    /**
     * Check if the company has a loss (net income < 0).
     *
     * @return bool True if loss, false if profit or break-even
     */
    public function hasLoss(): bool
    {
        return bccomp($this->net_income, '0.0000', 4) < 0;
    }

    /**
     * Check if the company broke even (net income = 0).
     *
     * @return bool True if break-even
     */
    public function isBreakEven(): bool
    {
        return bccomp($this->net_income, '0.0000', 4) === 0;
    }

    /**
     * Get profit margin percentage (net income / total revenue * 100).
     *
     * Returns '0.0000' if total revenue is zero.
     *
     * @return numeric-string Profit margin as percentage
     */
    public function getProfitMargin(): string
    {
        if (bccomp($this->total_revenue, '0.0000', 4) === 0) {
            return '0.0000';
        }

        $margin = bcdiv($this->net_income, $this->total_revenue, 6);

        return bcmul($margin, '100', 4);
    }

    /**
     * Get the count of revenue accounts.
     */
    public function getRevenueAccountCount(): int
    {
        return $this->revenue->count();
    }

    /**
     * Get the count of expense accounts.
     */
    public function getExpenseAccountCount(): int
    {
        return $this->expenses->count();
    }

    /**
     * Check if the report has any data.
     */
    public function isEmpty(): bool
    {
        return $this->revenue->isEmpty() && $this->expenses->isEmpty();
    }
}
