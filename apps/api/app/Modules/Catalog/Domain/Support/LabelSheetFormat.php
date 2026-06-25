<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Support;

/**
 * Registry of supported label sheet/roll layouts.
 *
 * Each format describes the physical geometry (page size, label dimensions,
 * grid, margins, gutters) the PDF renderer needs to position labels. All
 * lengths are millimetres. `pageSize` is either 'a4' (full A4 sheet rendered
 * as a rows×cols grid) or 'label_4x6' (a single label-roll page).
 */
final readonly class LabelSheetFormat
{
    public function __construct(
        public string $key,
        public string $label,
        public string $pageSize,
        public float $labelWidthMm,
        public float $labelHeightMm,
        public int $rows,
        public int $cols,
        public float $marginTopMm,
        public float $marginLeftMm,
        public float $gutterXmm,
        public float $gutterYmm,
    ) {}

    /**
     * @return array<int, self>
     */
    public static function all(): array
    {
        return [
            new self(
                key: 'avery_l7160',
                label: 'Avery L7160 (A4, 21 per sheet)',
                pageSize: 'a4',
                labelWidthMm: 63.5,
                labelHeightMm: 38.1,
                rows: 7,
                cols: 3,
                marginTopMm: 15.0,
                marginLeftMm: 7.2,
                gutterXmm: 2.5,
                gutterYmm: 0.0,
            ),
            new self(
                key: 'label_4x6',
                label: 'Label roll 4x6 in (single)',
                pageSize: 'label_4x6',
                labelWidthMm: 101.6,
                labelHeightMm: 152.4,
                rows: 1,
                cols: 1,
                marginTopMm: 0.0,
                marginLeftMm: 0.0,
                gutterXmm: 0.0,
                gutterYmm: 0.0,
            ),
            new self(
                key: 'grid_custom',
                label: 'Custom grid (A4, 36 per sheet)',
                pageSize: 'a4',
                labelWidthMm: 50.0,
                labelHeightMm: 30.0,
                rows: 9,
                cols: 4,
                marginTopMm: 10.0,
                marginLeftMm: 5.0,
                gutterXmm: 2.0,
                gutterYmm: 2.0,
            ),
        ];
    }

    public static function find(string $key): ?self
    {
        foreach (self::all() as $format) {
            if ($format->key === $key) {
                return $format;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $f): string => $f->key, self::all());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'page_size' => $this->pageSize,
            'label_width_mm' => $this->labelWidthMm,
            'label_height_mm' => $this->labelHeightMm,
            'rows' => $this->rows,
            'cols' => $this->cols,
            'margin_top_mm' => $this->marginTopMm,
            'margin_left_mm' => $this->marginLeftMm,
            'gutter_x_mm' => $this->gutterXmm,
            'gutter_y_mm' => $this->gutterYmm,
        ];
    }
}
