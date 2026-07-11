<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use DomainException;

final class MissingInstrumentAccountException extends DomainException
{
    public static function forPurpose(InstrumentAccountPurpose $purpose, string $companyId): self
    {
        return new self("Missing instrument account '{$purpose->value}' for company '{$companyId}'.");
    }
}
