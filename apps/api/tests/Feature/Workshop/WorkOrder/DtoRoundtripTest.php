<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Modules\Workshop\WorkOrder\Application\DTOs\WorkOrderData;
use App\Modules\Workshop\WorkOrder\Application\DTOs\WorkOrderLineData;
use App\Modules\Workshop\WorkOrder\Application\DTOs\WorkOrderListItemData;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DtoRoundtripTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_item_data_maps_core_fields(): void
    {
        $wo = WorkOrder::factory()->create([
            'work_order_number' => 'WO-2026-0001',
            'currency' => 'TND',
            'estimated_grand_total' => '450.000',
        ]);

        $dto = WorkOrderListItemData::fromModel($wo->refresh());

        $this->assertSame($wo->id, $dto->id);
        $this->assertSame('WO-2026-0001', $dto->work_order_number);
        $this->assertSame('TND', $dto->currency);
        $this->assertSame('450.000', $dto->estimated_grand_total);
    }

    public function test_line_data_maps_polymorphic_refs(): void
    {
        $wo = WorkOrder::factory()->create();
        $line = WorkOrderLine::factory()->for($wo)->labor()->create();

        $dto = WorkOrderLineData::fromModel($line->refresh());

        $this->assertSame($line->id, $dto->id);
        $this->assertNotNull($dto->service_id);
        $this->assertNull($dto->product_id);
    }

    public function test_work_order_data_includes_nested_collections(): void
    {
        $wo = WorkOrder::factory()->create();
        WorkOrderLine::factory()->for($wo)->part()->count(2)->create();

        $dto = WorkOrderData::fromModel($wo->refresh()->load(['lines', 'assignments', 'statusTransitions']));

        $this->assertCount(2, $dto->lines);
        $this->assertSame($wo->id, $dto->id);
    }

    public function test_work_order_data_serializes_to_array_cleanly(): void
    {
        $wo = WorkOrder::factory()->create();
        $dto = WorkOrderData::fromModel($wo->refresh()->load(['lines', 'assignments', 'statusTransitions']));

        $arr = $dto->toArray();
        // snake_case keys per AutoERP convention + Spec §7.3
        $this->assertArrayHasKey('work_order_number', $arr);
        $this->assertArrayHasKey('estimated_totals', $arr);
        $this->assertArrayHasKey('actual_totals', $arr);
        $this->assertArrayHasKey('lines', $arr);
    }
}
