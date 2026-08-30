<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use JsonException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Reviewed baseline for qualifying catalogue uniques.
 *
 * An entry with only `key` is frozen legacy debt and must shrink. An optional
 * non-empty `waiver` records why the key is legitimately tenant-global by
 * nature. Residual: unlike the DPA ratchet, this baseline has no protected-blob
 * authority; reviewers must inspect its diff. To re-pin after a reviewed schema
 * change, regenerate the sorted live keys, preserve only legitimate waivers,
 * and raise LEGACY_ENTRY_CEILING in the ratchet test if legacy debt grew.
 */
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
            if (array_key_exists('waiver', $item) && (! is_string($waiver) || trim($waiver) === '')) {
                throw new UnexpectedValueException("Baseline entry {$position} waiver must be a non-empty reason when present.");
            }

            $entries[] = new TenantOnlyUniqueBaselineEntry(
                $key,
                is_string($waiver) ? $waiver : null,
            );
        }

        return $entries;
    }
}
