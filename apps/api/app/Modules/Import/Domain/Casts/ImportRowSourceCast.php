<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Casts;

use App\Modules\Import\Domain\Data\ImportRowSourceData;

final class ImportRowSourceCast extends TypedJsonArrayCast
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    protected function normalize(array $payload): array
    {
        /** @var array<string, mixed> $keyed */
        $keyed = $payload;

        return ImportRowSourceData::fromStorage($keyed)->toStorage();
    }
}
