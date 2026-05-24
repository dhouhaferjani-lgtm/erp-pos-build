<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

/**
 * One row in `fiscal_events.payload.payments[]` — 6 properties per
 * Candidate C-v3 §3.
 *
 * `method_code` is UN/ECE 4461 mapped (e.g. "CASH", "CARD", "VOUCHER").
 * `amount` is bcformat at currency_scale.
 * `foreign_currency_amount` is bcformat at the foreign currency's scale
 * (e.g. EUR primary + USD secondary on DSFinV-K-style split payment).
 * `foreign_currency_code` is ISO 4217.
 * `instrument_type` + `instrument_serial` describe e.g. voucher serial,
 * card last-4.
 *
 * NOTE: `payment_method_id` is NOT on the canonical payload — Pass
 * 2A.PHP.2 projector resolves the tenant-scoped FK from
 * `(tenant_id, method_code)` lookup against `treasury_payment_methods`.
 */
final readonly class PaymentDTO
{
    public function __construct(
        public string $amount,
        public ?string $foreignCurrencyAmount,
        public ?string $foreignCurrencyCode,
        public ?string $instrumentSerial,
        public ?string $instrumentType,
        public string $methodCode,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            amount: FiscalPayloadArrayGuards::requireString($data, 'amount'),
            foreignCurrencyAmount: FiscalPayloadArrayGuards::optionalString($data, 'foreign_currency_amount'),
            foreignCurrencyCode: FiscalPayloadArrayGuards::optionalString($data, 'foreign_currency_code'),
            instrumentSerial: FiscalPayloadArrayGuards::optionalString($data, 'instrument_serial'),
            instrumentType: FiscalPayloadArrayGuards::optionalString($data, 'instrument_type'),
            methodCode: FiscalPayloadArrayGuards::requireString($data, 'method_code'),
        );
    }
}
