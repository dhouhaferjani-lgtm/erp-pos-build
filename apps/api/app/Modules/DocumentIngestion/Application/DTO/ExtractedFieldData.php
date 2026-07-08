<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\DTO;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ExtractedFieldData extends Data
{
    /**
     * @param  list<float>|null  $sourceBbox
     */
    public function __construct(
        public string $value,
        public float $confidence,
        public ?array $sourceBbox = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, string $path): self
    {
        if (! array_key_exists('value', $payload) || ! is_string($payload['value'])) {
            throw new \InvalidArgumentException("{$path}.value must be a string");
        }

        if (! array_key_exists('confidence', $payload) || ! is_numeric($payload['confidence'])) {
            throw new \InvalidArgumentException("{$path}.confidence must be numeric");
        }

        $sourceBbox = null;
        if (array_key_exists('source_bbox', $payload)) {
            if (! is_array($payload['source_bbox'])) {
                throw new \InvalidArgumentException("{$path}.source_bbox must be an array");
            }

            $sourceBbox = array_map(
                static fn (mixed $value): float => is_numeric($value)
                    ? (float) $value
                    : throw new \InvalidArgumentException("{$path}.source_bbox must contain only numbers"),
                array_values($payload['source_bbox']),
            );
        }

        return new self(
            value: $payload['value'],
            confidence: (float) $payload['confidence'],
            sourceBbox: $sourceBbox,
        );
    }

    /**
     * @return array{value: string, confidence: float, source_bbox?: list<float>}
     */
    public function toArray(): array
    {
        $data = [
            'value' => $this->value,
            'confidence' => $this->confidence,
        ];

        if ($this->sourceBbox !== null) {
            $data['source_bbox'] = $this->sourceBbox;
        }

        return $data;
    }
}
