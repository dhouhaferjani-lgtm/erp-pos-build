<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

/**
 * One row in `fiscal_events.payload.vouchers_redeemed[]`.
 *
 * Per Candidate C-v3 §3: `{redeemed_amount, voucher_code}`.
 * `redeemed_amount` is bcformat NON-NEGATIVE at currency_scale.
 */
final readonly class VoucherRedemptionDTO
{
    public function __construct(
        public string $redeemedAmount,
        public string $voucherCode,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            redeemedAmount: FiscalPayloadArrayGuards::requireString($data, 'redeemed_amount'),
            voucherCode: FiscalPayloadArrayGuards::requireString($data, 'voucher_code'),
        );
    }
}
