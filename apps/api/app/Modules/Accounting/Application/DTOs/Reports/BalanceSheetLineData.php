<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;

/**
 * BalanceSheetLineData
 *
 * Data Transfer Object representing a single line in a Balance Sheet report.
 *
 * Each line represents one account (asset, liability, or equity) with its balance
 * as of the reporting date, along with hierarchical metadata.
 *
 * Design Notes:
 * - Extends Spatie\LaravelData\Data for automatic serialization
 * - Immutable (readonly properties)
 * - Represents display format, not domain model
 * - Amount is the account's balance (debit - credit for assets, credit - debit for liabilities/equity)
 *
 * Data Integrity:
 * - All monetary values are numeric-strings (precision-safe)
 * - Asset accounts: amount = debit - credit
 * - Liability/Equity accounts: amount = credit - debit
 * - Level indicates hierarchy depth (0 = root)
 * - is_parent flag indicates subtotal rows
 *
 * Example Usage:
 * ```php
 * $line = BalanceSheetLineData::from([
 *     'account_code' => '100',
 *     'account_name' => 'Cash',
 *     'account_type' => 'asset',
 *     'amount' => '10000.0000',
 *     'level' => 1,
 *     'is_parent' => false,
 * ]);
 * ```
 */
final class BalanceSheetLineData extends Data
{
    /**
     * @param  string  $account_code  Account code (e.g., "100", "200", "300")
     * @param  string  $account_name  Account name (e.g., "Cash", "Accounts Payable", "Capital Stock")
     * @param  string  $account_type  Account type ('asset', 'liability', or 'equity')
     * @param  string  $amount  Balance as of the reporting date
     * @param  int  $level  Hierarchy depth (0 = root, 1 = child, etc.)
     * @param  bool  $is_parent  Whether this account has children (subtotal row)
     */
    public function __construct(
        public readonly string $account_code,
        public readonly string $account_name,
        public readonly string $account_type,
        public readonly string $amount,
        public readonly int $level,
        public readonly bool $is_parent,
    ) {}

    /**
     * Create from service output array.
     *
     * Converts the array output from BalanceSheetService into a typed DTO.
     *
     * @param array{
     *     account_code: string,
     *     account_name: string,
     *     account_type: string,
     *     amount: string,
     *     level: int,
     *     is_parent: bool
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            account_code: $data['account_code'],
            account_name: $data['account_name'],
            account_type: $data['account_type'],
            amount: $data['amount'],
            level: $data['level'],
            is_parent: $data['is_parent'],
        );
    }

    /**
     * Check if this is a leaf account (not a parent).
     */
    public function isLeaf(): bool
    {
        return ! $this->is_parent;
    }

    /**
     * Get the absolute value of the amount.
     *
     * @return numeric-string
     */
    public function getAbsoluteAmount(): string
    {
        /** @var numeric-string */
        return ltrim($this->amount, '-');
    }

    /**
     * Check if this is an asset account.
     */
    public function isAsset(): bool
    {
        return $this->account_type === 'asset';
    }

    /**
     * Check if this is a liability account.
     */
    public function isLiability(): bool
    {
        return $this->account_type === 'liability';
    }

    /**
     * Check if this is an equity account.
     */
    public function isEquity(): bool
    {
        return $this->account_type === 'equity';
    }
}
