<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use Picqer\Barcode\BarcodeGeneratorPNG;

final readonly class RenderedBarcode
{
    public function __construct(public string $symbology, public string $dataUri) {}
}

final class VariantLabelBarcodeRenderer
{
    public function render(string $value): RenderedBarcode
    {
        [$symbology, $type, $encoded] = $this->select($value);
        $png = (new BarcodeGeneratorPNG)->getBarcode($encoded, $type);

        return new RenderedBarcode($symbology, 'data:image/png;base64,'.base64_encode($png));
    }

    public function symbologyFor(string $value): string
    {
        return $this->select($value)[0];
    }

    /** @return array{0:string,1:'C128'|'EAN13',2:string} */
    private function select(string $value): array
    {
        if (preg_match('/^\d{12,13}$/', $value) === 1) {
            $candidate = strlen($value) === 12 ? '0'.$value : $value;
            if (strlen($candidate) === 13 && $this->validEan13($candidate)) {
                return ['ean13', BarcodeGeneratorPNG::TYPE_EAN_13, $candidate];
            }
        }

        return ['code128', BarcodeGeneratorPNG::TYPE_CODE_128, $value];
    }

    private function validEan13(string $code): bool
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $code[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10 === (int) $code[12];
    }
}
