<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement;

use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\Enums\ProcurementPreset;
use PHPUnit\Framework\TestCase;

final class ProcurementPresetTest extends TestCase
{
    public function test_values_map_each_preset_to_exact_policy_fields(): void
    {
        $this->assertSame([
            ProcurementPreset::Complet->value => [
                'bill_control_mode' => BillControlMode::Received->value,
                'match_mode' => MatchMode::ThreeWay->value,
                'match_enforcement' => MatchEnforcement::Block->value,
            ],
            ProcurementPreset::Standard->value => [
                'bill_control_mode' => BillControlMode::Received->value,
                'match_mode' => MatchMode::ThreeWay->value,
                'match_enforcement' => MatchEnforcement::Warn->value,
            ],
            ProcurementPreset::Leger->value => [
                'bill_control_mode' => BillControlMode::Received->value,
                'match_mode' => MatchMode::TwoWay->value,
                'match_enforcement' => MatchEnforcement::Warn->value,
            ],
        ], ProcurementPreset::values());
    }
}
