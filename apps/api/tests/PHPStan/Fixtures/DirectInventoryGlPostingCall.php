<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Application\DTOs\MovementGlContext;

final class DirectInventoryGlPostingCall
{
    public function __construct(private readonly InventoryGlPostingService $postingService) {}

    public function run(MovementGlContext $context): void
    {
        $this->postingService->postForExit($context);
    }
}
