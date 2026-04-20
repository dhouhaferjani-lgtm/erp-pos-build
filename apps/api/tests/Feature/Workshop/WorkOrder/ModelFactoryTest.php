<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Modules\Workshop\WorkOrder\Domain\Enums\CoreDepositStatus;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderType;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderAssignment;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderStatusTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke-tests the four Eloquent models + their factories. Exercises
 * enum casts and FK relations using RefreshDatabase + real seeded rows
 * (no fakes per AutoERP Memory testing convention).
 */
final class ModelFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_order_factory_creates_valid_row(): void
    {
        $wo = WorkOrder::factory()->create();

        $this->assertDatabaseHas('workshop_work_orders', ['id' => $wo->id]);
        $this->assertInstanceOf(WorkOrderStatus::class, $wo->status);
        $this->assertInstanceOf(WorkOrderType::class, $wo->type);
        $this->assertSame(WorkOrderStatus::Received, $wo->status);
    }

    public function test_work_order_has_lines_relation(): void
    {
        $wo = WorkOrder::factory()->create();
        $line = WorkOrderLine::factory()->for($wo)->create();

        $this->assertSame(1, $wo->lines()->count());
        $this->assertSame($line->id, $wo->lines->first()?->id);
    }

    public function test_work_order_line_factory_produces_part_line(): void
    {
        $line = WorkOrderLine::factory()->part()->create();

        $this->assertSame(WorkOrderLineType::Part, $line->line_type);
        $this->assertNotNull($line->product_id);
    }

    public function test_work_order_line_factory_produces_labor_line(): void
    {
        $line = WorkOrderLine::factory()->labor()->create();

        $this->assertSame(WorkOrderLineType::Labor, $line->line_type);
        $this->assertNotNull($line->service_id);
    }

    public function test_work_order_line_factory_produces_core_charge_line(): void
    {
        $line = WorkOrderLine::factory()->coreCharge()->create();

        $this->assertSame(WorkOrderLineType::CoreCharge, $line->line_type);
        $this->assertSame(CoreDepositStatus::Outstanding, $line->core_deposit_status);
    }

    public function test_work_order_assignment_factory(): void
    {
        $a = WorkOrderAssignment::factory()->create();

        $this->assertDatabaseHas('workshop_work_order_assignments', ['id' => $a->id]);
        $this->assertSame($a->work_order_id, $a->workOrder->id);
        $this->assertSame($a->technician_profile_id, $a->technicianProfile->id);
    }

    public function test_work_order_status_transition_factory(): void
    {
        $t = WorkOrderStatusTransition::factory()->create();

        $this->assertDatabaseHas('workshop_work_order_status_transitions', ['id' => $t->id]);
        $this->assertSame(WorkOrderStatus::Diagnosed, $t->to_status);
    }
}
