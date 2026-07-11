<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use App\Modules\Treasury\Domain\Enums\AllocationMethod;

final readonly class ApplyPaymentAllocationCommand
{
    /**
     * @param  array<int, array{document_id: string, amount: string}>|null  $manualAllocations
     */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $paymentId,
        public AllocationMethod $allocationMethod,
        public ?string $actorUserId,
        public string $source,
        public ?array $manualAllocations = null,
        public ?string $cashAccountOverrideId = null,
    ) {}
}
