<?php

declare(strict_types=1);

namespace Database\Factories\Workshop;

use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\WorkOrder\Domain\Enums\CoreDepositStatus;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkOrderLine>
 */
final class WorkOrderLineFactory extends Factory
{
    /** @var class-string<WorkOrderLine> */
    protected $model = WorkOrderLine::class;

    /**
     * Default state: Part line referencing a freshly-made Product.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Eagerly create the parent WorkOrder so tenant_id is in sync. Callers
        // that pass an explicit `work_order_id` via `for(...)` or state() will
        // override this via the factory override-merge semantics — but if they
        // do, they are expected to also pass `tenant_id`.
        $workOrder = WorkOrder::factory()->create();

        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $workOrder->tenant_id,
            'work_order_id' => $workOrder->id,
            'line_type' => WorkOrderLineType::Part->value,
            'display_order' => 0,
            'product_id' => fn (array $attrs): ?string => $this->productIdForType($attrs),
            'service_id' => fn (array $attrs): ?string => $this->serviceIdForType($attrs),
            'service_bundle_id' => fn (array $attrs): ?string => $this->bundleIdForType($attrs),
            'display_name' => $this->faker->words(3, true),
            'sku_or_code' => strtoupper($this->faker->bothify('SKU-###??')),
            'description' => $this->faker->optional()->sentence(),
            'quantity' => '1.000',
            'unit' => 'pc',
            'unit_price' => '50.000',
            'tax_rate' => '19.00',
            'discount_percent' => '0.00',
            'line_total_excl_tax' => '50.000',
            'line_total_tax' => '9.500',
            'line_total_incl_tax' => '59.500',
            'labor_hours_estimated' => null,
            'labor_hours_actual' => null,
            'assigned_technician_profile_id' => null,
            'stock_reservation_id' => null,
            'is_customer_supplied' => false,
            'core_deposit_partner_id' => null,
            'core_deposit_status' => null,
            'core_return_of_line_id' => null,
            'from_bundle_id' => null,
            'is_bundle_informational' => false,
            'is_completed' => false,
            'completed_at' => null,
        ];
    }

    public function part(): self
    {
        return $this->state(fn (): array => [
            'line_type' => WorkOrderLineType::Part->value,
            'service_id' => null,
            'service_bundle_id' => null,
        ]);
    }

    public function labor(): self
    {
        return $this->state(fn (): array => [
            'line_type' => WorkOrderLineType::Labor->value,
            'product_id' => null,
            'service_bundle_id' => null,
            'labor_hours_estimated' => '1.00',
            'unit' => 'h',
        ]);
    }

    public function coreCharge(string $deposit = '25.000'): self
    {
        return $this->state(fn (): array => [
            'line_type' => WorkOrderLineType::CoreCharge->value,
            'service_id' => null,
            'service_bundle_id' => null,
            'unit_price' => $deposit,
            'line_total_excl_tax' => $deposit,
            'line_total_tax' => '0.000',
            'line_total_incl_tax' => $deposit,
            'core_deposit_status' => CoreDepositStatus::Outstanding->value,
        ]);
    }

    public function bundleHeader(): self
    {
        return $this->state(fn (): array => [
            'line_type' => WorkOrderLineType::BundleHeader->value,
            'product_id' => null,
            'service_id' => null,
            'service_bundle_id' => ServiceBundle::factory(),
        ]);
    }

    // -- Helpers --

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function productIdForType(array $attrs): ?string
    {
        $lineType = $attrs['line_type'] ?? WorkOrderLineType::Part->value;
        if (in_array($lineType, [
            WorkOrderLineType::Part->value,
            WorkOrderLineType::CoreCharge->value,
            WorkOrderLineType::CoreReturn->value,
        ], true)) {
            $product = Product::factory()->create($this->tenantContext($attrs));

            return $product->id;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function serviceIdForType(array $attrs): ?string
    {
        $lineType = $attrs['line_type'] ?? WorkOrderLineType::Part->value;
        if ($lineType === WorkOrderLineType::Labor->value) {
            return Service::factory()->create($this->tenantContext($attrs))->id;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function bundleIdForType(array $attrs): ?string
    {
        $lineType = $attrs['line_type'] ?? WorkOrderLineType::Part->value;
        if ($lineType === WorkOrderLineType::BundleHeader->value) {
            return ServiceBundle::factory()->create($this->tenantContext($attrs))->id;
        }

        return null;
    }

    /**
     * Derive tenant_id/company_id from the line's parent WorkOrder so FK
     * constraints on polymorphic references hold under RefreshDatabase.
     *
     * @param  array<string, mixed>  $attrs
     * @return array<string, string>
     */
    private function tenantContext(array $attrs): array
    {
        $context = [];
        if (is_string($attrs['tenant_id'] ?? null)) {
            $context['tenant_id'] = $attrs['tenant_id'];
        }
        $workOrderId = $attrs['work_order_id'] ?? null;
        if (is_string($workOrderId)) {
            $wo = WorkOrder::find($workOrderId);
            if ($wo !== null) {
                $context['tenant_id'] = $wo->tenant_id;
                $context['company_id'] = $wo->company_id;
            }
        }

        return $context;
    }
}
