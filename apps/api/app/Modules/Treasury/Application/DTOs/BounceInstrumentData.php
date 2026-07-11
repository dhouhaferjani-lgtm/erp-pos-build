<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use App\Modules\Treasury\Domain\Enums\DishonorRouting;

final readonly class BounceInstrumentData
{
    /**
     * @param  numeric-string  $feeAmount
     * @param  numeric-string  $feeVatAmount
     */
    public function __construct(
        public string $instrumentId,
        public DishonorRouting $routing,
        public string $currency,
        public string $feeAmount = '0.000',
        public string $feeVatAmount = '0.000',
        public ?string $reason = null,
        public ?string $userId = null,
    ) {}
}
