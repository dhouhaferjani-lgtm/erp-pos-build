<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

final readonly class StatementColumnMap
{
    public function __construct(
        public string $valueDate,
        public ?string $bookingDate,
        public ?string $amount,
        public ?string $debit,
        public ?string $credit,
        public ?string $reference,
        public ?string $bankTransactionId,
        public string $label,
        public ?string $counterpartyHint,
        public ?string $openingBalance,
        public ?string $closingBalance,
    ) {}

    /** @param array<string, string|null> $map */
    public static function fromArray(array $map): self
    {
        return new self(
            valueDate: self::required($map, 'value_date'),
            bookingDate: self::optional($map, 'booking_date'),
            amount: self::optional($map, 'amount'),
            debit: self::optional($map, 'debit'),
            credit: self::optional($map, 'credit'),
            reference: self::optional($map, 'reference'),
            bankTransactionId: self::optional($map, 'bank_transaction_id'),
            label: self::required($map, 'label'),
            counterpartyHint: self::optional($map, 'counterparty_hint'),
            openingBalance: self::optional($map, 'opening_balance'),
            closingBalance: self::optional($map, 'closing_balance'),
        );
    }

    /** @param array<string, string|null> $map */
    private static function required(array $map, string $key): string
    {
        $value = self::optional($map, $key);
        if ($value === null) {
            throw new \InvalidArgumentException("Statement column mapping '{$key}' is required.");
        }

        return $value;
    }

    /** @param array<string, string|null> $map */
    private static function optional(array $map, string $key): ?string
    {
        $value = $map[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
