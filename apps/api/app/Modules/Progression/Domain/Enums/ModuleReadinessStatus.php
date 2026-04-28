<?php

declare(strict_types=1);

namespace App\Modules\Progression\Domain\Enums;

enum ModuleReadinessStatus: string
{
    case Locked = 'locked';
    case Available = 'available';
    case Ready = 'ready';
    case Active = 'active';

    public function canActivate(): bool
    {
        return $this === self::Ready;
    }
}
