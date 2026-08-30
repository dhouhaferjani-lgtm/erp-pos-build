<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Casts;

use App\Modules\Import\Domain\Data\ImportRowWarningData;

final class ImportRowWarningCollectionCast extends TypedJsonArrayCast
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<array{code: string|null, detail: string}>
     */
    protected function normalize(array $payload): array
    {
        $warnings = [];
        foreach ($payload as $warning) {
            if (! is_array($warning)) {
                continue;
            }

            /** @var array<string, mixed> $keyed */
            $keyed = $warning;
            $warnings[] = ImportRowWarningData::fromStorage($keyed)->toStorage();
        }

        return $warnings;
    }
}
