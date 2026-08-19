<?php

declare(strict_types=1);

namespace Tests\Traits;

trait ReadsCanonicalBytes
{
    /**
     * Normalize the driver-dependent value returned for a BYTEA/BLOB column.
     */
    private function stringifyCanonicalBytes(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            if ($contents === false) {
                self::fail('Unable to read canonical_bytes stream.');
            }

            return $contents;
        }

        return (string) $value;
    }
}
