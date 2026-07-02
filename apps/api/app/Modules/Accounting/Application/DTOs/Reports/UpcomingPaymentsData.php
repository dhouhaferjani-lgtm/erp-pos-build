<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UpcomingPaymentsData extends Data
{
    /**
     * @param  DataCollection<int, UpcomingPaymentLineData>|array<int, UpcomingPaymentLineData>  $in
     * @param  DataCollection<int, UpcomingPaymentLineData>|array<int, UpcomingPaymentLineData>  $out
     */
    public function __construct(
        #[DataCollectionOf(UpcomingPaymentLineData::class)]
        public readonly DataCollection|array $in,
        #[DataCollectionOf(UpcomingPaymentLineData::class)]
        public readonly DataCollection|array $out,
        public readonly string $total_in,
        public readonly string $total_out,
        public readonly string $net,
        public readonly int $days,
        public readonly string $as_of_date,
    ) {}
}
