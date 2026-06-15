<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exceptions;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class DuplicateBarcodeException
{
    /** True when $e is a PG unique violation (23505) on the given constraint name. */
    public static function isViolationOf(QueryException $e, string $constraint): bool
    {
        return (($e->errorInfo[0] ?? null) === '23505') && str_contains($e->getMessage(), $constraint);
    }

    public static function asValidation(string $message): ValidationException
    {
        return ValidationException::withMessages(['barcode' => [$message]]);
    }
}
