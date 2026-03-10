<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * BalanceSheetData
 *
 * Data Transfer Object representing a complete Balance Sheet report.
 *
 * The Balance Sheet (also called Statement of Financial Position) shows a company's
 * financial position at a specific point in time, displaying assets, liabilities,
 * and equity, verifying the fundamental accounting equation: Assets = Liabilities + Equity.
 *
 * Properties:
 * - assets: Collection of asset account lines
 * - liabilities: Collection of liability account lines
 * - equity: Collection of equity account lines
 * - total_assets: Sum of all assets
 * - total_liabilities: Sum of all liabilities
 * - total_equity: Sum of all equity (including retained earnings)
 * - retained_earnings: Cumulative net income from inception
 * - is_balanced: Whether Assets = Liabilities + Equity
 * - as_of_date: The snapshot date
 *
 * Data Integrity:
 * - total_assets = total_liabilities + total_equity (when is_balanced = true)
 * - total_equity includes retained_earnings
 * - All monetary values use 4 decimal precision
 *
 * Design Notes:
 * - Extends Spatie\LaravelData\Data for automatic serialization to JSON
 * - Uses DataCollection for type-safe arrays of BalanceSheetLineData
 * - Immutable (readonly properties)
 * - Can be directly returned from controllers (auto-serializes to JSON)
 *
 * Example JSON Output:
 * ```json
 * {
 *   "assets": [
 *     {
 *       "account_code": "100",
 *       "account_name": "Cash",
 *       "account_type": "asset",
 *       "amount": "10000.0000",
 *       "level": 1,
 *       "is_parent": false
 *     }
 *   ],
 *   "liabilities": [
 *     {
 *       "account_code": "200",
 *       "account_name": "Accounts Payable",
 *       "account_type": "liability",
 *       "amount": "8000.0000",
 *       "level": 1,
 *       "is_parent": false
 *     }
 *   ],
 *   "equity": [
 *     {
 *       "account_code": "300",
 *       "account_name": "Capital Stock",
 *       "account_type": "equity",
 *       "amount": "50000.0000",
 *       "level": 1,
 *       "is_parent": false
 *     }
 *   ],
 *   "total_assets": "75000.0000",
 *   "total_liabilities": "8000.0000",
 *   "total_equity": "67000.0000",
 *   "retained_earnings": "17000.0000",
 *   "is_balanced": true,
 *   "as_of_date": "2025-12-31"
 * }
 * ```
 */
final class BalanceSheetData extends Data
{
    /**
     * @param  DataCollection<int, BalanceSheetLineData>  $assets  Asset account lines
     * @param  DataCollection<int, BalanceSheetLineData>  $liabilities  Liability account lines
     * @param  DataCollection<int, BalanceSheetLineData>  $equity  Equity account lines
     * @param  numeric-string  $total_assets  Sum of all asset balances
     * @param  numeric-string  $total_liabilities  Sum of all liability balances
     * @param  numeric-string  $total_equity  Sum of all equity balances (including retained earnings)
     * @param  numeric-string  $retained_earnings  Cumulative net income from inception
     * @param  bool  $is_balanced  Whether the accounting equation holds (Assets = Liabilities + Equity)
     * @param  string  $as_of_date  Snapshot date (YYYY-MM-DD)
     */
    public function __construct(
        #[DataCollectionOf(BalanceSheetLineData::class)]
        public readonly DataCollection $assets,
        #[DataCollectionOf(BalanceSheetLineData::class)]
        public readonly DataCollection $liabilities,
        #[DataCollectionOf(BalanceSheetLineData::class)]
        public readonly DataCollection $equity,
        public readonly string $total_assets,
        public readonly string $total_liabilities,
        public readonly string $total_equity,
        public readonly string $retained_earnings,
        public readonly bool $is_balanced,
        public readonly string $as_of_date,
    ) {}

    /**
     * Create from service output array.
     *
     * Converts the array output from BalanceSheetService into a typed DTO.
     *
     * @param array{
     *     assets: list<array{account_code: string, account_name: string, account_type: string, amount: string, level: int, is_parent: bool}>,
     *     liabilities: list<array{account_code: string, account_name: string, account_type: string, amount: string, level: int, is_parent: bool}>,
     *     equity: list<array{account_code: string, account_name: string, account_type: string, amount: string, level: int, is_parent: bool}>,
     *     total_assets: numeric-string,
     *     total_liabilities: numeric-string,
     *     total_equity: numeric-string,
     *     retained_earnings: numeric-string,
     *     is_balanced: bool,
     *     as_of_date: string
     * } $data
     */
    public static function fromArray(array $data): self
    {
        // Convert arrays to DataCollections of DTOs
        $assets = array_map(
            fn (array $line) => BalanceSheetLineData::fromArray($line),
            $data['assets']
        );

        $liabilities = array_map(
            fn (array $line) => BalanceSheetLineData::fromArray($line),
            $data['liabilities']
        );

        $equity = array_map(
            fn (array $line) => BalanceSheetLineData::fromArray($line),
            $data['equity']
        );

        return new self(
            assets: new DataCollection(BalanceSheetLineData::class, $assets),
            liabilities: new DataCollection(BalanceSheetLineData::class, $liabilities),
            equity: new DataCollection(BalanceSheetLineData::class, $equity),
            total_assets: $data['total_assets'],
            total_liabilities: $data['total_liabilities'],
            total_equity: $data['total_equity'],
            retained_earnings: $data['retained_earnings'],
            is_balanced: $data['is_balanced'],
            as_of_date: $data['as_of_date'],
        );
    }

    /**
     * Get the difference between assets and liabilities+equity.
     *
     * Should be '0.0000' when balanced.
     *
     * @return numeric-string Difference amount
     */
    public function getBalanceDifference(): string
    {
        $liabilitiesAndEquity = bcadd($this->total_liabilities, $this->total_equity, 4);

        return bcsub($this->total_assets, $liabilitiesAndEquity, 4);
    }

    /**
     * Get the count of asset accounts.
     */
    public function getAssetAccountCount(): int
    {
        return $this->assets->count();
    }

    /**
     * Get the count of liability accounts.
     */
    public function getLiabilityAccountCount(): int
    {
        return $this->liabilities->count();
    }

    /**
     * Get the count of equity accounts.
     */
    public function getEquityAccountCount(): int
    {
        return $this->equity->count();
    }

    /**
     * Check if the balance sheet has any data.
     */
    public function isEmpty(): bool
    {
        return $this->assets->count() === 0
            && $this->liabilities->count() === 0
            && $this->equity->count() === 0;
    }

    /**
     * Get the debt-to-equity ratio.
     *
     * Returns '0.0000' if total equity is zero.
     *
     * @return numeric-string Debt-to-equity ratio
     */
    public function getDebtToEquityRatio(): string
    {
        if (bccomp($this->total_equity, '0.0000', 4) === 0) {
            return '0.0000';
        }

        return bcdiv($this->total_liabilities, $this->total_equity, 4);
    }

    /**
     * Get the current ratio (assets / liabilities).
     *
     * Measures short-term liquidity.
     * Returns '0.0000' if total liabilities is zero.
     *
     * Note: This is a simplified calculation using all assets and liabilities.
     * For accurate current ratio, you should filter by current assets/liabilities only.
     *
     * @return numeric-string Current ratio
     */
    public function getCurrentRatio(): string
    {
        if (bccomp($this->total_liabilities, '0.0000', 4) === 0) {
            return '0.0000';
        }

        return bcdiv($this->total_assets, $this->total_liabilities, 4);
    }

    /**
     * Get the equity percentage of total assets.
     *
     * Measures financial independence.
     * Returns '0.0000' if total assets is zero.
     *
     * @return numeric-string Equity percentage (0-100)
     */
    public function getEquityPercentage(): string
    {
        if (bccomp($this->total_assets, '0.0000', 4) === 0) {
            return '0.0000';
        }

        $ratio = bcdiv($this->total_equity, $this->total_assets, 6);

        return bcmul($ratio, '100', 4);
    }
}
