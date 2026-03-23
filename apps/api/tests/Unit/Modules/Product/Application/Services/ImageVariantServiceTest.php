<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Product\Application\Services;

use App\Modules\Product\Application\Services\ImageVariantService;
use PHPUnit\Framework\TestCase;

class ImageVariantServiceTest extends TestCase
{
    public function test_variant_path_appends_suffix_and_changes_extension_to_webp(): void
    {
        $original = 'products/tid/pid/abc123.jpg';
        $this->assertSame(
            'products/tid/pid/abc123_sm.webp',
            ImageVariantService::variantPath($original, 'sm')
        );
    }

    public function test_variant_path_works_with_png(): void
    {
        $original = 'products/tid/pid/abc123.png';
        $this->assertSame(
            'products/tid/pid/abc123_md.webp',
            ImageVariantService::variantPath($original, 'md')
        );
    }

    public function test_variant_path_works_with_webp_original(): void
    {
        $original = 'products/tid/pid/abc123.webp';
        $this->assertSame(
            'products/tid/pid/abc123_sm.webp',
            ImageVariantService::variantPath($original, 'sm')
        );
    }

    public function test_all_variant_paths_returns_both_sizes(): void
    {
        $original = 'products/tid/pid/abc123.jpg';
        $paths = ImageVariantService::allVariantPaths($original);
        $this->assertSame([
            'sm' => 'products/tid/pid/abc123_sm.webp',
            'md' => 'products/tid/pid/abc123_md.webp',
        ], $paths);
    }

    public function test_resize_and_encode_produces_valid_webp(): void
    {
        $source = imagecreatetruecolor(800, 600);
        $red = imagecolorallocate($source, 255, 0, 0);
        imagefill($source, 0, 0, $red);
        ob_start();
        imagejpeg($source, null, 90);
        $jpegData = ob_get_clean();
        imagedestroy($source);

        $service = new ImageVariantService;
        $webpData = $service->resizeAndEncode($jpegData, 150);

        $this->assertStringStartsWith('RIFF', $webpData);
        $this->assertStringContainsString('WEBP', substr($webpData, 0, 12));

        $img = imagecreatefromstring($webpData);
        $this->assertSame(150, imagesx($img));
        $this->assertEqualsWithDelta(113, imagesy($img), 1);
        imagedestroy($img);
    }

    public function test_resize_and_encode_does_not_upscale(): void
    {
        $source = imagecreatetruecolor(100, 80);
        ob_start();
        imagejpeg($source, null, 90);
        $jpegData = ob_get_clean();
        imagedestroy($source);

        $service = new ImageVariantService;
        $webpData = $service->resizeAndEncode($jpegData, 150);

        $img = imagecreatefromstring($webpData);
        $this->assertSame(100, imagesx($img));
        $this->assertSame(80, imagesy($img));
        imagedestroy($img);
    }
}
