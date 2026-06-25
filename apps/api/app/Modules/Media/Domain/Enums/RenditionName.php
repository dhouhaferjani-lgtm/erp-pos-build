<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\Enums;

enum RenditionName: string
{
    case Thumbnail = 'THUMBNAIL';
    case Small = 'SMALL';
    case Web = 'WEB';
    case Zoom = 'ZOOM';
}
