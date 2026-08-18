<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Application\Services;

use BackedEnum;
use InvalidArgumentException;
use Normalizer;
use ReflectionClass;
use RuntimeException;

final class CanonicalCoaSerializer
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * Pin the frozen definition-array insertion sequence as legacy export order.
     *
     * M4's legacy exporter must call this on the definitions exactly as they
     * appear in frozen seeder source, before database insertion/querying can
     * discard that sequence. Persisted template rows still own an explicit,
     * unique sort_order; serialize() never synthesizes or weakens that contract.
     *
     * @param  list<array<string, BackedEnum|bool|int|string|null>>  $rows
     * @return list<array<string, BackedEnum|bool|int|string|null>>
     */
    public function withLegacyInsertionOrder(array $rows): array
    {
        $adapted = [];
        foreach ($rows as $index => $row) {
            $adapted[] = [...$row, 'sort_order' => $index + 1];
        }

        return $adapted;
    }

    /**
     * Canonical template content deliberately has no activation field. The central
     * admin_template_accounts schema stores no is_active value, and the future
     * template-backed company seeder must derive is_active=true for every account
     * it creates. Activation is therefore provisioning policy, never editable or
     * hash-bearing template content.
     *
     * @param  list<array<string, BackedEnum|bool|int|string|null>>  $rows
     */
    public function serialize(array $rows): string
    {
        $this->nativeIcuVersion();

        $parentCodesById = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (is_int($id) || is_string($id)) {
                $parentCodesById[(string) $id] = $this->requiredString($row, 'code');
            }
        }

        $projected = [];
        $sortOrders = [];
        foreach ($rows as $row) {
            $sortOrder = $row['sort_order'] ?? null;
            if (! is_int($sortOrder)) {
                throw new InvalidArgumentException('Canonical COA sort_order must be an integer.');
            }
            if (isset($sortOrders[$sortOrder])) {
                throw new InvalidArgumentException("Canonical COA sort_order {$sortOrder} is not unique.");
            }
            $sortOrders[$sortOrder] = true;

            $parentCode = $this->parentCode($row, $parentCodesById);
            $isSystem = $row['is_system'] ?? null;
            if (! is_bool($isSystem)) {
                throw new InvalidArgumentException('Canonical COA is_system must be boolean.');
            }

            $projected[] = [
                'code' => $this->normalize($this->requiredString($row, 'code')),
                'name' => $this->normalize($this->requiredString($row, 'name')),
                'type' => $this->normalize($this->enumOrString($row, 'type')),
                'parent_code' => $parentCode === null ? null : $this->normalize($parentCode),
                'system_purpose' => $this->nullableEnumOrString($row, 'system_purpose'),
                'is_system' => $isSystem,
                'sort_order' => $sortOrder,
            ];
        }

        usort($projected, static fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);

        return implode("\n", array_map(
            static fn (array $row): string => json_encode($row, self::JSON_FLAGS),
            $projected,
        ));
    }

    /**
     * @param  list<array<string, BackedEnum|bool|int|string|null>>  $rows
     */
    public function hash(array $rows): string
    {
        return hash('sha256', $this->serialize($rows));
    }

    public function nativeIcuVersion(): string
    {
        if (! extension_loaded('intl')) {
            throw new RuntimeException('Certification hashing requires the ext-intl native ICU implementation.');
        }

        $normalizer = new ReflectionClass(Normalizer::class);
        if (! $normalizer->isInternal()) {
            throw new RuntimeException('Certification hashing rejects a polyfill-only Normalizer; native ICU is required.');
        }

        $version = defined('INTL_ICU_VERSION') ? constant('INTL_ICU_VERSION') : null;
        if (! is_string($version) || trim($version) === '') {
            throw new RuntimeException('Certification hashing could not identify the active native ICU version.');
        }

        return $version;
    }

    /**
     * @param  array<string, BackedEnum|bool|int|string|null>  $row
     * @param  array<string, string>  $parentCodesById
     */
    private function parentCode(array $row, array $parentCodesById): ?string
    {
        if (array_key_exists('parent_code', $row)) {
            $parentCode = $row['parent_code'];
            if ($parentCode === null || is_string($parentCode)) {
                return $parentCode;
            }

            throw new InvalidArgumentException('Canonical COA parent_code must be string or null.');
        }

        $parentId = $row['parent_id'] ?? null;
        if ($parentId === null) {
            return null;
        }
        if (! is_int($parentId) && ! is_string($parentId)) {
            throw new InvalidArgumentException('Canonical COA parent_id must be string, integer, or null.');
        }

        return $parentCodesById[(string) $parentId]
            ?? throw new InvalidArgumentException("Canonical COA parent_id {$parentId} does not resolve to an input row.");
    }

    /** @param array<string, BackedEnum|bool|int|string|null> $row */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (! is_string($value)) {
            throw new InvalidArgumentException("Canonical COA {$key} must be a string.");
        }

        return $value;
    }

    /** @param array<string, BackedEnum|bool|int|string|null> $row */
    private function enumOrString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }
        if (is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException("Canonical COA {$key} must be a string or backed enum.");
    }

    /** @param array<string, BackedEnum|bool|int|string|null> $row */
    private function nullableEnumOrString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }

        return $this->normalize($this->enumOrString($row, $key));
    }

    private function normalize(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Canonical COA strings must contain valid UTF-8.');
        }

        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if ($normalized === false) {
            throw new RuntimeException('Unable to normalize canonical COA string to NFC.');
        }

        return $normalized;
    }
}
