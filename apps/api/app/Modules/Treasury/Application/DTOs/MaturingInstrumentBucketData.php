<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MaturingInstrumentBucketData extends Data
{
    public function __construct(
        public readonly int $count,
        public readonly string $total_in,
        public readonly string $total_out,
    ) {}
}
