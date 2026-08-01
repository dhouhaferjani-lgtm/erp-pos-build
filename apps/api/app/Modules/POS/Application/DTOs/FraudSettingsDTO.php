<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class FraudSettingsDTO extends Data
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $cashVarianceOverSoft,
        public readonly string $cashVarianceOverHard,
        public readonly string $cashVarianceUnderSoft,
        public readonly string $cashVarianceUnderHard,
        public readonly bool $requireBlindCashCount,
        public readonly bool $requireManagerPinAboveHard,
        public readonly string $cashVarianceEmailSeverity,
        /**
         * Lane C M2 — max refunds one terminal may author inside a single
         * shift while it still holds unsynced fiscal events.
         */
        public readonly int $offlineRefundCountCeiling,
        /** Lane C M2 — the same bound expressed as cumulative payout value. */
        public readonly string $offlineRefundValueCeiling,
        /**
         * Lane C M3 — a refund above this amount may only be authored while
         * the device is online (server-verified manager PIN).
         */
        public readonly string $onlineRequiredRefundThreshold,
    ) {}
}
