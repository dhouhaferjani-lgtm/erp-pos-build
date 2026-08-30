<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Casts;

use App\Modules\Import\Domain\Data\ImportRowErrorBagData;

final class ImportRowErrorBagCast extends TypedJsonArrayCast
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, list<string>>
     */
    protected function normalize(array $payload): array
    {
        /** @var array<string, mixed> $keyed */
        $keyed = $payload;

        return ImportRowErrorBagData::fromStorage($keyed)->errors;
    }
}
