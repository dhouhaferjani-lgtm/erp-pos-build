<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Application\DTOs\VariantLabelData;
use App\Modules\Catalog\Domain\Support\LabelSheetFormat;
use Barryvdh\DomPDF\Facade\Pdf;
use InvalidArgumentException;

/**
 * Renders a batch of ready-to-print variant labels into a PDF binary, laid out
 * on a chosen {@see LabelSheetFormat} grid.
 *
 * Each label is expanded into `quantity` cells; `startCell` blank cells are
 * prepended so a user can resume printing on a partially-used sheet. dompdf has
 * no CSS grid support, so the Blade template lays cells out as a fixed-layout
 * HTML <table>.
 */
final class VariantLabelPdfService
{
    private const MM_TO_PT = 2.83465;

    public function __construct(
        private readonly VariantLabelBarcodeRenderer $renderer,
    ) {}

    /**
     * @param  array<int, VariantLabelData>  $labels
     */
    public function render(string $formatKey, array $labels, int $startCell, string $shopName): string
    {
        $format = LabelSheetFormat::find($formatKey);

        if ($format === null) {
            throw new InvalidArgumentException("Unknown label sheet format: {$formatKey}");
        }

        $cells = $this->buildCells($format, $labels, $startCell, $shopName);

        $paper = $format->pageSize === 'a4'
            ? 'a4'
            : [0.0, 0.0, $this->mmToPt($format->labelWidthMm), $this->mmToPt($format->labelHeightMm)];

        return Pdf::loadView('catalog.variant-labels', [
            'format' => $format,
            'cells' => $cells,
            'shopName' => $shopName,
        ])->setPaper($paper)->output();
    }

    /**
     * Expand labels (× quantity) into a flat, ordered list of cell descriptors,
     * prefixed with `$startCell` blank cells.
     *
     * @param  array<int, VariantLabelData>  $labels
     * @return array<int, array{blank: bool, product_name: string, name_suffix: string, effective_price: string, barcode_data_uri: string, barcode_value: string, shop_name: string}>
     */
    private function buildCells(LabelSheetFormat $format, array $labels, int $startCell, string $shopName): array
    {
        $cells = [];

        for ($i = 0; $i < max(0, $startCell); $i++) {
            $cells[] = $this->blankCell();
        }

        foreach ($labels as $label) {
            $dataUri = $this->renderer->render($label->barcode_value)->dataUri;

            for ($q = 0; $q < $label->quantity; $q++) {
                $cells[] = [
                    'blank' => false,
                    'product_name' => $label->product_name,
                    'name_suffix' => $label->name_suffix,
                    'effective_price' => $label->effective_price,
                    'barcode_data_uri' => $dataUri,
                    'barcode_value' => $label->barcode_value,
                    'shop_name' => $shopName,
                ];
            }
        }

        return $cells;
    }

    /**
     * @return array{blank: bool, product_name: string, name_suffix: string, effective_price: string, barcode_data_uri: string, barcode_value: string, shop_name: string}
     */
    private function blankCell(): array
    {
        return [
            'blank' => true,
            'product_name' => '',
            'name_suffix' => '',
            'effective_price' => '',
            'barcode_data_uri' => '',
            'barcode_value' => '',
            'shop_name' => '',
        ];
    }

    private function mmToPt(float $mm): float
    {
        return $mm * self::MM_TO_PT;
    }
}
