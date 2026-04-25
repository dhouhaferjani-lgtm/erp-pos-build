<?php

declare(strict_types=1);

namespace App\Shared\Domain\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum VarianceDirection: string
{
    case Over = 'over';
    case Under = 'under';
    case Balanced = 'balanced';

    /**
     * Decide direction from a signed decimal-string variance amount.
     * Uses bccomp so scale-independent.
     *
     * @param  numeric-string  $amount
     */
    public static function fromSignedAmount(string $amount): self
    {
        $cmp = bccomp($amount, '0', 6);

        return match (true) {
            $cmp === 0 => self::Balanced,
            $cmp > 0 => self::Over,
            default => self::Under,
        };
    }
}
