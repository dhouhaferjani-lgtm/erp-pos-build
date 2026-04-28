<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Application\DTOs;

use App\Modules\Workshop\Technician\Domain\ValueObjects\AvailabilityResult;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AvailabilityResultData extends Data
{
    public function __construct(
        public string $status, // yes | no | partial
        public string $reason,
    ) {}

    public static function fromResult(AvailabilityResult $r): self
    {
        return new self(status: $r->status, reason: $r->reason);
    }
}
