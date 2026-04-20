<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * TrialBalanceLineData
 *
 * Data Transfer Object representing a single line in a Trial Balance report.
 *
 * Each line represents one account with its debit/credit balance and
 * hierarchical metadata (level, parent status).
 *
 * Design Notes:
 * - Extends Spatie\LaravelData\Data for automatic serialization
 * - Immutable (readonly properties)
 * - Represents display format, not domain model
 * - Debit and credit are mutually exclusive (one will be '0.00')
 *
 * Data Integrity:
 * - All monetary values are numeric-strings (precision-safe)
 * - Level indicates hierarchy depth (0 = root)
 * - is_parent flag indicates subtotal rows
 */
#[TypeScript]
final class TrialBalanceLineData extends Data
{
    /**
     * @param  string  $account_code  Account code (e.g., "100", "411")
     * @param  string  $account_name  Account name (e.g., "Cash", "Accounts Receivable")
     * @param  string  $account_type  Account type enum value ('asset', 'liability', 'revenue', 'expense', 'equity')
     * @param  numeric-string  $debit  Debit balance (0.00 if credit balance)
     * @param  numeric-string  $credit  Credit balance (0.00 if debit balance)
     * @param  int  $level  Hierarchy depth (0 = root, 1 = child, 2 = grandchild, etc.)
     * @param  bool  $is_parent  Whether this account has children (subtotal row)
     */
    public function __construct(
        public readonly string $account_code,
        public readonly string $account_name,
        public readonly string $account_type,
        public readonly string $debit,
        public readonly string $credit,
        public readonly int $level,
        public readonly bool $is_parent,
    ) {}

    /**
     * Create from array (typically from TrialBalanceService output).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            account_code: $data['account_code'],
            account_name: $data['account_name'],
            account_type: $data['account_type'],
            debit: $data['debit'],
            credit: $data['credit'],
            level: $data['level'],
            is_parent: $data['is_parent'],
        );
    }
}
