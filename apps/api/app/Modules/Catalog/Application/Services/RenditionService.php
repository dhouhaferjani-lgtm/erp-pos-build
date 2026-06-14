<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Domain\Contracts\MediaStorageInterface;
use App\Modules\Catalog\Domain\Contracts\RenditionGeneratorInterface;
use App\Modules\Catalog\Domain\Enums\RenditionFormat;
use App\Modules\Catalog\Domain\Enums\RenditionName;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaRendition;
use RuntimeException;

/**
 * Generates WebP renditions for an uploaded MediaAsset.
 *
 * Reads the original bytes via MediaStorageInterface, runs the GD encoder for
 * each target, writes the result back to storage, and creates a MediaRendition
 * row per target.
 *
 * ZOOM is intentionally excluded — it references the original file directly and
 * does not require a separate rendition.
 */
final class RenditionService
{
    /**
     * Rendition name → max width in pixels.
     * ZOOM is not generated here (references the original).
     *
     * @var array<string, int>
     */
    public const TARGETS = [
        RenditionName::Thumbnail->value => 150,
        RenditionName::Small->value => 400,
        RenditionName::Web->value => 1000,
    ];

    public function __construct(
        private readonly RenditionGeneratorInterface $generator,
        private readonly MediaStorageInterface $storage,
    ) {}

    /**
     * Generate all TARGETS renditions for the given asset.
     *
     * Reads the original from `$asset->storage_disk` / `$asset->storage_path`,
     * encodes each target as WebP, writes it to
     * `{dirname(original)}/{name-lowercased}.webp`, and persists a
     * MediaRendition row.
     */
    public function generate(MediaAsset $asset): void
    {
        $originalBytes = $this->storage->get(
            (string) $asset->storage_disk,
            (string) $asset->storage_path,
        );

        if ($originalBytes === null) {
            throw new RuntimeException(
                "Original file not found for MediaAsset {$asset->id} at "
                ."{$asset->storage_disk}:{$asset->storage_path}",
            );
        }

        $dir = dirname((string) $asset->storage_path);

        foreach (self::TARGETS as $nameValue => $maxWidth) {
            $webpBytes = $this->generator->generate($originalBytes, $maxWidth);

            $renditionName = RenditionName::from($nameValue);
            $filename = strtolower($renditionName->value).'.webp';
            $renditionPath = $dir.'/'.$filename;

            $this->storage->put(
                (string) $asset->storage_disk,
                $renditionPath,
                $webpBytes,
            );

            [$width, $height] = $this->imageDimensions($webpBytes);

            // Idempotent: keyed on the (media_asset_id, name, format) unique index
            // so a re-run (e.g. a queue retry after a partial success, or a
            // regenerate command) updates the existing row instead of hitting a
            // unique-constraint violation. The storage path is deterministic, so
            // put() above already overwrites the file in place.
            MediaRendition::updateOrCreate(
                [
                    'media_asset_id' => $asset->id,
                    'name' => $renditionName,
                    'format' => RenditionFormat::Webp,
                ],
                [
                    'tenant_id' => $asset->tenant_id,
                    'storage_disk' => $asset->storage_disk,
                    'storage_path' => $renditionPath,
                    'width' => $width,
                    'height' => $height,
                    'file_size' => strlen($webpBytes),
                ],
            );
        }
    }

    /**
     * Return [width, height] of the given image bytes using GD.
     * Returns [0, 0] if GD cannot parse the data (non-fatal — dimensions are
     * informational metadata, not a hard contract).
     *
     * @return array{int, int}
     */
    private function imageDimensions(string $bytes): array
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return [0, 0];
        }

        $w = imagesx($img);
        $h = imagesy($img);
        imagedestroy($img);

        return [$w, $h];
    }
}
