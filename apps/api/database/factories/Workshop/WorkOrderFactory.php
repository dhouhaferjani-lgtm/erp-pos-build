<?php

declare(strict_types=1);

namespace Database\Factories\Workshop;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderType;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkOrder>
 */
final class WorkOrderFactory extends Factory
{
    /** @var class-string<WorkOrder> */
    protected $model = WorkOrder::class;

    /**
     * Default state: Received work order for a random customer + vehicle.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenant = Tenant::first() ?? Tenant::factory()->create();
        $company = Company::where('tenant_id', $tenant->id)->first()
            ?? Company::factory()->create(['tenant_id' => $tenant->id]);

        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => null,
            'work_order_number' => 'WO-'.date('Y').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'status' => WorkOrderStatus::Received->value,
            'type' => WorkOrderType::Repair->value,
            'customer_partner_id' => $partner->id,
            'vehicle_id' => $vehicle->id,
            'opened_by_user_id' => $user->id,
            'primary_technician_profile_id' => null,
            'mileage_at_intake' => $this->faker->numberBetween(1000, 250000),
            'customer_complaint' => $this->faker->sentence(),
            'diagnosis' => null,
            'internal_notes' => null,
            'scheduled_start_at' => null,
            'scheduled_end_at' => null,
            'promised_at' => null,
            'started_at' => null,
            'paused_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'approval_captured_at' => null,
            'approval_method' => null,
            'approval_captured_by_user_id' => null,
            'approval_reference' => null,
            'currency' => 'TND',
            'estimated_parts_total' => '0.000',
            'estimated_labor_total' => '0.000',
            'estimated_other_total' => '0.000',
            'estimated_tax_total' => '0.000',
            'estimated_grand_total' => '0.000',
            'actual_parts_total' => '0.000',
            'actual_labor_total' => '0.000',
            'actual_other_total' => '0.000',
            'actual_tax_total' => '0.000',
            'actual_grand_total' => '0.000',
            'quote_document_id' => null,
            'invoice_document_id' => null,
        ];
    }

    public function diagnosed(): self
    {
        return $this->state(fn (): array => [
            'status' => WorkOrderStatus::Diagnosed->value,
            'diagnosis' => $this->faker->paragraph(),
        ]);
    }

    public function quoted(): self
    {
        return $this->state(fn (): array => [
            'status' => WorkOrderStatus::Quoted->value,
            'diagnosis' => $this->faker->paragraph(),
        ]);
    }

    public function approved(): self
    {
        return $this->state(fn (): array => [
            'status' => WorkOrderStatus::Approved->value,
            'approval_captured_at' => now(),
            'approval_method' => 'phone',
        ]);
    }

    public function inProgress(): self
    {
        return $this->state(fn (): array => [
            'status' => WorkOrderStatus::InProgress->value,
            'started_at' => now(),
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => WorkOrderStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }

    public function forType(WorkOrderType $type): self
    {
        return $this->state(fn (): array => ['type' => $type->value]);
    }
}
