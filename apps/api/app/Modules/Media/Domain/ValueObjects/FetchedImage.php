<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\ValueObjects;

final class FetchedImage
{
    public function __construct(
        public readonly string $tempPath,
        public readonly string $mime,
        public readonly string $filename,
        public readonly int $byteSize,
    ) {}
}
