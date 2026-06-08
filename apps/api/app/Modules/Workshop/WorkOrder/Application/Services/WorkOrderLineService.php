<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Services;

use App\Modules\Workshop\WorkOrder\Application\Commands\AddLineCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\RemoveLineCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\ReorderLinesCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\UpdateLineCommand;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderLineRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Enums\CoreDepositStatus;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderLineUpdated;
use App\Modules\Workshop\WorkOrder\Domain\Exceptions\WorkOrderImmutableException;
use App\Modules\Workshop\WorkOrder\Domain\Services\TotalsCalculator;
use App\Modules\Workshop\WorkOrder\Domain\Services\TotalsCalculatorLineInput;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * WorkOrderLine CRUD + totals recompute. Lines may only be mutated while the
 * parent WO is in an editable status (everything up to Completed; Completed,
 * Invoiced, Closed, Cancelled reject writes via WorkOrderImmutableException).
 *
 * Totals are recomputed on every write and persisted on the WO header so list
 * views and dashboards don't have to aggregate lines.
 */
final readonly class WorkOrderLineService
{
    public function __construct(
        private WorkOrderRepositoryInterface $workOrders,
        private WorkOrderLineRepositoryInterface $lines,
        private TotalsCalculator $totals,
        private ConnectionInterface $db,
    ) {}

    public function addLine(AddLineCommand $command): WorkOrderLine
    {
        return $this->db->transaction(function () use ($command): WorkOrderLine {
            $wo = $this->requireMutableWorkOrder($command->tenant_id, $command->company_id, $command->work_order_id);
            $this->assertRefsMatchLineType($command);

            $existing = $this->lines->listForWorkOrder($wo->id);
            $displayOrder = count($existing);

            $scale = CurrencyScale::for($wo->currency);

            $line = new WorkOrderLine;
            $line->fill([
                'tenant_id' => $wo->tenant_id,
                'work_order_id' => $wo->id,
                'line_type' => $command->line_type->value,
                'display_order' => $displayOrder,
                'product_id' => $command->product_id,
                'service_id' => $command->service_id,
                'service_bundle_id' => null,
                'display_name' => $command->display_name,
                'sku_or_code' => $command->sku_or_code,
                'description' => $command->description,
                'quantity' => CurrencyScale::bcformat($command->quantity, 4),
                'unit' => $command->unit,
                'unit_price' => CurrencyScale::bcformat($command->unit_price, $scale),
                'tax_rate' => CurrencyScale::bcformat($command->tax_rate, 3),
                'discount_percent' => CurrencyScale::bcformat($command->discount_percent, 2),
                'labor_hours_estimated' => $command->labor_hours_estimated !== null
                    ? CurrencyScale::bcformat($command->labor_hours_estimated, 2)
                    : null,
                'labor_hours_actual' => null,
                'assigned_technician_profile_id' => $command->assigned_technician_profile_id,
                'stock_reservation_id' => null,
                'is_customer_supplied' => $command->is_customer_supplied,
                'core_deposit_partner_id' => null,
                'core_deposit_status' => null,
                'core_return_of_line_id' => null,
                'from_bundle_id' => null,
                'is_bundle_informational' => false,
                'is_completed' => false,
            ]);

            $this->recomputeLineMoney($line, $scale);
            $this->lines->save($line);

            $this->recomputeWorkOrderTotals($wo);

            event(new WorkOrderLineUpdated(
                work_order_id: $wo->id,
                line_id: $line->id,
                change_type: 'added',
                updated_at: new \DateTimeImmutable,
            ));

            return $line;
        });
    }

    public function updateLine(UpdateLineCommand $command): WorkOrderLine
    {
        return $this->db->transaction(function () use ($command): WorkOrderLine {
            $wo = $this->requireMutableWorkOrder($command->tenant_id, $command->company_id, $command->work_order_id);
            $line = $this->lines->findById($command->line_id);
            if ($line === null || $line->work_order_id !== $wo->id) {
                throw new RuntimeException("WorkOrderLine {$command->line_id} not found for WO {$wo->id}.");
            }

            $scale = CurrencyScale::for($wo->currency);

            if ($command->display_name !== null) {
                $line->display_name = $command->display_name;
            }
            if ($command->description !== null) {
                $line->description = $command->description;
            }
            if ($command->quantity !== null) {
                $line->quantity = CurrencyScale::bcformat($command->quantity, 4);
            }
            if ($command->unit_price !== null) {
                $line->unit_price = CurrencyScale::bcformat($command->unit_price, $scale);
            }
            if ($command->tax_rate !== null) {
                $line->tax_rate = CurrencyScale::bcformat($command->tax_rate, 3);
            }
            if ($command->discount_percent !== null) {
                $line->discount_percent = CurrencyScale::bcformat($command->discount_percent, 2);
            }
            if ($command->labor_hours_actual !== null) {
                $line->labor_hours_actual = CurrencyScale::bcformat($command->labor_hours_actual, 2);
            }
            if ($command->assigned_technician_profile_id !== null) {
                $line->assigned_technician_profile_id = $command->assigned_technician_profile_id;
            }
            if ($command->is_completed !== null) {
                $line->is_completed = $command->is_completed;
                if ($command->is_completed) {
                    $line->completed_at = now();
                }
            }

            $this->recomputeLineMoney($line, $scale);
            $this->lines->save($line);

            $this->recomputeWorkOrderTotals($wo);

            event(new WorkOrderLineUpdated(
                work_order_id: $wo->id,
                line_id: $line->id,
                change_type: 'updated',
                updated_at: new \DateTimeImmutable,
            ));

            return $line;
        });
    }

    public function removeLine(RemoveLineCommand $command): void
    {
        $this->db->transaction(function () use ($command): void {
            $wo = $this->requireMutableWorkOrder($command->tenant_id, $command->company_id, $command->work_order_id);
            $line = $this->lines->findById($command->line_id);
            if ($line === null || $line->work_order_id !== $wo->id) {
                throw new RuntimeException("WorkOrderLine {$command->line_id} not found for WO {$wo->id}.");
            }

            $this->lines->delete($line->id);
            $this->recomputeWorkOrderTotals($wo);

            event(new WorkOrderLineUpdated(
                work_order_id: $wo->id,
                line_id: $command->line_id,
                change_type: 'removed',
                updated_at: new \DateTimeImmutable,
            ));
        });
    }

    public function reorderLines(ReorderLinesCommand $command): void
    {
        $this->db->transaction(function () use ($command): void {
            $wo = $this->requireMutableWorkOrder($command->tenant_id, $command->company_id, $command->work_order_id);

            foreach ($command->ordered_line_ids as $index => $lineId) {
                $line = $this->lines->findById($lineId);
                if ($line === null || $line->work_order_id !== $wo->id) {
                    throw new RuntimeException("WorkOrderLine {$lineId} not found for WO {$wo->id}.");
                }
                $line->display_order = $index;
                $this->lines->save($line);
            }
        });
    }

    /**
     * Mark the line as a CoreReturn paired with a prior CoreCharge. Flips the
     * paired CoreCharge's deposit status to Returned. Enforces mutable status.
     */
    public function returnCoreCharge(string $tenantId, string $companyId, string $workOrderId, string $coreChargeLineId, string $coreReturnLineId): void
    {
        $this->db->transaction(function () use ($tenantId, $companyId, $workOrderId, $coreChargeLineId, $coreReturnLineId): void {
            $wo = $this->requireMutableWorkOrder($tenantId, $companyId, $workOrderId);

            $charge = $this->lines->findById($coreChargeLineId);
            $return = $this->lines->findById($coreReturnLineId);
            if ($charge === null || $return === null || $charge->work_order_id !== $wo->id || $return->work_order_id !== $wo->id) {
                throw new RuntimeException('Core charge pair not found on the given WorkOrder.');
            }
            if ($charge->line_type !== WorkOrderLineType::CoreCharge) {
                throw new InvalidArgumentException('Paired charge line must be of type core_charge.');
            }
            if ($return->line_type !== WorkOrderLineType::CoreReturn) {
                throw new InvalidArgumentException('Paired return line must be of type core_return.');
            }

            $return->core_return_of_line_id = $charge->id;
            $this->lines->save($return);

            $charge->core_deposit_status = CoreDepositStatus::Returned;
            $this->lines->save($charge);
        });
    }

    private function assertRefsMatchLineType(AddLineCommand $command): void
    {
        $type = $command->line_type;
        $hasProduct = $command->product_id !== null;
        $hasService = $command->service_id !== null;

        $requirement = match ($type) {
            WorkOrderLineType::Part, WorkOrderLineType::CoreCharge, WorkOrderLineType::CoreReturn => 'product',
            WorkOrderLineType::Labor => 'service',
            WorkOrderLineType::Sublet, WorkOrderLineType::EnvironmentalFee, WorkOrderLineType::MiscFee => 'any',
            WorkOrderLineType::BundleHeader => 'bundle-only',
        };

        if ($requirement === 'product' && ! $hasProduct) {
            throw new InvalidArgumentException("Line type {$type->value} requires product_id.");
        }
        if ($requirement === 'service' && ! $hasService) {
            throw new InvalidArgumentException("Line type {$type->value} requires service_id.");
        }
        if ($requirement === 'bundle-only') {
            throw new InvalidArgumentException('bundle_header lines must be created via WorkOrderBundleService::addBundle.');
        }
    }

    private function recomputeLineMoney(WorkOrderLine $line, int $scale): void
    {
        /** @var numeric-string $qty */
        $qty = CurrencyScale::bcformat($line->quantity, 4);
        /** @var numeric-string $unit */
        $unit = CurrencyScale::bcformat($line->unit_price, $scale);
        /** @var numeric-string $discount */
        $discount = CurrencyScale::bcformat($line->discount_percent, 2);
        /** @var numeric-string $taxRate */
        $taxRate = CurrencyScale::bcformat($line->tax_rate, 3);

        $gross = CurrencyScale::bcformat(bcmul($qty, $unit, $scale + 3), $scale);
        /** @var numeric-string $grossNum */
        $grossNum = $gross;
        $discountAmount = CurrencyScale::bcformat(
            bcdiv(bcmul($grossNum, $discount, $scale + 3), '100', $scale + 3),
            $scale
        );
        /** @var numeric-string $discountAmountNum */
        $discountAmountNum = $discountAmount;
        $excl = CurrencyScale::bcformat(bcsub($grossNum, $discountAmountNum, $scale + 3), $scale);
        /** @var numeric-string $exclNum */
        $exclNum = $excl;
        $taxAmount = CurrencyScale::bcformat(
            bcdiv(bcmul($exclNum, $taxRate, $scale + 3), '100', $scale + 3),
            $scale
        );
        /** @var numeric-string $taxAmountNum */
        $taxAmountNum = $taxAmount;
        $incl = CurrencyScale::bcformat(bcadd($exclNum, $taxAmountNum, $scale + 3), $scale);

        $line->line_total_excl_tax = $excl;
        $line->line_total_tax = $taxAmount;
        $line->line_total_incl_tax = $incl;
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
