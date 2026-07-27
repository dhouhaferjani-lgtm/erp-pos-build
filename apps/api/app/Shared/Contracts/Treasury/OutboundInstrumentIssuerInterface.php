<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury;

use App\Shared\Contracts\Treasury\DTOs\OutboundInstrumentIssueData;
use App\Shared\Contracts\Treasury\DTOs\OutboundInstrumentIssueResult;

interface OutboundInstrumentIssuerInterface
{
    public function issue(OutboundInstrumentIssueData $data): OutboundInstrumentIssueResult;

    public function assertReplaceable(string $instrumentId, string $tenantId, string $companyId): void;
}
