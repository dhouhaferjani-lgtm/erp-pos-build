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
        return $this->normalizeWithReport($data, $rules)['data'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<int, mixed>>  $rules
     * @return array{data: array<string, mixed>, normalized_fields: list<string>}
     */
    public function normalizeWithReport(array $data, array $rules): array
    {
        $normalizedFields = [];

        foreach ($data as $field => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (! $this->isNumericField($field, $rules)) {
                continue;
            }

            $fieldRules = $rules[$field] ?? [];
            $normalized = $this->normalizeLocaleValue($value);
            $scale = $this->decimalScale($fieldRules);
            if ($scale === null) {
                $data[$field] = $normalized;

                continue;
            }

            $floatNoise = $this->normalizeFloatNoise($normalized, $scale);
            $data[$field] = $floatNoise['value'];
            if ($floatNoise['normalized']) {
                $normalizedFields[] = $field;
            }
        }

        return ['data' => $data, 'normalized_fields' => $normalizedFields];
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
    private function decimalScale(array $rules): ?int
    {
        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            if (preg_match('/\\\\d\{1,(\d+)\}/', $rule, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function normalizeLocaleValue(string $value): string
    {
        $trimmed = trim($value);

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

    /** @return array{value: string, normalized: bool} */
    private function normalizeFloatNoise(string $value, int $scale): array
    {
        $expanded = $this->expandDecimal($value);
        if ($expanded === null) {
            return ['value' => $value, 'normalized' => false];
        }

        $hasExponent = str_contains(strtolower($value), 'e');
        $fractionLength = strlen(explode('.', ltrim($expanded, '-'), 2)[1] ?? '');
        if (! $hasExponent && $fractionLength <= $scale) {
            return ['value' => $value, 'normalized' => false];
        }

        $rounded = $this->roundHalfUp($expanded, $scale);
        $comparisonScale = max($fractionLength, $scale + 7);
        $difference = $this->absoluteDifference($expanded, $rounded, $comparisonScale);
        if (bccomp($difference, '0', $comparisonScale) === 0) {
            return ['value' => $rounded, 'normalized' => true];
        }

        $absoluteValue = bccomp($expanded, '0', $comparisonScale) === -1
            ? bcmul($expanded, '-1', $comparisonScale)
            : $expanded;
        if (bccomp($absoluteValue, '0', $comparisonScale) === 0) {
            return ['value' => $value, 'normalized' => false];
        }

        $relativeThreshold = bcmul($absoluteValue, '0.000001', $comparisonScale);

        $withinEpsilon = bccomp($difference, '0.000001', $comparisonScale) === -1
            && bccomp($difference, $relativeThreshold, $comparisonScale) === -1;

        return [
            'value' => $withinEpsilon ? $rounded : $value,
            'normalized' => $withinEpsilon,
        ];
    }

    /** @return numeric-string|null */
    private function expandDecimal(string $value): ?string
    {
        $trimmed = trim($value);
        if (preg_match('/^([+-]?)(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', $trimmed, $matches) !== 1) {
            return null;
        }

        $exponentText = $matches[4] ?? '0';
        if (strlen(ltrim($exponentText, '+-')) > 3) {
            return null;
        }
        $exponent = (int) $exponentText;
        if (abs($exponent) > 100) {
            return null;
        }

        $integer = $matches[2];
        $fraction = $matches[3] ?? '';
        $digits = $integer.$fraction;
        $decimalPosition = strlen($integer) + $exponent;

        if ($decimalPosition <= 0) {
            $plain = '0.'.str_repeat('0', -$decimalPosition).$digits;
        } elseif ($decimalPosition >= strlen($digits)) {
            $plain = $digits.str_repeat('0', $decimalPosition - strlen($digits));
        } else {
            $plain = substr($digits, 0, $decimalPosition).'.'.substr($digits, $decimalPosition);
        }

        [$plainInteger, $plainFraction] = array_pad(explode('.', $plain, 2), 2, '');
        $plainInteger = ltrim($plainInteger, '0');
        $plainInteger = $plainInteger === '' ? '0' : $plainInteger;
        $isZero = $plainInteger === '0' && trim($plainFraction, '0') === '';
        $sign = $matches[1] === '-' && ! $isZero ? '-' : '';

        $expanded = $sign.$plainInteger.($plainFraction === '' ? '' : '.'.$plainFraction);

        return is_numeric($expanded) ? $expanded : null;
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private function roundHalfUp(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $unsigned = ltrim($value, '-');
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $paddedFraction = str_pad($fraction, $scale + 1, '0');
        $keptFraction = substr($paddedFraction, 0, $scale);
        $roundingDigit = (int) ($paddedFraction[$scale] ?? '0');
        $magnitude = ltrim($integer.$keptFraction, '0');
        $magnitude = $magnitude === '' ? '0' : $magnitude;
        if (! is_numeric($magnitude)) {
            throw new \LogicException('Rounded decimal magnitude must remain numeric.');
        }
        if ($roundingDigit >= 5) {
            $magnitude = bcadd($magnitude, '1', 0);
        }

        $minimumLength = $scale + 1;
        $magnitude = str_pad($magnitude, $minimumLength, '0', STR_PAD_LEFT);
        $roundedInteger = $scale === 0 ? $magnitude : substr($magnitude, 0, -$scale);
        $roundedFraction = $scale === 0 ? '' : substr($magnitude, -$scale);
        $isZero = trim($roundedInteger.$roundedFraction, '0') === '';

        $rounded = ($negative && ! $isZero ? '-' : '')
            .$roundedInteger
            .($scale === 0 ? '' : '.'.$roundedFraction);

        if (! is_numeric($rounded)) {
            throw new \LogicException('Rounded decimal must remain numeric.');
        }

        return $rounded;
    }

    /**
     * @param  numeric-string  $left
     * @param  numeric-string  $right
     * @return numeric-string
     */
    private function absoluteDifference(string $left, string $right, int $scale): string
    {
        return bccomp($left, $right, $scale) >= 0
            ? bcsub($left, $right, $scale)
            : bcsub($right, $left, $scale);
    }
}
