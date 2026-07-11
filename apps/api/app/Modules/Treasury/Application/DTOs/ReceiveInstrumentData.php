<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;

final readonly class ReceiveInstrumentData
{
    /** @param numeric-string $amount */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $paymentMethodId,
        public InstrumentKind $kind,
        public InstrumentDirection $direction,
        public InstrumentOrigin $origin,
        public string $reference,
        public string $amount,
        public string $currency,
        public string $repositoryId,
        public ?string $partnerId = null,
        public ?string $drawerName = null,
        public ?string $maturityDate = null,
        public ?string $receivedDate = null,
        public ?string $bankId = null,
        public ?string $bankName = null,
        public ?string $bankBranch = null,
        public ?string $bankAccount = null,
        public ?string $idempotencyKey = null,
        public bool $needsDetails = false,
        public ?string $createdBy = null,
    ) {}
}
