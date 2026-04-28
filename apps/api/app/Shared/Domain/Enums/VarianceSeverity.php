<?php

declare(strict_types=1);

namespace App\Shared\Domain\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum VarianceSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    private function weight(): int
    {
        return match ($this) {
            self::Info => 1,
            self::Warning => 2,
            self::Critical => 3,
        };
    }

    /**
     * Given the company's configured minimum-severity-to-email threshold, should this
     * severity trigger an email?
     */
    public static function shouldEmailAt(string $threshold, self $severity): bool
    {
        if ($threshold === 'none') {
            return false;
        }
        $thresholdEnum = self::from($threshold);

        return $severity->weight() >= $thresholdEnum->weight();
    }
}
