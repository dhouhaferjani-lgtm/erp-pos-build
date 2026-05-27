<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

final readonly class AccountPaymentPayload
{
    /**
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $localBalanceSnapshot
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>|null  $references
     * @param  array<string, mixed>|null  $regimeExtensions
     * @param  array<string, mixed>  $seller
     * @param  array<string, mixed>  $staleness
     * @param  array<string, mixed>|null  $sourcePayload
     */
    public function __construct(
        public string $accountPaymentUuid,
        public string $businessDate,
        public string $cashierId,
        public string $cashierName,
        public string $currencyCode,
        public int $currencyScale,
        public array $customer,
        public string $eventTimeDevice,
        public array $localBalanceSnapshot,
        public ?string $notes,
        public array $payment,
        public string $receiptTypeCode,
        public ?array $references,
        public ?array $regimeExtensions,
        public array $seller,
        public string $shiftId,
        public array $staleness,
        public string $terminalId,
        public bool $trainingFlag,
        public string $treasuryAllocationPolicy,
        private ?array $sourcePayload = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $customer = FiscalPayloadArrayGuards::requireArray($data, 'customer');
        $localBalanceSnapshot = FiscalPayloadArrayGuards::requireArray($data, 'local_balance_snapshot');
        $payment = FiscalPayloadArrayGuards::requireArray($data, 'payment');
        $seller = FiscalPayloadArrayGuards::requireArray($data, 'seller');
        $staleness = FiscalPayloadArrayGuards::requireArray($data, 'staleness');

        return new self(
            accountPaymentUuid: FiscalPayloadArrayGuards::requireString($data, 'account_payment_uuid'),
            businessDate: FiscalPayloadArrayGuards::requireString($data, 'business_date'),
            cashierId: FiscalPayloadArrayGuards::requireString($data, 'cashier_id'),
            cashierName: FiscalPayloadArrayGuards::requireString($data, 'cashier_name'),
            currencyCode: FiscalPayloadArrayGuards::requireString($data, 'currency_code'),
            currencyScale: FiscalPayloadArrayGuards::requireInt($data, 'currency_scale'),
            // @phpstan-ignore-next-line argument.type
            customer: $customer,
            eventTimeDevice: FiscalPayloadArrayGuards::requireString($data, 'event_time_device'),
            // @phpstan-ignore-next-line argument.type
            localBalanceSnapshot: $localBalanceSnapshot,
            notes: FiscalPayloadArrayGuards::optionalString($data, 'notes'),
            // @phpstan-ignore-next-line argument.type
            payment: $payment,
            receiptTypeCode: FiscalPayloadArrayGuards::requireString($data, 'receipt_type_code'),
            // @phpstan-ignore-next-line argument.type
            references: FiscalPayloadArrayGuards::optionalArray($data, 'references'),
            // @phpstan-ignore-next-line argument.type
            regimeExtensions: FiscalPayloadArrayGuards::optionalArray($data, 'regime_extensions'),
            // @phpstan-ignore-next-line argument.type
            seller: $seller,
            shiftId: FiscalPayloadArrayGuards::requireString($data, 'shift_id'),
            // @phpstan-ignore-next-line argument.type
            staleness: $staleness,
            terminalId: FiscalPayloadArrayGuards::requireString($data, 'terminal_id'),
            trainingFlag: FiscalPayloadArrayGuards::requireBool($data, 'training_flag'),
            treasuryAllocationPolicy: FiscalPayloadArrayGuards::requireString($data, 'treasury_allocation_policy'),
            sourcePayload: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->sourcePayload !== null) {
            return $this->sourcePayload;
        }

        return [
            'account_payment_uuid' => $this->accountPaymentUuid,
            'business_date' => $this->businessDate,
            'cashier_id' => $this->cashierId,
            'cashier_name' => $this->cashierName,
            'currency_code' => $this->currencyCode,
            'currency_scale' => $this->currencyScale,
            'customer' => $this->customer,
            'event_time_device' => $this->eventTimeDevice,
            'local_balance_snapshot' => $this->localBalanceSnapshot,
            'notes' => $this->notes,
            'payment' => $this->payment,
            'receipt_type_code' => $this->receiptTypeCode,
            'references' => $this->references,
            'regime_extensions' => $this->regimeExtensions,
            'seller' => $this->seller,
            'shift_id' => $this->shiftId,
            'staleness' => $this->staleness,
            'terminal_id' => $this->terminalId,
            'training_flag' => $this->trainingFlag,
            'treasury_allocation_policy' => $this->treasuryAllocationPolicy,
        ];
    }
}
