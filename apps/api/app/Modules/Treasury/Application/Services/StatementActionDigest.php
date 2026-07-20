<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use BackedEnum;
use DomainException;

final class StatementActionDigest
{
    /** @param array<string, mixed> $params */
    public static function make(MatchActionType $action, BankStatementLine $line, array $params): string
    {
        return hash('sha256', json_encode(self::normalize([
            'action_type' => $action->value,
            'line_id' => $line->id,
            'line_direction' => $line->direction->value,
            'line_amount' => $line->amount,
            'value_date' => $line->value_date->toDateString(),
            'payment_repository_id' => $line->payment_repository_id,
            'params' => $params,
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if (is_float($value)) {
            throw new DomainException('Statement action money and quantity parameters must be strings, never floats.');
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value);

        return array_map(self::normalize(...), $value);
    }
}
