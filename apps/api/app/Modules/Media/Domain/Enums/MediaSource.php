<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\Enums;

enum MediaSource: string
{
    case Upload = 'UPLOAD';
    case ExternalUrl = 'EXTERNAL_URL';
}
