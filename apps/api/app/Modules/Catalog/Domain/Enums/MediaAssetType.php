<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum MediaAssetType: string
{
    case Image = 'IMAGE';
    case Document = 'DOCUMENT';
    case Video = 'VIDEO';
    case ExternalVideo = 'EXTERNAL_VIDEO';
    case Spin360 = 'SPIN_360';
}
