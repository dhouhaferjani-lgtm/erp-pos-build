<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use App\Modules\Catalog\Domain\Media\MediaAsset;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MediaAssetData extends Data
{
    public function __construct(
        public string $id,
        public string $tenant_id,
        public string $type,
        public string $source,
        public string $status,
        public ?string $storage_disk,
        public ?string $storage_path,
        public ?string $external_url,
        public ?string $original_filename,
        public ?string $mime_type,
        public ?int $file_size,
        public ?int $width,
        public ?int $height,
        public ?string $title,
    ) {}

    public static function fromModel(MediaAsset $asset): self
    {
        return new self(
            id: $asset->id,
            tenant_id: $asset->tenant_id,
            type: $asset->type->value,
            source: $asset->source->value,
            status: $asset->status->value,
            storage_disk: $asset->storage_disk,
            storage_path: $asset->storage_path,
            external_url: $asset->external_url,
            original_filename: $asset->original_filename,
            mime_type: $asset->mime_type,
            file_size: $asset->file_size,
            width: $asset->width,
            height: $asset->height,
            title: $asset->title,
        );
    }
}
