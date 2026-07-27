<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use App\Modules\Accounting\Application\DTOs\Reports\LocationReportBucketData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MaturingInstrumentsMetaData extends Data
{
    /** @param DataCollection<int, LocationReportBucketData>|array<int, LocationReportBucketData> $buckets_by_location */
    public function __construct(
        public readonly MaturingInstrumentBucketsData $buckets,
        public readonly MaturingInstrumentBucketData $grand_total,
        #[DataCollectionOf(LocationReportBucketData::class)]
        public readonly DataCollection|array $buckets_by_location = [],
    ) {}
}
