<?php

declare(strict_types=1);

namespace Tests\Unit\PlatformIntegration;

use App\Modules\PlatformIntegration\Domain\Services\BarcodeNormalizer;
use PHPUnit\Framework\TestCase;

final class BarcodeNormalizerTest extends TestCase
{
    private BarcodeNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new BarcodeNormalizer;
    }

    public function test_normalizes_supported_barcode_formats(): void
    {
        $this->assertSame('3017620422003', $this->normalizer->normalize('3017620422003'));
        $this->assertSame('3017620422003', $this->normalizer->normalize(' 3017-6204-22003 '));
        $this->assertSame('0036000291452', $this->normalizer->normalize('036000291452'));
        $this->assertSame('96385074', $this->normalizer->normalize('96385074'));
        $this->assertSame('ABC123XYZ', $this->normalizer->normalize('ABC123XYZ'));
    }

    public function test_returns_null_for_empty_or_invalid_ean13_values(): void
    {
        $this->assertNull($this->normalizer->normalize('!!!'));
        $this->assertNull($this->normalizer->normalize('3017620422004'));
    }
}
