<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\DTOs;

final readonly class AuditReconciliationResultData
{
    public function __construct(
        public int $attempted,
        public int $reconciled,
        public int $failed,
        public int $pending,
    ) {}
}
