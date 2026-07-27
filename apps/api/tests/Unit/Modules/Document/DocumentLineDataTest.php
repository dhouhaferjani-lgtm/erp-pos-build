<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Document;

use App\Modules\Document\Application\DTOs\DocumentLineData;
use App\Modules\Document\Domain\DocumentLine;
use PHPUnit\Framework\TestCase;

final class DocumentLineDataTest extends TestCase
{
    public function test_quantities_use_canonical_scale_while_money_uses_currency_scale(): void
    {
        $data = DocumentLineData::fromModel($this->line([
            'quantity' => '0.1250',
            'free_quantity' => '1.5000',
            'quantity_delivered' => '2.5000',
            'quantity_received' => '3.2500',
            'free_quantity_received' => '0.7500',
            'unit_price' => '12.345',
            'discount_amount' => '1.234',
            'line_total' => '11.111',
        ]), 2);

        self::assertSame('0.1250', $data->quantity);
        self::assertSame('1.5000', $data->free_quantity);
        self::assertSame('2.5000', $data->quantity_delivered);
        self::assertSame('3.2500', $data->quantity_received);
        self::assertSame('0.7500', $data->free_quantity_received);
        self::assertSame('12.34', $data->unit_price);
        self::assertSame('1.23', $data->discount_amount);
        self::assertSame('11.11', $data->line_total);
    }

    public function test_missing_quantity_counters_default_to_canonical_zero(): void
    {
        $data = DocumentLineData::fromModel($this->line([
            'quantity_delivered' => null,
            'quantity_received' => null,
            'free_quantity_received' => null,
        ]), 2);

        self::assertSame('0.0000', $data->quantity_delivered);
        self::assertSame('0.0000', $data->quantity_received);
        self::assertSame('0.0000', $data->free_quantity_received);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function line(array $overrides = []): DocumentLine
    {
        $line = new DocumentLine;
        $line->forceFill([
            'id' => 'line-1',
            'document_id' => 'document-1',
            'product_id' => null,
            'line_number' => 1,
            'description' => 'Precision line',
            'quantity' => '1.0000',
            'free_quantity' => '0.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'free_quantity_received' => '0.0000',
            'unit_price' => '10.000',
            'discount_percent' => null,
            'discount_amount' => null,
            'tax_rate' => null,
            'line_total' => '10.000',
            'price_entry_mode' => 'unit',
            'is_bonus_line' => false,
            'notes' => null,
            'designation_default_snapshot' => null,
            ...$overrides,
        ]);

        return $line;
    }
}
