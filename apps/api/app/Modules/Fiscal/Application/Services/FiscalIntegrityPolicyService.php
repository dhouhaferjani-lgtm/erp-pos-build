<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Domain\Enums\FiscalIntegrityAnomaly;
use App\Modules\Fiscal\Domain\Enums\FiscalIntegrityPolicyAction;

final class FiscalIntegrityPolicyService
{
    public function actionFor(FiscalIntegrityAnomaly $anomaly, string $countryCode): FiscalIntegrityPolicyAction
    {
        $country = strtoupper($countryCode);

        if (! in_array($country, ['FR', 'TN'], true)) {
            $country = 'TN';
        }

        return match ($anomaly) {
            FiscalIntegrityAnomaly::CanonicalHashMismatch,
            FiscalIntegrityAnomaly::CanonicalParseFailure,
            FiscalIntegrityAnomaly::DeviceTimeAnomaly => FiscalIntegrityPolicyAction::AcceptAndQuarantine,
            FiscalIntegrityAnomaly::SequenceGap => FiscalIntegrityPolicyAction::RequireAcknowledgment,
            FiscalIntegrityAnomaly::SignatureInvalid => FiscalIntegrityPolicyAction::Inactive,
        };
    }
}
