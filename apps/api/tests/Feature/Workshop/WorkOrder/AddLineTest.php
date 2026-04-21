<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Modules\Product\Domain\Product;
use App\Modules\Workshop\WorkOrder\Application\Commands\AddLineCommand;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderLineService;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderLineUpdated;
use App\Modules\Workshop\WorkOrder\Domain\Exceptions\WorkOrderImmutableException;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class AddLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_part_line_recomputes_totals_and_emits_event(): void
    {
        Event::fake([WorkOrderLineUpdated::class]);

        $wo = WorkOrder::factory()->create(['currency' => 'TND']);
        $product = Product::factory()->create([
            'tenant_id' => $wo->tenant_id,
            'company_id' => $wo->company_id,
        ]);

        $service = $this->app->make(WorkOrderLineService::class);
        $line = $service->addLine(new AddLineCommand(
            work_order_id: $wo->id,
            line_type: WorkOrderLineType::Part,
            product_id: $product->id,
            service_id: null,
            display_name: 'Brake pads',
            sku_or_code: 'BP-001',
            description: null,
            quantity: '2',
            unit: 'piece',
            unit_price: '50.000',
            tax_rate: '19',
            discount_percent: '0',
            labor_hours_estimated: null,
            assigned_technician_profile_id: null,
            is_customer_supplied: false,
        ));

        $this->assertSame(WorkOrderLineType::Part, $line->line_type);
        $this->assertSame('2.000', $line->quantity);
        $this->assertSame('100.000', $line->line_total_excl_tax);
        $this->assertSame('19.000', $line->line_total_tax);
        $this->assertSame('119.000', $line->line_total_incl_tax);

        $wo->refresh();
        $this->assertSame('100.000', $wo->estimated_parts_total);
        $this->assertSame('19.000', $wo->estimated_tax_total);
        $this->assertSame('119.000', $wo->estimated_grand_total);

        Event::assertDispatched(WorkOrderLineUpdated::class);
    }

    public function test_adding_line_to_completed_wo_raises_immutable_exception(): void
    {
        $wo = WorkOrder::factory()->completed()->create(['currency' => 'TND']);
        $product = Product::factory()->create([
            'tenant_id' => $wo->tenant_id,
            'company_id' => $wo->company_id,
        ]);

        $service = $this->app->make(WorkOrderLineService::class);

        $this->expectException(WorkOrderImmutableException::class);
        $service->addLine(new AddLineCommand(
            work_order_id: $wo->id,
            line_type: WorkOrderLineType::Part,
            product_id: $product->id,
            service_id: null,
            display_name: 'Brake pads',
            sku_or_code: null,
            description: null,
            quantity: '1',
            unit: 'piece',
            unit_price: '50',
            tax_rate: '19',
            discount_percent: '0',
            labor_hours_estimated: null,
            assigned_technician_profile_id: null,
            is_customer_supplied: false,
        ));
    }

    public function test_part_line_requires_product_id(): void
    {
        $wo = WorkOrder::factory()->create(['currency' => 'TND']);

        $service = $this->app->make(WorkOrderLineService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->addLine(new AddLineCommand(
            work_order_id: $wo->id,
            line_type: WorkOrderLineType::Part,
            product_id: null,
            service_id: null,
            display_name: 'Brake pads',
            sku_or_code: null,
            description: null,
            quantity: '1',
            unit: 'piece',
            unit_price: '50',
            tax_rate: '19',
            discount_percent: '0',
            labor_hours_estimated: null,
            assigned_technician_profile_id: null,
            is_customer_supplied: false,
        ));
    }

    public function test_initial_status_is_received_after_authoring(): void
    {
        $wo = WorkOrder::factory()->create();
        $this->assertSame(WorkOrderStatus::Received, $wo->status);
    }
}
