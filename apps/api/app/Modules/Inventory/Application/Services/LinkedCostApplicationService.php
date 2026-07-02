<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentAdditionalCost;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\LandedCostSplitMethod;
use App\Modules\Expense\Application\Exceptions\LinkedCostException;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Inventory\LinkedCostApplicatorInterface;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\ProportionalMoneyAllocator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class LinkedCostApplicationService implements LinkedCostApplicatorInterface
{
    public function __construct(
        private readonly WeightedAverageCostService $wacService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly ProportionalMoneyAllocator $moneyAllocator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function apply(Document $expense, DocumentAdditionalCost $cost, User $user): array
    {
        if ($cost->applied_at !== null) {
            return $expense->payload['linked_cost_application'] ?? [
                'path' => 'wac_adjustment',
                'split_method' => $cost->split_method->value,
                'inventory_total' => '0.000',
                'cogs_total' => '0.000',
                'adjustments' => [],
            ];
        }

        $result = $this->calculateAndApply($expense, $cost, (string) $cost->amount, false);
        $cost->applied_at = now();
        $cost->save();

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function reverse(Document $expense, DocumentAdditionalCost $originalCost, DocumentAdditionalCost $reversalCost, User $user): array
    {
        return $this->calculateAndApply($expense, $reversalCost, (string) $originalCost->amount, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function calculateAndApply(Document $expense, DocumentAdditionalCost $cost, string $amount, bool $reverse): array
    {
        $scale = $this->scaleResolver->getScale((string) $expense->currency);
        $working = max($scale + 4, 7);
        $operation = $cost->document()->with('lines.product')->firstOrFail();
        $lines = $this->receivedProductLines($operation);
        $bases = $this->bases($lines, $cost->split_method, (string) $expense->currency);
        $shares = $this->moneyAllocator->allocate($amount, $bases, $scale, $working);

        $inventoryByProduct = [];
        $cogsTotal = CurrencyScale::bcformatStrict('0', $scale);
        $lineBreakdown = [];

        foreach ($lines->values() as $index => $line) {
            $lineShare = $shares[$index];
            $soldQty = $this->soldSinceReceipt($operation, $line, $working);
            $receivedQty = CurrencyScale::bcformatStrict((string) $line->quantity_received, 4);
            $soldRatio = bccomp($receivedQty, '0', 4) > 0
                ? bcdiv($soldQty, $receivedQty, $working)
                : '0';

            if (bccomp($soldRatio, '1', $working) > 0) {
                $soldRatio = '1';
            }

            $cogsPortion = CurrencyScale::bcformatStrict(bcmul($lineShare, $soldRatio, $working), $scale);
            $inventoryPortion = CurrencyScale::bcformatStrict(bcsub($lineShare, $cogsPortion, $scale), $scale);

            $cogsTotal = bcadd($cogsTotal, $cogsPortion, $scale);
            if (bccomp($inventoryPortion, '0', $scale) > 0 && $line->product_id !== null) {
                $inventoryByProduct[$line->product_id] = bcadd($inventoryByProduct[$line->product_id] ?? '0.000', $inventoryPortion, $scale);
            }

            $lineBreakdown[] = [
                'line_id' => $line->id,
                'product_id' => $line->product_id,
                'line_share' => $lineShare,
                'sold_quantity' => CurrencyScale::bcformatStrict($soldQty, 4),
                'inventory_portion' => $inventoryPortion,
                'cogs_portion' => $cogsPortion,
            ];
        }

        $inventoryTotal = CurrencyScale::bcformatStrict('0', $scale);
        $adjustments = [];

        foreach ($inventoryByProduct as $productId => $portion) {
            /** @var Product $product */
            $product = Product::query()
                ->where('tenant_id', $expense->tenant_id)
                ->where('company_id', $expense->company_id)
                ->findOrFail($productId);

            $delta = $reverse ? bcmul($portion, '-1', $scale) : $portion;
            $movement = $this->wacService->recordCostAdjustment(
                product: $product,
                additionalCost: $delta,
                reason: $reverse
                    ? "linked cost reversal {$expense->document_number}"
                    : "linked cost {$expense->document_number}",
                tenantId: $expense->tenant_id,
                companyId: $expense->company_id,
                reference: $expense->document_number,
                referenceType: DocumentAdditionalCost::class,
                referenceId: $cost->id,
            );

            if ($movement === null) {
                $cogsTotal = bcadd($cogsTotal, $portion, $scale);
                $adjustments[] = [
                    'product_id' => $productId,
                    'inventory_portion' => '0.000',
                    'cogs_portion' => $portion,
                    'stock_movement_id' => null,
                ];
                continue;
            }

            $inventoryTotal = bcadd($inventoryTotal, $portion, $scale);
            $adjustments[] = [
                'product_id' => $productId,
                'inventory_portion' => $portion,
                'cogs_portion' => '0.000',
                'stock_movement_id' => $movement->id,
            ];
        }

        return [
            'path' => 'wac_adjustment',
            'split_method' => $cost->split_method->value,
            'inventory_total' => CurrencyScale::bcformatStrict($inventoryTotal, $scale),
            'cogs_total' => CurrencyScale::bcformatStrict($cogsTotal, $scale),
            'adjustments' => $adjustments,
            'lines' => $lineBreakdown,
        ];
    }

    /**
     * @return Collection<int, DocumentLine>
     */
    private function receivedProductLines(Document $operation): Collection
    {
        $lines = $operation->lines
            ->filter(fn (DocumentLine $line): bool => $line->product_id !== null && bccomp((string) $line->line_total, '0', 3) > 0)
            ->values();

        if ($lines->isEmpty()) {
            throw new LinkedCostException('OPERATION_NOT_RECEIVED', 'The linked purchase operation has no product lines.');
        }

        $received = $lines->filter(fn (DocumentLine $line): bool => $line->accrual_unit_cost !== null)->count();
        if ($received === 0) {
            throw new LinkedCostException('OPERATION_NOT_RECEIVED', 'The linked purchase operation has not been received.');
        }
        if ($received !== $lines->count()) {
            throw new LinkedCostException('OPERATION_PARTIALLY_RECEIVED', 'Partially received purchase operations are not supported in Phase 1.');
        }

        return $lines;
    }

    /**
     * @param  Collection<int, DocumentLine>  $lines
     * @return array<int, numeric-string>
     */
    private function bases(Collection $lines, LandedCostSplitMethod $splitMethod, string $currency): array
    {
        $working = max($this->scaleResolver->getScale($currency) + 4, 7);

        return $lines->map(function (DocumentLine $line) use ($splitMethod, $working): string {
            if ($splitMethod === LandedCostSplitMethod::ByQuantity) {
                return CurrencyScale::bcformatStrict((string) $line->quantity_received, $working);
            }

            return CurrencyScale::bcformatStrict(
                bcmul((string) $line->quantity_received, (string) $line->unit_price, $working),
                $working,
            );
        })->all();
    }

    /**
     * @return numeric-string
     */
    private function soldSinceReceipt(Document $operation, DocumentLine $line, int $working): string
    {
        $receiptAt = $this->receiptTimestamp($operation, $line);
        if ($receiptAt === null || $line->product_id === null) {
            return '0';
        }

        $sold = '0';
        StockMovement::query()
            ->where('tenant_id', $operation->tenant_id)
            ->where('company_id', $operation->company_id)
            ->where('product_id', $line->product_id)
            ->where('movement_type', MovementType::Issue->value)
            ->where('created_at', '>=', $receiptAt)
            ->orderBy('created_at')
            ->each(function (StockMovement $movement) use (&$sold, $working): void {
                $quantity = (string) $movement->quantity;
                if (str_starts_with($quantity, '-')) {
                    $quantity = substr($quantity, 1);
                }
                $sold = bcadd($sold, $quantity, $working);
            });

        $received = CurrencyScale::bcformatStrict((string) $line->quantity_received, 4);

        return bccomp($sold, $received, $working) > 0 ? $received : $sold;
    }

    private function receiptTimestamp(Document $operation, DocumentLine $line): ?Carbon
    {
        if ($line->product_id === null) {
            return null;
        }

        /** @var StockMovement|null $movement */
        $movement = StockMovement::query()
            ->where('tenant_id', $operation->tenant_id)
            ->where('company_id', $operation->company_id)
            ->where('product_id', $line->product_id)
            ->where('movement_type', MovementType::Receipt->value)
            ->where('reference_type', 'Document')
            ->where('reference_id', $operation->id)
            ->orderBy('created_at')
            ->first();

        return $movement?->created_at;
    }
}
