<?php

declare(strict_types=1);

namespace App\Shared\DTOs\Media;

final class MediaAttachmentView
{
    public function __construct(
        public readonly string $id,
        public readonly string $originalFilename,
        public readonly string $mimeType,
        public readonly int $fileSize,
        public readonly ?string $caption,
        public readonly ?string $uploadedById,
        public readonly ?string $uploadedByName,
        public readonly ?string $createdAt,
        public readonly bool $isImage,
        public readonly bool $isPdf,
    ) {}
}
