<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

use App\Shared\Enums\ProductIdentityFailure;
use App\Shared\Enums\ProductIdentityMatch;

final readonly class ProductIdentityResolutionData
{
    /** @param list<string> $candidateSkus */
    public function __construct(
        public ?string $productId,
        public ?string $sku,
        public ?ProductIdentityMatch $matchedBy,
        public array $candidateSkus = [],
        public ?ProductIdentityFailure $failure = null,
        public ?string $failureSku = null,
    ) {}

    public function isBarcodeAmbiguous(): bool
    {
        return $this->failure === ProductIdentityFailure::BarcodeAmbiguous;
    }
}
