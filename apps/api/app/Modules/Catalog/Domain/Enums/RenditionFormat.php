<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum RenditionFormat: string
{
    case Webp = 'WEBP';
    case Jpeg = 'JPEG';
}
