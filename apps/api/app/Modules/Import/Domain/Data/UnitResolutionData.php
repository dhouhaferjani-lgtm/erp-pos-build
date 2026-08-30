<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

use App\Modules\Import\Domain\Enums\ImportWarningCode;

final readonly class UnitResolutionData
{
    public function __construct(
        public ?string $unitId,
        public ?string $unitCode,
        public ?ImportWarningCode $warning,
    ) {}
}
