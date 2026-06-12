<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use App\Modules\Catalog\Domain\Media\MediaAttachment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MediaAttachmentData extends Data
{
    public function __construct(
        public string $id,
        public string $asset_id,
        public string $type,
        public string $role,
        public int $sort_order,
        public ?string $url,
        public ?string $alt,
        public ?string $caption,
    ) {}

    /**
     * PURE mapping — caller resolves $url via MediaUrlResolver (no service-location in a DTO).
     *
     * The mediaAsset relation MUST be loaded before calling this method.
     *
     * @param  MediaAttachment  $a  the attachment with its mediaAsset relation already loaded
     * @param  string|null  $url  the pre-resolved display URL (rendition or external_url)
     */
    public static function fromModel(MediaAttachment $a, ?string $url): self
    {
        $asset = $a->mediaAsset;

        if ($asset === null) {
            throw new \LogicException(
                sprintf('MediaAttachment [%s] must have its mediaAsset relation loaded before mapping to a DTO.', $a->id)
            );
        }

        return new self(
            id: $a->id,
            asset_id: $a->media_asset_id,
            type: $asset->type->value,
            role: $a->role->value,
            sort_order: $a->sort_order,
            url: $url,
            alt: $a->alt,
            caption: $a->caption,
        );
    }
}
