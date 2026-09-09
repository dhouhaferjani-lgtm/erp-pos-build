<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

use App\Modules\Identity\Domain\Enums\LotActionPermissionDeltaOutcome;

final readonly class LotActionPermissionDeltaResult
{
    public function __construct(
        public readonly LotActionPermissionDeltaOutcome $outcome,
        public readonly string $reason,
    ) {}
}
