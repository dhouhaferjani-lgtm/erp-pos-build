<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum MediaRole: string
{
    case Primary = 'PRIMARY';
    case Gallery = 'GALLERY';
    case Datasheet = 'DATASHEET';
    case Manual = 'MANUAL';
    case VideoPoster = 'VIDEO_POSTER';
    case Spin = 'SPIN';
    case Swatch = 'SWATCH';
}
