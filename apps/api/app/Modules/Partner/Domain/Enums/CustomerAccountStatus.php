<?php

declare(strict_types=1);

namespace App\Modules\Partner\Domain\Enums;

enum CustomerAccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
    case Disputed = 'disputed';

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return false;
        }

        return match ($this) {
            self::Active => in_array($next, [self::Suspended, self::Disputed], true),
            self::Suspended => in_array($next, [self::Active, self::Closed], true),
            self::Disputed => in_array($next, [self::Active, self::Closed], true),
            self::Closed => false,
        };
    }
}
