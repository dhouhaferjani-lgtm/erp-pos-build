<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\DTOs;

/**
 * Outcome of recording a back-office customer-account deposit — the data the
 * POST endpoint returns: the sealed receipt identity, how the money landed
 * (settled against open invoices vs. credited as advance), and the refreshed
 * partner balances. All monetary values are numeric-strings at the currency
 * scale.
 */
final readonly class RecordCustomerDepositResult
{
    public function __construct(
        public string $fiscalEventId,
        public string $depositReceiptUuid,
        public string $amount,
        public string $currencyCode,
        public string $settledAmount,
        public string $creditedAmount,
        public string $receivableBalance,
        public string $creditBalance,
        public string $netBalance,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'fiscal_event_id' => $this->fiscalEventId,
            'deposit_receipt_uuid' => $this->depositReceiptUuid,
            'amount' => $this->amount,
            'currency_code' => $this->currencyCode,
            'settled_amount' => $this->settledAmount,
            'credited_amount' => $this->creditedAmount,
            'receivable_balance' => $this->receivableBalance,
            'credit_balance' => $this->creditBalance,
            'net_balance' => $this->netBalance,
        ];
    }
}
