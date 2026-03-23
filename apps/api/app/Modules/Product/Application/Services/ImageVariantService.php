<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use RuntimeException;

class ImageVariantService
{
    /** @var array<string, int> Variant name → max width in pixels */
    public const VARIANTS = [
        'sm' => 150,
        'md' => 400,
    ];

    public const WEBP_QUALITY = 80;

    /**
     * Derive the S3 path for a variant from the original storage path.
     */
    public static function variantPath(string $originalPath, string $variant): string
    {
        $info = pathinfo($originalPath);
        $dir = isset($info['dirname']) ? $info['dirname'].'/' : '';

        return $dir.$info['filename'].'_'.$variant.'.webp';
    }

    /**
     * @return array<string, string>
     */
    public static function allVariantPaths(string $originalPath): array
    {
        $paths = [];
        foreach (array_keys(self::VARIANTS) as $variant) {
            $paths[$variant] = self::variantPath($originalPath, $variant);
        }

        return $paths;
    }

    /**
     * Resize image data to a max width and encode as WebP.
     * Does not upscale.
     */
    public function resizeAndEncode(string $imageData, int $maxWidth): string
    {
        $source = @imagecreatefromstring($imageData);
        if ($source === false) {
            throw new RuntimeException('Failed to create image from data');
        }

        $origWidth = imagesx($source);
        $origHeight = imagesy($source);

        if ($origWidth <= $maxWidth) {
            $newWidth = $origWidth;
            $newHeight = $origHeight;
        } else {
            $newWidth = $maxWidth;
            $newHeight = (int) round($origHeight * ($maxWidth / $origWidth));
        }

        $newWidth = max(1, $newWidth);
        $newHeight = max(1, $newHeight);

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
        imagedestroy($source);

        ob_start();
        imagewebp($resized, null, self::WEBP_QUALITY);
        $webpData = ob_get_clean();
        imagedestroy($resized);

        if ($webpData === false || $webpData === '') {
            throw new RuntimeException('Failed to encode image as WebP');
        }

        return $webpData;
    }
}
