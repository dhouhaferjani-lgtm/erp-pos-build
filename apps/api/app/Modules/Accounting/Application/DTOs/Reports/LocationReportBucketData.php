<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class LocationReportBucketData extends Data
{
    public function __construct(
        public readonly ?string $location_id,
        public readonly string $location_name,
        public readonly string $total,
    ) {}
}
