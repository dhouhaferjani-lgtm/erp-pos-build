<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Commands;

final readonly class RemoveLineCommand
{
    public function __construct(
        public string $work_order_id,
        public string $line_id,
    ) {}
}
