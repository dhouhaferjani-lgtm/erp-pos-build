<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\DTO;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ExtractedLineData extends Data
{
    public function __construct(
        public ExtractedFieldData $description,
        public ?ExtractedFieldData $supplierRef,
        public ExtractedFieldData $quantity,
        public ?ExtractedFieldData $unitPrice,
        public ?ExtractedFieldData $taxRate,
        public ?ExtractedFieldData $lineTotal,
        public ?ExtractedFieldData $batchNumber,
        public ?ExtractedFieldData $expiryDate,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, int $index): self
    {
        $path = "lines.{$index}";

        return new self(
            description: self::requiredField($payload, 'description', "{$path}.description"),
            supplierRef: self::optionalField($payload, 'supplier_ref', "{$path}.supplier_ref"),
            quantity: self::requiredField($payload, 'quantity', "{$path}.quantity"),
            unitPrice: self::optionalField($payload, 'unit_price', "{$path}.unit_price"),
            taxRate: self::optionalField($payload, 'tax_rate', "{$path}.tax_rate"),
            lineTotal: self::optionalField($payload, 'line_total', "{$path}.line_total"),
            batchNumber: self::optionalField($payload, 'batch_number', "{$path}.batch_number"),
            expiryDate: self::optionalField($payload, 'expiry_date', "{$path}.expiry_date"),
        );
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description->toArray(),
            'supplier_ref' => $this->supplierRef?->toArray(),
            'quantity' => $this->quantity->toArray(),
            'unit_price' => $this->unitPrice?->toArray(),
            'tax_rate' => $this->taxRate?->toArray(),
            'line_total' => $this->lineTotal?->toArray(),
            'batch_number' => $this->batchNumber?->toArray(),
            'expiry_date' => $this->expiryDate?->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function requiredField(array $payload, string $key, string $path): ExtractedFieldData
    {
        if (! array_key_exists($key, $payload) || ! is_array($payload[$key])) {
            throw new \InvalidArgumentException("{$path} is required");
        }

        /** @var array<string, mixed> $field */
        $field = $payload[$key];

        return ExtractedFieldData::fromPayload($field, $path);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function optionalField(array $payload, string $key, string $path): ?ExtractedFieldData
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        if (! is_array($payload[$key])) {
            throw new \InvalidArgumentException("{$path} must be an object or null");
        }

        /** @var array<string, mixed> $field */
        $field = $payload[$key];

        return ExtractedFieldData::fromPayload($field, $path);
    }
}
