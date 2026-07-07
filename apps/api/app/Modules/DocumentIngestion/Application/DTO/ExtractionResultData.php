<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\DTO;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ExtractionResultData extends Data
{
    /**
     * @param  array<string, ExtractedFieldData>  $supplier
     * @param  array<string, ExtractedFieldData>  $header
     * @param  list<ExtractedLineData>  $lines
     */
    public function __construct(
        public string $docKind,
        public int $pages,
        public array $supplier,
        public array $header,
        public array $lines,
        public bool $totalsConsistent,
    ) {}

    public static function from(mixed ...$payloads): static
    {
        if (count($payloads) !== 1 || ! is_array($payloads[0])) {
            throw new \InvalidArgumentException('ExtractionResultData expects one array payload');
        }

        /** @var array<string, mixed> $payload */
        $payload = $payloads[0];

        return new self(
            docKind: self::stringAt($payload, 'doc_kind'),
            pages: self::intAt($payload, 'pages'),
            supplier: self::fieldMap($payload, 'supplier'),
            header: self::fieldMap($payload, 'header'),
            lines: self::lines($payload),
            totalsConsistent: self::boolAt($payload, 'totals_consistent'),
        );
    }

    /**
     * @return array{
     *     doc_kind: string,
     *     pages: int,
     *     supplier: array<string, array<string, mixed>>,
     *     header: array<string, array<string, mixed>>,
     *     lines: list<array<string, array<string, mixed>|null>>,
     *     totals_consistent: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'doc_kind' => $this->docKind,
            'pages' => $this->pages,
            'supplier' => self::fieldMapToArray($this->supplier),
            'header' => self::fieldMapToArray($this->header),
            'lines' => array_map(
                static fn (ExtractedLineData $line): array => $line->toArray(),
                $this->lines,
            ),
            'totals_consistent' => $this->totalsConsistent,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function stringAt(array $payload, string $key): string
    {
        if (! array_key_exists($key, $payload) || ! is_string($payload[$key])) {
            throw new \InvalidArgumentException("{$key} must be a string");
        }

        return $payload[$key];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function intAt(array $payload, string $key): int
    {
        if (! array_key_exists($key, $payload) || ! is_int($payload[$key])) {
            throw new \InvalidArgumentException("{$key} must be an integer");
        }

        return $payload[$key];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function boolAt(array $payload, string $key): bool
    {
        if (! array_key_exists($key, $payload) || ! is_bool($payload[$key])) {
            throw new \InvalidArgumentException("{$key} must be a boolean");
        }

        return $payload[$key];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, ExtractedFieldData>
     */
    private static function fieldMap(array $payload, string $key): array
    {
        if (! array_key_exists($key, $payload) || ! is_array($payload[$key])) {
            throw new \InvalidArgumentException("{$key} must be an object");
        }

        $fields = [];
        foreach ($payload[$key] as $fieldKey => $fieldPayload) {
            if (! is_string($fieldKey) || ! is_array($fieldPayload)) {
                throw new \InvalidArgumentException("{$key} must contain field objects");
            }

            /** @var array<string, mixed> $fieldPayload */
            $fields[$fieldKey] = ExtractedFieldData::fromPayload($fieldPayload, "{$key}.{$fieldKey}");
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ExtractedLineData>
     */
    private static function lines(array $payload): array
    {
        if (! array_key_exists('lines', $payload) || ! is_array($payload['lines'])) {
            throw new \InvalidArgumentException('lines must be an array');
        }

        $lines = [];
        foreach (array_values($payload['lines']) as $index => $linePayload) {
            if (! is_array($linePayload)) {
                throw new \InvalidArgumentException("lines.{$index} must be an object");
            }

            /** @var array<string, mixed> $linePayload */
            $lines[] = ExtractedLineData::fromPayload($linePayload, $index);
        }

        return $lines;
    }

    /**
     * @param  array<string, ExtractedFieldData>  $fields
     * @return array<string, array<string, mixed>>
     */
    private static function fieldMapToArray(array $fields): array
    {
        $data = [];
        foreach ($fields as $key => $field) {
            $data[$key] = $field->toArray();
        }

        return $data;
    }
}
