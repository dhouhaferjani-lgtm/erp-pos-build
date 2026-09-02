<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Casts;

use App\Modules\Import\Domain\Data\ImportEnrichmentSummaryData;

final class ImportEnrichmentSummaryCast extends TypedJsonArrayCast
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, int>
     */
    protected function normalize(array $payload): array
    {
        /** @var array<string, mixed> $keyed */
        $keyed = $payload;

        return ImportEnrichmentSummaryData::fromStorage($keyed)->toStorage();
    }
}
