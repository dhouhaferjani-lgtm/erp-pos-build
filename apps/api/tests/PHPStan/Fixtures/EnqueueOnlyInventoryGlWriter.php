<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Application\DTOs\MovementGlContext;

final class EnqueueOnlyInventoryGlWriter
{
    public function __construct(private readonly InventoryGlPostingBuffer $buffer) {}

    public function run(MovementGlContext $context): void
    {
        $this->buffer->enqueue($context);
    }
}
