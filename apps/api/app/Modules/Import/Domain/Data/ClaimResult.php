<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

use App\Modules\Import\Domain\Enums\ImportStatus;

final readonly class ClaimResult
{
    public function __construct(
        public bool $won,
        public ImportStatus $priorStatus,
    ) {}
}
