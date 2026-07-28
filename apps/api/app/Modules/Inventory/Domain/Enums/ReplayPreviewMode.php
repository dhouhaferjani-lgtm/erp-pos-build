<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum ReplayPreviewMode: string
{
    case TimestampReplay = 'timestamp_replay';
    case LegacyDelta = 'legacy_delta';
}
