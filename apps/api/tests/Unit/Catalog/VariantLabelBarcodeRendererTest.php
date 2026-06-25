<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Modules\Catalog\Application\Services\VariantLabelBarcodeRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\TestCase;

/**
 * Task A1 — barcode PNG renderer + symbology selection + dompdf render PoC.
 *
 * Pure unit test (no DB): the renderer selects EAN-13 for valid GTINs and
 * falls back to Code128 otherwise, and emits a base64 PNG data URI.
 */
class VariantLabelBarcodeRendererTest extends TestCase
{
    public function test_selects_ean13_for_valid_gtin(): void
    {
        // 5012345678900 — valid EAN-13 (check digit 0).
        $result = (new VariantLabelBarcodeRenderer)->render('5012345678900');

        $this->assertSame('ean13', $result->symbology);
        $this->assertStringStartsWith('data:image/png;base64,', $result->dataUri);
    }

    public function test_pads_upc_a_12_to_ean13(): void
    {
        // 012345678905 — left-pads to 0012345678905, a valid EAN-13.
        $result = (new VariantLabelBarcodeRenderer)->render('012345678905');

        $this->assertSame('ean13', $result->symbology);
        $this->assertStringStartsWith('data:image/png;base64,', $result->dataUri);
    }

    public function test_falls_back_to_code128_for_alpha_sku(): void
    {
        $result = (new VariantLabelBarcodeRenderer)->render('TSHIRT-S-BLACK');

        $this->assertSame('code128', $result->symbology);
        $this->assertStringStartsWith('data:image/png;base64,', $result->dataUri);
    }

    public function test_invalid_13_digit_check_digit_uses_code128(): void
    {
        // 5012345678901 — valid value would end in 0, so this check digit is wrong.
        $result = (new VariantLabelBarcodeRenderer)->render('5012345678901');

        $this->assertSame('code128', $result->symbology);
        $this->assertStringStartsWith('data:image/png;base64,', $result->dataUri);
    }

    public function test_symbology_for_returns_without_rendering(): void
    {
        $renderer = new VariantLabelBarcodeRenderer;

        $this->assertSame('code128', $renderer->symbologyFor('TSHIRT-S-BLACK'));
        $this->assertSame('ean13', $renderer->symbologyFor('5012345678900'));
    }

    public function test_can_encode_rejects_non_ascii_and_accepts_ascii_and_ean(): void
    {
        $renderer = new VariantLabelBarcodeRenderer;

        $this->assertFalse($renderer->canEncode('CAFÉ'));
        $this->assertTrue($renderer->canEncode('TSHIRT-S'));
        $this->assertTrue($renderer->canEncode('5012345678900'));
    }

    public function test_png_barcode_embeds_into_dompdf(): void
    {
        $uri = (new VariantLabelBarcodeRenderer)->render('TSHIRT-S-BLACK')->dataUri;
        $html = '<html><body><img src="'.$uri.'" style="height:12mm"></body></html>';
        $pdf = Pdf::loadHTML($html)->output();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1500, strlen($pdf));
    }
}
