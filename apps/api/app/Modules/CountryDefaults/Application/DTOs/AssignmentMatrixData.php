<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Application\DTOs;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AssignmentMatrixData extends Data
{
    /** @param DataCollection<int, AssignmentMatrixRowData>|array<int, AssignmentMatrixRowData> $data */
    public function __construct(
        #[DataCollectionOf(AssignmentMatrixRowData::class)]
        public readonly DataCollection|array $data,
        public readonly AssignmentMatrixMetaData $meta,
    ) {}
}
