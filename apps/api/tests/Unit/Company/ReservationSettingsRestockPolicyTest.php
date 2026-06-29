<?php

declare(strict_types=1);

namespace Tests\Unit\Company;

use App\Modules\Company\Domain\ValueObjects\ReservationSettings;
use Tests\TestCase;

final class ReservationSettingsRestockPolicyTest extends TestCase
{
    public function test_default_and_round_trip(): void
    {
        $this->assertSame('default_allow', (new ReservationSettings)->default_restock_policy);

        $rebuilt = ReservationSettings::fromArray(
            (new ReservationSettings(default_restock_policy: 'if_sealed'))->toArray()
        );
        $this->assertSame('if_sealed', $rebuilt->default_restock_policy);
    }

    public function test_from_array_falls_back_when_key_absent(): void
    {
        $this->assertSame('default_allow', ReservationSettings::fromArray([])->default_restock_policy);
    }
}
