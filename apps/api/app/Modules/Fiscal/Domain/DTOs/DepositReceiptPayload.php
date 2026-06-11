<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

/**
 * Canonical payload for the server-authored `DEPOSIT_RECEIPT` fiscal event.
 *
 * `DEPOSIT_RECEIPT` is the back-office / mobile-web counterpart of the
 * device-authored `ACCOUNT_PAYMENT`: a store owner records a payment toward a
 * customer's account from the admin dashboard (no physical terminal). The money
 * is settled FIFO against open invoices with any remainder overflowing to the
 * customer's credit balance — the SAME allocation engine the desktop path uses.
 *
 * The contract is intentionally leaner than `ACCOUNT_PAYMENT`: a server-authored
 * event has no device staleness, no local balance snapshot, and no offline
 * treasury-allocation negotiation — the server is the authority. `seller` is
 * omitted (resolved at print time) to avoid coupling to country-specific tax
 * validation, mirroring the server-only `ACCOUNT_STATUS_CHANGED` contract.
 */
final readonly class DepositReceiptPayload
{
    /** Sorted-lex key set — mirrored into FiscalPayloadConstraintValidator::PAYLOAD_KEYS. */
    public const PAYLOAD_KEYS = [
        'actor_name',
        'actor_user_id',
        'business_date',
        'company_id',
        'currency_code',
        'currency_scale',
        'customer',
        'deposit_receipt_uuid',
        'event_time_device',
        'notes',
        'partner_id',
        'payment',
        'tenant_id',
        'terminal_id',
        'training_flag',
        'treasury_allocation_policy',
    ];

    /**
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>|null  $sourcePayload
     */
    public function __construct(
        public string $actorName,
        public string $actorUserId,
        public string $businessDate,
        public string $companyId,
        public string $currencyCode,
        public int $currencyScale,
        public array $customer,
        public string $depositReceiptUuid,
        public string $eventTimeDevice,
        public ?string $notes,
        public string $partnerId,
        public array $payment,
        public string $tenantId,
        public string $terminalId,
        public bool $trainingFlag,
        public string $treasuryAllocationPolicy,
        private ?array $sourcePayload = null,
    ) {}

    /**
     * @return list<string>
     */
    public static function payloadKeys(): array
    {
        return self::PAYLOAD_KEYS;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $customer = FiscalPayloadArrayGuards::requireArray($data, 'customer');
        $payment = FiscalPayloadArrayGuards::requireArray($data, 'payment');

        return new self(
            actorName: FiscalPayloadArrayGuards::requireString($data, 'actor_name'),
            actorUserId: FiscalPayloadArrayGuards::requireString($data, 'actor_user_id'),
            businessDate: FiscalPayloadArrayGuards::requireString($data, 'business_date'),
            companyId: FiscalPayloadArrayGuards::requireString($data, 'company_id'),
            currencyCode: FiscalPayloadArrayGuards::requireString($data, 'currency_code'),
            currencyScale: FiscalPayloadArrayGuards::requireInt($data, 'currency_scale'),
            // @phpstan-ignore-next-line argument.type
            customer: $customer,
            depositReceiptUuid: FiscalPayloadArrayGuards::requireString($data, 'deposit_receipt_uuid'),
            eventTimeDevice: FiscalPayloadArrayGuards::requireString($data, 'event_time_device'),
            notes: FiscalPayloadArrayGuards::optionalString($data, 'notes'),
            partnerId: FiscalPayloadArrayGuards::requireString($data, 'partner_id'),
            // @phpstan-ignore-next-line argument.type
            payment: $payment,
            tenantId: FiscalPayloadArrayGuards::requireString($data, 'tenant_id'),
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
            'actor_name' => $this->actorName,
            'actor_user_id' => $this->actorUserId,
            'business_date' => $this->businessDate,
            'company_id' => $this->companyId,
            'currency_code' => $this->currencyCode,
            'currency_scale' => $this->currencyScale,
            'customer' => $this->customer,
            'deposit_receipt_uuid' => $this->depositReceiptUuid,
            'event_time_device' => $this->eventTimeDevice,
            'notes' => $this->notes,
            'partner_id' => $this->partnerId,
            'payment' => $this->payment,
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
            'training_flag' => $this->trainingFlag,
            'treasury_allocation_policy' => $this->treasuryAllocationPolicy,
        ];
    }
}
