<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\Enums\CatalogLookupOutcome;

interface CatalogLookupResultInterface
{
    public function outcome(): CatalogLookupOutcome;

    public function platformProductId(): ?string;
}
