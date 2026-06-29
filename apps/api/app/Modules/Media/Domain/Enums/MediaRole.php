<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\Enums;

enum MediaRole: string
{
    case Primary = 'PRIMARY';
    case Gallery = 'GALLERY';
    case Datasheet = 'DATASHEET';
    case Manual = 'MANUAL';
    case VideoPoster = 'VIDEO_POSTER';
    case Spin = 'SPIN';
    case Swatch = 'SWATCH';
    /** Scanned source PDF of a supplier invoice or other procurement document. */
    case SourceDocument = 'SOURCE_DOCUMENT';
}
