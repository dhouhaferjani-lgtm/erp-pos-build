<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

final readonly class ClearInstrumentData
{
    /**
     * @param  numeric-string  $feeAmount
     * @param  numeric-string  $feeVatAmount
     */
    public function __construct(
        public string $instrumentId,
        public string $currency,
        public string $feeAmount = '0.000',
        public string $feeVatAmount = '0.000',
        public ?string $valueDate = null,
        public ?string $userId = null,
    ) {}
}
