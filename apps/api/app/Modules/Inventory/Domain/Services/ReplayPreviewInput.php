<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use Carbon\CarbonInterface;

final readonly class ReplayPreviewInput
{
    /**
     * @param  numeric-string  $finalQty
     * @param  numeric-string  $onHandNow
     */
    public function __construct(
        public string $key,
        public string $productId,
        public string $locationId,
        public ?string $variantId,
        public string $finalQty,
        public string $onHandNow,
        public CarbonInterface $from,
        public int $windowMinutes,
    ) {}
}
