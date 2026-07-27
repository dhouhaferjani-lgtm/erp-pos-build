<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MaturingInstrumentsData extends Data
{
    /** @param DataCollection<int, MaturingInstrumentRowData>|array<int, MaturingInstrumentRowData> $data */
    public function __construct(
        #[DataCollectionOf(MaturingInstrumentRowData::class)]
        public readonly DataCollection|array $data,
        public readonly MaturingInstrumentsMetaData $meta,
    ) {}
}
