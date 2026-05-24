<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalIntegrityPolicyService;
use App\Modules\Fiscal\Domain\Enums\FiscalIntegrityAnomaly;
use App\Modules\Fiscal\Domain\Enums\FiscalIntegrityPolicyAction;
use Tests\TestCase;

final class FiscalIntegrityPolicyServiceTest extends TestCase
{
    public function test_fr_and_tn_hash_parse_and_time_anomalies_accept_and_quarantine(): void
    {
        $service = new FiscalIntegrityPolicyService;

        foreach (['FR', 'TN'] as $countryCode) {
            foreach ([
                FiscalIntegrityAnomaly::CanonicalHashMismatch,
                FiscalIntegrityAnomaly::CanonicalParseFailure,
                FiscalIntegrityAnomaly::DeviceTimeAnomaly,
            ] as $anomaly) {
                $this->assertSame(
                    FiscalIntegrityPolicyAction::AcceptAndQuarantine,
                    $service->actionFor($anomaly, $countryCode),
                );
            }
        }
    }

    public function test_fr_and_tn_sequence_gaps_require_acknowledgment(): void
    {
        $service = new FiscalIntegrityPolicyService;

        $this->assertSame(
            FiscalIntegrityPolicyAction::RequireAcknowledgment,
            $service->actionFor(FiscalIntegrityAnomaly::SequenceGap, 'FR'),
        );
        $this->assertSame(
            FiscalIntegrityPolicyAction::RequireAcknowledgment,
            $service->actionFor(FiscalIntegrityAnomaly::SequenceGap, 'TN'),
        );
    }

    public function test_signature_invalid_is_inactive_without_provider(): void
    {
        $service = new FiscalIntegrityPolicyService;

        $this->assertSame(
            FiscalIntegrityPolicyAction::Inactive,
            $service->actionFor(FiscalIntegrityAnomaly::SignatureInvalid, 'FR'),
        );
        $this->assertSame(
            FiscalIntegrityPolicyAction::Inactive,
            $service->actionFor(FiscalIntegrityAnomaly::SignatureInvalid, 'TN'),
        );
    }
}
