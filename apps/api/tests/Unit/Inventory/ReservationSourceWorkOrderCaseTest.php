<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Company\Domain\ValueObjects\ReservationSettings;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use Tests\TestCase;

class ReservationSourceWorkOrderCaseTest extends TestCase
{
    public function test_work_order_case_is_registered(): void
    {
        $case = ReservationSource::from('work_order');
        $this->assertSame(ReservationSource::WorkOrder, $case);
    }

    public function test_work_order_case_has_no_default_expiry(): void
    {
        $settings = new ReservationSettings;
        $this->assertNull(ReservationSource::WorkOrder->getDefaultExpiry($settings));
    }

    public function test_work_order_case_does_not_trigger_fraud_alert(): void
    {
        $this->assertFalse(ReservationSource::WorkOrder->triggersFraudAlert());
    }

    public function test_work_order_case_has_label(): void
    {
        $this->assertSame('Work Order', ReservationSource::WorkOrder->label());
    }

    public function test_all_cases_have_exhaustive_match_arms(): void
    {
        // Regression guard: every case must resolve without UnhandledMatchError
        // (PHPStan level 8 requires exhaustive match arms; a runtime check is belt-and-braces).
        $settings = new ReservationSettings;
        $labels = [];
        $fraudFlags = [];
        $expiries = [];
        foreach (ReservationSource::cases() as $case) {
            $expiries[] = $case->getDefaultExpiry($settings);
            $fraudFlags[] = $case->triggersFraudAlert();
            $labels[] = $case->label();
        }
        $this->assertCount(count(ReservationSource::cases()), $labels);
        $this->assertCount(count(ReservationSource::cases()), $fraudFlags);
        $this->assertCount(count(ReservationSource::cases()), $expiries);
    }
}
