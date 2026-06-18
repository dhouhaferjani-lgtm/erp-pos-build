<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\DTOs\VariantLabelData;
use App\Modules\Catalog\Application\Services\VariantLabelPdfService;
use Tests\TestCase;

/**
 * Task C1 — VariantLabelPdfService renders a label sheet to a PDF binary.
 *
 * No DB required: the service operates purely on VariantLabelData + a format
 * key, so it runs under the SQLite suite.
 */
class VariantLabelPdfServiceTest extends TestCase
{
    private function service(): VariantLabelPdfService
    {
        return app(VariantLabelPdfService::class);
    }

    /**
     * @return array<int, VariantLabelData>
     */
    private function labels(): array
    {
        return [
            new VariantLabelData(
                variant_id: 'v1',
                product_name: 'Tee',
                name_suffix: 'S / Black',
                effective_price: '12.000',
                barcode_value: 'TS-S-BLK',
                symbology: 'code128',
                quantity: 3,
                sku: 'TS-S-BLK',
            ),
        ];
    }

    public function test_renders_avery_sheet_pdf_with_start_offset(): void
    {
        $pdf = $this->service()->render('avery_l7160', $this->labels(), startCell: 2, shopName: 'My Shop');

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1500, strlen($pdf));
    }

    public function test_renders_label_4x6_single_cell_pdf(): void
    {
        $labels = [
            new VariantLabelData(
                variant_id: 'v2',
                product_name: 'Mug',
                name_suffix: 'Large',
                effective_price: '8.000',
                barcode_value: '0123456789012',
                symbology: 'ean13',
                quantity: 1,
                sku: 'MUG-L',
            ),
        ];

        $pdf = $this->service()->render('label_4x6', $labels, startCell: 0, shopName: 'My Shop');

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1500, strlen($pdf));
    }

    public function test_unknown_format_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->render('nope', $this->labels(), startCell: 0, shopName: 'My Shop');
    }
}
