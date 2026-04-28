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
    ) {}
}
