<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum BankStatementStatus: string
{
    case Imported = 'imported';
    case Reconciling = 'reconciling';
    case Reconciled = 'reconciled';
    case Voided = 'voided';

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return false;
        }

        return match ($this) {
            self::Imported => in_array($next, [self::Reconciling, self::Voided], true),
            self::Reconciling => in_array($next, [self::Reconciled, self::Voided], true),
            self::Reconciled => $next === self::Reconciling,
            self::Voided => false,
        };
    }
}
