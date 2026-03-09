<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Domain\Enums;

enum PlatformLookupStatus: string
{
    case Found = 'found';
    case NotFound = 'not_found';
    case Error = 'error';
    case Cached = 'cached';

    public function label(): string
    {
        return match ($this) {
            self::Found => 'Found',
            self::NotFound => 'Not Found',
            self::Error => 'Error',
            self::Cached => 'Cached',
        };
    }
}
