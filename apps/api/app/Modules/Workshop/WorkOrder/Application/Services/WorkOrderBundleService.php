<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Services;

use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use App\Modules\Workshop\Bundle\Domain\ValueObjects\BundleExpansionLine;
use App\Modules\Workshop\WorkOrder\Application\Commands\AddBundleCommand;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderLineRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderLineUpdated;
use App\Modules\Workshop\WorkOrder\Domain\Exceptions\WorkOrderImmutableException;
use App\Modules\Workshop\WorkOrder\Domain\Services\TotalsCalculator;
use App\Modules\Workshop\WorkOrder\Domain\Services\TotalsCalculatorLineInput;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use App\Modules\Workshop\WorkOrder\Infrastructure\Adapters\BundleExpansionAdapter;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Materialize a ServiceBundle into WorkOrderLine rows (snapshot).
 *
 * Standard mode: each BundleExpansionLine becomes a priced WorkOrderLine.
 * Fixed-bundle mode: the synthetic header line becomes a `bundle_header`
 * WorkOrderLine at the bundle's flat price; each subsequent informational
 * component becomes a `is_bundle_informational=true` line (qty + unit
 * preserved for inventory/labor attribution; money columns at zero).
 *
 * Mixed-VAT fixed bundles surface the underlying BundleExpansionService's
 * `MixedVatInFixedBundleException` which the HTTP boundary maps to 422.
 */
final readonly class WorkOrderBundleService
{
    public function __construct(
        private WorkOrderRepositoryInterface $workOrders,
        private WorkOrderLineRepositoryInterface $lines,
        private TotalsCalculator $totals,
        private BundleExpansionAdapter $bundles,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return list<WorkOrderLine> Newly created lines, in insertion order.
     */
    public function addBundle(AddBundleCommand $command): array
    {
        return $this->db->transaction(function () use ($command): array {
            $wo = $this->requireMutableWorkOrder($command->tenant_id, $command->company_id, $command->work_order_id);

            $expansion = $this->bundles->expand($wo->tenant_id, $wo->company_id, $command->bundle_id, $command->quantity, $command->vehicle_id);
            $pricingMode = $this->bundles->pricingModeOf($wo->tenant_id, $wo->company_id, $command->bundle_id);
            if ($pricingMode === null) {
                throw new RuntimeException("Bundle {$command->bundle_id} not found.");
            }

            $existing = $this->lines->listForWorkOrder($wo->id);
            $displayOrder = count($existing);
            $scale = CurrencyScale::for($wo->currency);

            $created = [];
            $isFixed = $pricingMode === BundlePricingMode::FixedBundle;

            foreach ($expansion as $expansionLine) {
                // First line of a fixed bundle is the synthetic header carrying the
                // priced total; subsequent lines are informational.
                $isHeader = $isFixed && $expansionLine->component_id === null && ! $expansionLine->is_from_fixed_bundle;
                $lineType = $this->lineTypeFor($expansionLine, $isHeader);

                $line = new WorkOrderLine;
                $line->fill([
                    'tenant_id' => $wo->tenant_id,
                    'work_order_id' => $wo->id,
                    'line_type' => $lineType->value,
                    'display_order' => $displayOrder++,
                    'product_id' => $expansionLine->component_type === BundleComponentType::Part
                        ? $expansionLine->component_id
                        : null,
                    'service_id' => $expansionLine->component_type === BundleComponentType::Labor
                        ? $expansionLine->component_id
                        : null,
                    'service_bundle_id' => $isHeader ? $command->bundle_id : null,
                    'display_name' => $expansionLine->display_name,
                    'sku_or_code' => null,
                    'description' => null,
                    'quantity' => CurrencyScale::bcformat($expansionLine->quantity, 3),
                    'unit' => $expansionLine->unit,
                    'unit_price' => CurrencyScale::bcformat($expansionLine->unit_price, $scale),
                    'tax_rate' => '0.000',
                    'discount_percent' => '0.00',
                    'line_total_excl_tax' => CurrencyScale::bcformat($expansionLine->line_total, $scale),
                    'line_total_tax' => CurrencyScale::bcformat('0', $scale),
                    'line_total_incl_tax' => CurrencyScale::bcformat($expansionLine->line_total, $scale),
                    'labor_hours_estimated' => $expansionLine->component_type === BundleComponentType::Labor
                        ? CurrencyScale::bcformat($expansionLine->quantity, 2)
                        : null,
                    'labor_hours_actual' => null,
                    'assigned_technician_profile_id' => null,
                    'stock_reservation_id' => null,
                    'is_customer_supplied' => false,
                    'core_deposit_partner_id' => null,
                    'core_deposit_status' => null,
                    'core_return_of_line_id' => null,
                    'from_bundle_id' => $command->bundle_id,
                    'is_bundle_informational' => $expansionLine->is_from_fixed_bundle,
                    'is_completed' => false,
                ]);

                $this->lines->save($line);
                $created[] = $line;

                event(new WorkOrderLineUpdated(
                    work_order_id: $wo->id,
                    line_id: $line->id,
                    change_type: 'added',
                    updated_at: new \DateTimeImmutable,
                ));
            }

            $this->recomputeWorkOrderTotals($wo);

            return $created;
        });
    }

    private function lineTypeFor(BundleExpansionLine $expansionLine, bool $isHeader): WorkOrderLineType
    {
        if ($isHeader) {
            return WorkOrderLineType::BundleHeader;
        }

        return match ($expansionLine->component_type) {
            BundleComponentType::Part => WorkOrderLineType::Part,
            BundleComponentType::Labor => WorkOrderLineType::Labor,
            // Nested-bundle rows never reach here (they're flattened upstream)
            BundleComponentType::NestedBundle => WorkOrderLineType::MiscFee,
        };
    }

    private function recomputeWorkOrderTotals(WorkOrder $wo): void
    {
        $lines = $this->lines->listForWorkOrder($wo->id);

        $inputs = [];
        foreach ($lines as $line) {
            $inputs[] = new TotalsCalculatorLineInput(
                line_type: $line->line_type,
                line_total_excl_tax: $line->line_total_excl_tax,
                line_total_tax: $line->line_total_tax,
                line_total_incl_tax: $line->line_total_incl_tax,
                is_bundle_informational: $line->is_bundle_informational,
            );
        }

        $totals = $this->totals->compute($inputs, $wo->currency);

        $wo->estimated_parts_total = $totals->parts_total;
        $wo->estimated_labor_total = $totals->labor_total;
        $wo->estimated_other_total = $totals->other_total;
        $wo->estimated_tax_total = $totals->tax_total;
        $wo->estimated_grand_total = $totals->grand_total;

        $this->workOrders->save($wo);
    }

    private function requireMutableWorkOrder(string $tenantId, string $companyId, string $workOrderId): WorkOrder
    {
        $wo = $this->workOrders->findForUpdateForScope($tenantId, $companyId, $workOrderId);
        if ($wo === null) {
            throw new RuntimeException("WorkOrder {$workOrderId} not found.");
        }

        if (in_array($wo->status, [
            WorkOrderStatus::Completed,
            WorkOrderStatus::Invoiced,
            WorkOrderStatus::Closed,
            WorkOrderStatus::Cancelled,
        ], true)) {
            throw WorkOrderImmutableException::forStatus($wo->status);
        }

        return $wo;
    }
}
