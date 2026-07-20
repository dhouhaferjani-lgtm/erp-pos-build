<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum StatementLineMatchStatus: string
{
    case Unmatched = 'unmatched';
    case Partial = 'partial';
    case Matched = 'matched';
    case ResolvedByCreation = 'resolved_by_creation';
    case Ignored = 'ignored';

    /**
     * @param  numeric-string  $allocationTotal
     * @param  numeric-string  $lineAmount
     */
    public static function derive(
        bool $ignored,
        bool $resolvedByCreation,
        string $allocationTotal,
        string $lineAmount,
        int $scale,
    ): self {
        if ($ignored) {
            return self::Ignored;
        }

        $comparison = bccomp($allocationTotal, $lineAmount, $scale);
        if ($comparison > 0 || bccomp($allocationTotal, '0', $scale) < 0) {
            throw new \InvalidArgumentException('Statement line allocation total cannot exceed the line amount or be negative.');
        }
        if ($comparison === 0) {
            return $resolvedByCreation ? self::ResolvedByCreation : self::Matched;
        }

        return bccomp($allocationTotal, '0', $scale) === 0
            ? self::Unmatched
            : self::Partial;
    }
}
