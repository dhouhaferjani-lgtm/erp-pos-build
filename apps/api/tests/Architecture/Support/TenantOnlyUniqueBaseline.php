<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use JsonException;
use RuntimeException;
use UnexpectedValueException;

final class TenantOnlyUniqueBaseline
{
    /**
     * @return list<TenantOnlyUniqueBaselineEntry>
     *
     * @throws JsonException
     */
    public static function fromFile(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read tenant-only unique baseline: '.$path);
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Tenant-only unique baseline must be a JSON array.');
        }

        $entries = [];
        foreach ($decoded as $position => $item) {
            if (! is_array($item)) {
                throw new UnexpectedValueException("Baseline entry {$position} must be an object.");
            }

            $key = $item['key'] ?? null;
            $waiver = $item['waiver'] ?? null;
            if (! is_string($key) || $key === '') {
                throw new UnexpectedValueException("Baseline entry {$position} needs a non-empty key.");
            }
            if (! is_string($waiver) || trim($waiver) === '') {
                throw new UnexpectedValueException("Baseline entry {$position} needs a non-empty waiver reason.");
            }

            $entries[] = new TenantOnlyUniqueBaselineEntry($key, $waiver);
        }

        return $entries;
    }
}
