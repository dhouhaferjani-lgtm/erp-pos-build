<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

/**
 * Normalizes European/US-formatted numbers on numeric-validated import fields.
 *
 * Excel in French/German locales exports "10,00" and "1.234,56"; US locales
 * export "1,234.56". Laravel's `numeric` rule rejects all three, so imported
 * files from those locales fail validation on every priced row. This
 * normalizer converts such strings to canonical dot-decimal form BEFORE rows
 * are stored, so validation, execution, and the failed-rows export all see
 * the same canonical value.
 *
 * Values stay strings end-to-end (precision contract: no float on money).
 * Ambiguous "1,234" is read as decimal comma (1.234) — the European
 * interpretation, matching the product's primary markets. Unparseable values
 * are left untouched for validation to reject with a clear error.
 */
final class NumericFieldNormalizer
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, mixed>
     */
    public function normalize(array $data, array $rules): array
    {
        foreach ($data as $field => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (! $this->isNumericField($field, $rules)) {
                continue;
            }

            $data[$field] = $this->normalizeValue($value, $this->isPercentScaleField($rules[$field] ?? []));
        }

        return $data;
    }

    /**
     * @param  array<string, array<int, mixed>>  $rules
     */
    private function isNumericField(string $field, array $rules): bool
    {
        return in_array('numeric', $rules[$field] ?? [], true);
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    private function isPercentScaleField(array $rules): bool
    {
        return in_array('regex:/^-?\d+(\.\d{1,2})?$/', $rules, true);
    }

    private function normalizeValue(string $value, bool $percentScaleField = false): string
    {
        $trimmed = trim($value);

        if ($percentScaleField && preg_match('/^-?\d+\.\d+$/', $trimmed) === 1) {
            return $value;
        }

        // European thousands-dot with decimal comma: 1.234,56
        if (preg_match('/^-?\d{1,3}(\.\d{3})+,\d+$/', $trimmed) === 1) {
            return str_replace(',', '.', str_replace('.', '', $trimmed));
        }

        // Plain decimal comma: 10,00 (also the ambiguous 1,234 case)
        if (preg_match('/^-?\d+,\d+$/', $trimmed) === 1) {
            return str_replace(',', '.', $trimmed);
        }

        // US thousands-comma with decimal dot: 1,234.56
        if (preg_match('/^-?\d{1,3}(,\d{3})+\.\d+$/', $trimmed) === 1) {
            return str_replace(',', '', $trimmed);
        }

        return $value;
    }
}
