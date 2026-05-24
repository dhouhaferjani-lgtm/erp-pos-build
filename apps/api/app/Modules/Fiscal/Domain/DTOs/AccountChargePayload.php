<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

final readonly class AccountChargePayload
{
    public const PAYLOAD_KEYS = [
        'account_charge_uuid',
        'business_date',
        'buyer',
        'cashier_id',
        'cashier_name',
        'charge_terms',
        'credit_decision',
        'currency_code',
        'currency_scale',
        'customer',
        'event_time_device',
        'invoice_classification',
        'line_items',
        'local_balance_snapshot',
        'notes',
        'print_profile',
        'receipt_type_code',
        'references',
        'regime_extensions',
        'seller',
        'shift_id',
        'staleness',
        'terminal_id',
        'totals',
        'training_flag',
        'transaction_discount_amount',
        'transaction_discount_reason',
        'vat_breakdown',
    ];

    /**
     * @param  array<string, mixed>|null  $buyer
     * @param  array<string, mixed>  $chargeTerms
     * @param  array<string, mixed>  $creditDecision
     * @param  array<string, mixed>  $customer
     * @param  list<array<string, mixed>>  $lineItems
     * @param  array<string, mixed>  $localBalanceSnapshot
     * @param  array<string, mixed>|null  $references
     * @param  array<string, mixed>|null  $regimeExtensions
     * @param  array<string, mixed>  $seller
     * @param  array<string, mixed>  $staleness
     * @param  array<string, mixed>  $totals
     * @param  list<array<string, mixed>>  $vatBreakdown
     */
    public function __construct(
        public string $accountChargeUuid,
        public string $businessDate,
        public ?array $buyer,
        public string $cashierId,
        public string $cashierName,
        public array $chargeTerms,
        public array $creditDecision,
        public string $currencyCode,
        public int $currencyScale,
        public array $customer,
        public string $eventTimeDevice,
        public string $invoiceClassification,
        public array $lineItems,
        public array $localBalanceSnapshot,
        public ?string $notes,
        public string $printProfile,
        public string $receiptTypeCode,
        public ?array $references,
        public ?array $regimeExtensions,
        public array $seller,
        public string $shiftId,
        public array $staleness,
        public string $terminalId,
        public array $totals,
        public bool $trainingFlag,
        public string $transactionDiscountAmount,
        public ?string $transactionDiscountReason,
        public array $vatBreakdown,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $chargeTerms = FiscalPayloadArrayGuards::requireArray($data, 'charge_terms');
        $creditDecision = FiscalPayloadArrayGuards::requireArray($data, 'credit_decision');
        $customer = FiscalPayloadArrayGuards::requireArray($data, 'customer');
        $lineItems = FiscalPayloadArrayGuards::requireArray($data, 'line_items');
        $localBalanceSnapshot = FiscalPayloadArrayGuards::requireArray($data, 'local_balance_snapshot');
        $seller = FiscalPayloadArrayGuards::requireArray($data, 'seller');
        $staleness = FiscalPayloadArrayGuards::requireArray($data, 'staleness');
        $totals = FiscalPayloadArrayGuards::requireArray($data, 'totals');
        $vatBreakdown = FiscalPayloadArrayGuards::requireArray($data, 'vat_breakdown');

        return new self(
            accountChargeUuid: FiscalPayloadArrayGuards::requireString($data, 'account_charge_uuid'),
            businessDate: FiscalPayloadArrayGuards::requireString($data, 'business_date'),
            // @phpstan-ignore-next-line argument.type
            buyer: FiscalPayloadArrayGuards::optionalArray($data, 'buyer'),
            cashierId: FiscalPayloadArrayGuards::requireString($data, 'cashier_id'),
            cashierName: FiscalPayloadArrayGuards::requireString($data, 'cashier_name'),
            // @phpstan-ignore-next-line argument.type
            chargeTerms: $chargeTerms,
            // @phpstan-ignore-next-line argument.type
            creditDecision: $creditDecision,
            currencyCode: FiscalPayloadArrayGuards::requireString($data, 'currency_code'),
            currencyScale: FiscalPayloadArrayGuards::requireInt($data, 'currency_scale'),
            // @phpstan-ignore-next-line argument.type
            customer: $customer,
            eventTimeDevice: FiscalPayloadArrayGuards::requireString($data, 'event_time_device'),
            invoiceClassification: FiscalPayloadArrayGuards::requireString($data, 'invoice_classification'),
            // @phpstan-ignore-next-line argument.type
            lineItems: $lineItems,
            // @phpstan-ignore-next-line argument.type
            localBalanceSnapshot: $localBalanceSnapshot,
            notes: FiscalPayloadArrayGuards::optionalString($data, 'notes'),
            printProfile: FiscalPayloadArrayGuards::requireString($data, 'print_profile'),
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
            // @phpstan-ignore-next-line argument.type
            totals: $totals,
            trainingFlag: FiscalPayloadArrayGuards::requireBool($data, 'training_flag'),
            transactionDiscountAmount: FiscalPayloadArrayGuards::requireString($data, 'transaction_discount_amount'),
            transactionDiscountReason: FiscalPayloadArrayGuards::optionalString($data, 'transaction_discount_reason'),
            // @phpstan-ignore-next-line argument.type
            vatBreakdown: $vatBreakdown,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'account_charge_uuid' => $this->accountChargeUuid,
            'business_date' => $this->businessDate,
            'buyer' => $this->buyer,
            'cashier_id' => $this->cashierId,
            'cashier_name' => $this->cashierName,
            'charge_terms' => $this->chargeTerms,
            'credit_decision' => $this->creditDecision,
            'currency_code' => $this->currencyCode,
            'currency_scale' => $this->currencyScale,
            'customer' => $this->customer,
            'event_time_device' => $this->eventTimeDevice,
            'invoice_classification' => $this->invoiceClassification,
            'line_items' => $this->lineItems,
            'local_balance_snapshot' => $this->localBalanceSnapshot,
            'notes' => $this->notes,
            'print_profile' => $this->printProfile,
            'receipt_type_code' => $this->receiptTypeCode,
            'references' => $this->references,
            'regime_extensions' => $this->regimeExtensions,
            'seller' => $this->seller,
            'shift_id' => $this->shiftId,
            'staleness' => $this->staleness,
            'terminal_id' => $this->terminalId,
            'totals' => $this->totals,
            'training_flag' => $this->trainingFlag,
            'transaction_discount_amount' => $this->transactionDiscountAmount,
            'transaction_discount_reason' => $this->transactionDiscountReason,
            'vat_breakdown' => $this->vatBreakdown,
        ];
    }
}
