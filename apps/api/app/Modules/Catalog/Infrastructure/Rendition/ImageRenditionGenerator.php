<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Rendition;

use App\Modules\Catalog\Domain\Contracts\RenditionGeneratorInterface;
use RuntimeException;

/**
 * GD-based WebP rendition generator.
 *
 * The resize-and-encode algorithm is lifted verbatim from
 * Product\Application\Services\ImageVariantService::resizeAndEncode() so that
 * Task 15 can delete that class without losing any behaviour.
 */
final class ImageRenditionGenerator implements RenditionGeneratorInterface
{
    private const WEBP_QUALITY = 80;

    /**
     * {@inheritDoc}
     */
    public function generate(string $bytes, int $maxWidth): string
    {
        $source = @imagecreatefromstring($bytes);
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
