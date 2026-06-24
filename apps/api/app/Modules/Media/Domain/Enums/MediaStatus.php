<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\Enums;

enum MediaStatus: string
{
    case Uploaded = 'UPLOADED';
    case Processing = 'PROCESSING';
    case Ready = 'READY';
    case Failed = 'FAILED';
}
