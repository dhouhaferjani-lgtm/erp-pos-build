<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ScheduleWindowData extends Data
{
    public function __construct(
        public string $start, // HH:MM
        public string $end,   // HH:MM
    ) {}
}
