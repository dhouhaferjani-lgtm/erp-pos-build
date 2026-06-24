<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use App\Modules\Media\Domain\Media\MediaRendition;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MediaRenditionData extends Data
{
    public function __construct(
        public string $id,
        public string $media_asset_id,
        public string $name,
        public string $format,
        public ?string $storage_disk,
        public ?string $storage_path,
        public ?int $width,
        public ?int $height,
        public ?int $file_size,
    ) {}

    public static function fromModel(MediaRendition $rendition): self
    {
        return new self(
            id: $rendition->id,
            media_asset_id: $rendition->media_asset_id,
            name: $rendition->name->value,
            format: $rendition->format->value,
            storage_disk: $rendition->storage_disk,
            storage_path: $rendition->storage_path,
            width: $rendition->width,
            height: $rendition->height,
            file_size: $rendition->file_size,
        );
    }
}
