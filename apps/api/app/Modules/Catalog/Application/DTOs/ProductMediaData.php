<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ProductMediaData extends Data
{
    /**
     * @param  array<int, MediaAttachmentData>  $media
     */
    public function __construct(
        public ?string $primary_image_url,
        public array $media,
    ) {}

    public static function makeEmpty(): self
    {
        return new self(null, []);
    }
}
