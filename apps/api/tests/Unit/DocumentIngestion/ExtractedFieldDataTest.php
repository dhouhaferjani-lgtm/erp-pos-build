<?php

declare(strict_types=1);

namespace Tests\Unit\DocumentIngestion;

use App\Modules\DocumentIngestion\Application\DTO\ExtractedFieldData;
use PHPUnit\Framework\TestCase;

final class ExtractedFieldDataTest extends TestCase
{
    public function test_accepts_a_null_source_bbox(): void
    {
        // erp-ml emits source_bbox: list[float] | None. A real (photographed,
        // handwritten) invoice yields null bboxes for fields it cannot localize;
        // that must not fail the whole extraction.
        $field = ExtractedFieldData::fromPayload(
            ['value' => 'COMPANY NAME', 'confidence' => 0.3, 'source_bbox' => null],
            'supplier.name',
        );

        $this->assertSame('COMPANY NAME', $field->value);
        $this->assertSame(0.3, $field->confidence);
        $this->assertNull($field->sourceBbox);
    }

    public function test_accepts_a_missing_source_bbox(): void
    {
        $field = ExtractedFieldData::fromPayload(
            ['value' => 'ITEM01', 'confidence' => 0.9],
            'lines.0.description',
        );

        $this->assertNull($field->sourceBbox);
    }

    public function test_parses_a_present_numeric_source_bbox(): void
    {
        $field = ExtractedFieldData::fromPayload(
            ['value' => '8.500', 'confidence' => 0.99, 'source_bbox' => [0.55, 0.24, 0.7, 0.27]],
            'lines.0.unit_price',
        );

        $this->assertSame([0.55, 0.24, 0.7, 0.27], $field->sourceBbox);
    }

    public function test_rejects_a_non_array_non_null_source_bbox(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('supplier.name.source_bbox must be an array');

        ExtractedFieldData::fromPayload(
            ['value' => 'x', 'confidence' => 0.5, 'source_bbox' => 'nope'],
            'supplier.name',
        );
    }
}
