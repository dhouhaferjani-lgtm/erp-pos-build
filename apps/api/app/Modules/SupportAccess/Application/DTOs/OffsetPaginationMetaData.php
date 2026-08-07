<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class OffsetPaginationMetaData extends Data
{
    public function __construct(
        public int $current_page,
        public int $per_page,
        public int $total,
        public int $last_page,
        public ?int $from,
        public ?int $to,
    ) {}
}
