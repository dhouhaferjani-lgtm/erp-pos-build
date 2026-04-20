<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class WorkOrderHoursBreakdownData extends Data
{
    public function __construct(
        public string $work_order_id,
        public int $minutes,
    ) {}
}
