<?php

declare(strict_types=1);

namespace Tests\Unit\DocumentIngestion;

use App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData;
use InvalidArgumentException;
use Tests\TestCase;

final class ExtractionResultDataTest extends TestCase
{
    public function test_invoice_fixture_hydrates_and_round_trips_without_numeric_value_coercion(): void
    {
        $payload = $this->fixture('extraction_invoice_fr.json');

        $data = ExtractionResultData::from($payload);

        $this->assertSame($payload, $data->toArray());
        $this->assertSame('12.500', $data->lines[0]->unitPrice?->value);
    }

    public function test_delivery_note_fixture_hydrates_without_prices(): void
    {
        $payload = $this->fixture('extraction_bl_fr.json');

        $data = ExtractionResultData::from($payload);

        $this->assertSame($payload, $data->toArray());
        $this->assertNull($data->lines[0]->unitPrice);
    }

    public function test_numeric_field_values_are_rejected_instead_of_cast_to_strings(): void
    {
        $payload = $this->fixture('extraction_invoice_fr.json');
        $payload['lines'][0]['unit_price']['value'] = 12.5;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lines.0.unit_price.value must be a string');

        ExtractionResultData::from($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $json = file_get_contents(base_path("tests/Fixtures/document_ingestion/{$name}"));
        $this->assertIsString($json);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
