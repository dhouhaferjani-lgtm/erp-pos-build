<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum PlatformLinkStatus: string
{
    case Linked = 'linked';
    case Unlinked = 'unlinked';
    case PendingMatch = 'pending_match';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Linked => 'Linked',
            self::Unlinked => 'Unlinked',
            self::PendingMatch => 'Pending Match',
            self::Rejected => 'Rejected',
        };
    }
}
