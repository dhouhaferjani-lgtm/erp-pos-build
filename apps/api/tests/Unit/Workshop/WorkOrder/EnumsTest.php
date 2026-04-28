<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\WorkOrder;

use App\Modules\Workshop\WorkOrder\Domain\Enums\ApprovalMethod;
use App\Modules\Workshop\WorkOrder\Domain\Enums\CancellationReason;
use App\Modules\Workshop\WorkOrder\Domain\Enums\CoreDepositStatus;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderType;
use PHPUnit\Framework\TestCase;

/**
 * Validates the six WorkOrder enums per Spec §5.2.
 */
final class EnumsTest extends TestCase
{
    public function test_work_order_status_values(): void
    {
        $this->assertSame([
            'received',
            'diagnosed',
            'quoted',
            'approved',
            'in_progress',
            'paused',
            'waiting_parts',
            'completed',
            'invoiced',
            'closed',
            'cancelled',
        ], WorkOrderStatus::values());
    }

    public function test_work_order_status_is_terminal(): void
    {
        $this->assertTrue(WorkOrderStatus::Closed->isTerminal());
        $this->assertTrue(WorkOrderStatus::Cancelled->isTerminal());
        $this->assertFalse(WorkOrderStatus::Received->isTerminal());
        $this->assertFalse(WorkOrderStatus::InProgress->isTerminal());
    }

    public function test_work_order_type_values(): void
    {
        $this->assertSame([
            'repair',
            'maintenance',
            'inspection',
            'bodywork',
            'tire_service',
            'electrical',
            'diagnostic',
            'other',
        ], WorkOrderType::values());
    }

    public function test_work_order_line_type_values(): void
    {
        $this->assertSame([
            'part',
            'labor',
            'core_charge',
            'core_return',
            'sublet',
            'environmental_fee',
            'misc_fee',
            'bundle_header',
        ], WorkOrderLineType::values());
    }

    public function test_approval_method_values(): void
    {
        $this->assertSame([
            'in_person',
            'phone',
            'email',
            'sms',
            'signed_document',
        ], ApprovalMethod::values());
    }

    public function test_cancellation_reason_values(): void
    {
        $this->assertSame([
            'customer_declined',
            'customer_no_show',
            'internal_error',
            'duplicate',
            'vehicle_unfit',
            'other',
        ], CancellationReason::values());
    }

    public function test_core_deposit_status_values(): void
    {
        $this->assertSame([
            'outstanding',
            'returned',
            'expired',
            'credited',
        ], CoreDepositStatus::values());
    }
}
