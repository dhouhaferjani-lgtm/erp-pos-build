<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MaturingInstrumentBucketsData extends Data
{
    public function __construct(
        public readonly MaturingInstrumentBucketData $overdue,
        public readonly MaturingInstrumentBucketData $d0_7,
        public readonly MaturingInstrumentBucketData $d8_30,
        public readonly MaturingInstrumentBucketData $d31_60,
        public readonly MaturingInstrumentBucketData $d61_90,
        public readonly MaturingInstrumentBucketData $d90_plus,
    ) {}
}
