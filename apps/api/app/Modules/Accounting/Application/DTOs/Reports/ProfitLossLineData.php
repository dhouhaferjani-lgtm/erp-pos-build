<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;

/**
 * ProfitLossLineData
 *
 * Data Transfer Object representing a single line in a Profit & Loss report.
 *
 * Each line represents one account (revenue or expense) with its amount
 * for the reporting period, along with hierarchical metadata.
 *
 * Design Notes:
 * - Extends Spatie\LaravelData\Data for automatic serialization
 * - Immutable (readonly properties)
 * - Represents display format, not domain model
 * - Amount is always positive (expenses are not negative)
 *
 * Data Integrity:
 * - All monetary values are numeric-strings (precision-safe)
 * - Revenue accounts: amount = credit - debit
 * - Expense accounts: amount = debit - credit
 * - Level indicates hierarchy depth (0 = root)
 * - is_parent flag indicates subtotal rows
 *
 * Example Usage:
 * ```php
 * $line = ProfitLossLineData::from([
 *     'account_code' => '400',
 *     'account_name' => 'Sales Revenue',
 *     'account_type' => 'revenue',
 *     'amount' => '50000.0000',
 *     'level' => 1,
 *     'is_parent' => false,
 * ]);
 * ```
 */
final class ProfitLossLineData extends Data
{
    /**
     * @param  string  $account_code  Account code (e.g., "400", "600")
     * @param  string  $account_name  Account name (e.g., "Sales Revenue", "Rent Expense")
     * @param  string  $account_type  Account type ('revenue' or 'expense')
     * @param  numeric-string  $amount  Amount for the period (always positive)
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
     * Converts the array output from ProfitLossService into a typed DTO.
     *
     * @param array{
     *     account_code: string,
     *     account_name: string,
     *     account_type: string,
     *     amount: numeric-string,
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
        return ltrim($this->amount, '-');
    }
}
