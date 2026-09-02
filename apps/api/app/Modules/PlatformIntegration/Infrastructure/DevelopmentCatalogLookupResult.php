<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Infrastructure;

use App\Shared\Contracts\CatalogLookupResultInterface;
use App\Shared\Enums\CatalogLookupOutcome;

final readonly class DevelopmentCatalogLookupResult implements CatalogLookupResultInterface
{
    public function __construct(
        private CatalogLookupOutcome $lookupOutcome,
        private ?string $productId,
    ) {}

    public function outcome(): CatalogLookupOutcome
    {
        return $this->lookupOutcome;
    }

    public function platformProductId(): ?string
    {
        return $this->productId;
    }
}
