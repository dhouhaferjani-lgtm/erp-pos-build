<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Modules\Inventory\Application\Contracts\InventoryReservationServiceInterface;
use App\Modules\Inventory\Domain\StockReservation;
use App\Modules\Workshop\WorkOrder\Application\Commands\TransitionStatusCommand;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderTransitionService;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderApproved;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderPartsNeeded;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PartsNeededEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_transition_emits_parts_needed_event(): void
    {
        Event::fake([WorkOrderPartsNeeded::class, WorkOrderApproved::class]);

        // Swap the real reservation service with a spy/stub that returns a
        // fake StockReservation without hitting StockLevel lookups.
        $this->app->bind(InventoryReservationServiceInterface::class, static fn () => new class implements InventoryReservationServiceInterface
        {
            public function reserveForWorkOrder(
                string $tenantId,
                string $companyId,
                string $productId,
                string $quantity,
                string $workOrderLineId,
                string $workOrderId,
                ?\DateTimeImmutable $expiresAt,
            ): StockReservation {
                $r = new StockReservation;
                $r->id = (string) Str::uuid();

                return $r;
            }

            public function releaseForWorkOrder(string $workOrderId, string $reasonCode): int
            {
                return 0;
            }
        });

        $wo = WorkOrder::factory()->quoted()->create(['currency' => 'TND']);
        // Add a Part line (factory shortcut)
        WorkOrderLine::factory()->for($wo)->part()->create([
            'product_id' => (string) Str::uuid(),
            'is_customer_supplied' => false,
        ]);

        $service = $this->app->make(WorkOrderTransitionService::class);
        $service->transition(new TransitionStatusCommand(
            work_order_id: $wo->id,
            to_status: WorkOrderStatus::Approved,
            reason_code: null,
            triggered_by_user_id: null,
            occurred_at: new \DateTimeImmutable,
            context: null,
        ));

        Event::assertDispatched(
            WorkOrderPartsNeeded::class,
            function (WorkOrderPartsNeeded $event) use ($wo): bool {
                if ($event->work_order_id !== $wo->id) {
                    return false;
                }
                if (count($event->needs) !== 1) {
                    return false;
                }

                $need = $event->needs[0];

                return $need->vehicle_id === $wo->vehicle_id
                    && $need->urgency === 'normal';
            }
        );
    }

    public function test_approval_without_part_lines_does_not_emit_parts_needed(): void
    {
        Event::fake([WorkOrderPartsNeeded::class]);

        $this->app->bind(InventoryReservationServiceInterface::class, static fn () => new class implements InventoryReservationServiceInterface
        {
            public function reserveForWorkOrder(
                string $tenantId,
                string $companyId,
                string $productId,
                string $quantity,
                string $workOrderLineId,
                string $workOrderId,
                ?\DateTimeImmutable $expiresAt,
            ): StockReservation {
                throw new \RuntimeException('no parts, should not be called');
            }

            public function releaseForWorkOrder(string $workOrderId, string $reasonCode): int
            {
                return 0;
            }
        });

        $wo = WorkOrder::factory()->quoted()->create(['currency' => 'TND']);
        // No part lines.
        WorkOrderLine::factory()->for($wo)->labor()->create();

        $service = $this->app->make(WorkOrderTransitionService::class);
        $service->transition(new TransitionStatusCommand(
            work_order_id: $wo->id,
            to_status: WorkOrderStatus::Approved,
            reason_code: null,
            triggered_by_user_id: null,
            occurred_at: new \DateTimeImmutable,
            context: null,
        ));

        Event::assertNotDispatched(WorkOrderPartsNeeded::class);
        $refreshed = WorkOrder::query()->with('lines')->findOrFail($wo->id);
        $this->assertSame(WorkOrderLineType::Labor, $refreshed->lines->first()?->line_type);
    }
}
