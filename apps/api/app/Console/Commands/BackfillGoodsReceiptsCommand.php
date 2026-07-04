<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @cross-tenant-by-design Iterates companies fleet-wide unless --company narrows scope; synthesizes receipt ledger rows from historical stock movements.
 */
final class BackfillGoodsReceiptsCommand extends Command
{
    protected $signature = 'procurement:backfill-goods-receipts
        {--company= : Specific company UUID to backfill}
        {--dry-run : Count eligible movements without writing receipt rows}';

    protected $description = 'Backfill goods_receipts and goods_receipt_lines from historical purchase stock movements';

    public function __construct(
        private readonly DocumentNumberingService $numberingService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $companyId = $this->option('company');
        $companies = Company::query()
            ->when(is_string($companyId) && $companyId !== '', fn ($query) => $query->where('id', $companyId))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->error('No companies found.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Dry run: no receipt rows will be written.');
        }

        $eligibleMovements = 0;
        $createdReceipts = 0;
        $createdLines = 0;

        foreach ($companies as $company) {
            $movements = $this->eligibleMovements((string) $company->id);
            $eligibleMovements += $movements->count();

            if ($dryRun || $movements->isEmpty()) {
                continue;
            }

            [$receipts, $lines] = $this->backfillCompany($company, $movements);
            $createdReceipts += $receipts;
            $createdLines += $lines;
        }

        $this->info("Eligible movements: {$eligibleMovements}");
        $this->info("Created receipts: {$createdReceipts}");
        $this->info("Created lines: {$createdLines}");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, StockMovement>
     */
    private function eligibleMovements(string $companyId): Collection
    {
        $claimedMovementIds = GoodsReceiptLine::query()
            ->where('company_id', $companyId)
            ->pluck('movement_id')
            ->filter()
            ->merge(
                GoodsReceiptLine::query()
                    ->where('company_id', $companyId)
                    ->pluck('free_movement_id')
                    ->filter()
            )
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return StockMovement::query()
            ->where('company_id', $companyId)
            ->where('movement_type', MovementType::Receipt)
            ->where('reason', MovementReason::GoodsReceipt)
            ->where('reference_type', 'Document')
            ->whereNotNull('reference_id')
            ->when($claimedMovementIds !== [], fn ($query) => $query->whereNotIn('id', $claimedMovementIds))
            ->orderBy('reference_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     * @return array{int, int}
     */
    private function backfillCompany(Company $company, Collection $movements): array
    {
        $createdReceipts = 0;
        $createdLines = 0;

        /** @var array<string, list<StockMovement>> $groups */
        $groups = [];
        foreach ($movements as $movement) {
            $groups[$movement->reference_id.'|'.$movement->created_at?->toIso8601String()][] = $movement;
        }

        /** @var array<string, string> $invoicedRemaining */
        $invoicedRemaining = [];

        foreach ($groups as $groupMovements) {
            [$receiptCreated, $linesCreated] = DB::transaction(function () use ($company, $groupMovements, &$invoicedRemaining): array {
                $po = Document::query()
                    ->where('company_id', $company->id)
                    ->where('type', DocumentType::PurchaseOrder)
                    ->with('lines')
                    ->find($groupMovements[0]->reference_id);

                if (! $po instanceof Document) {
                    return [0, 0];
                }

                $receipt = GoodsReceipt::create([
                    'tenant_id' => $po->tenant_id,
                    'company_id' => $po->company_id,
                    'purchase_order_id' => $po->id,
                    'receipt_number' => $this->numberingService->generateForKey($po->tenant_id, $po->company_id, 'goods_receipt', 'GRN'),
                    'status' => GoodsReceiptStatus::Posted,
                    'received_at' => $groupMovements[0]->created_at,
                    'received_by' => null,
                    'payload' => ['backfilled_at' => now()->toIso8601String()],
                ]);

                $linesCreated = $this->createLinesForGroup($po, $receipt, $groupMovements, $invoicedRemaining);

                return [1, $linesCreated];
            });

            $createdReceipts += $receiptCreated;
            $createdLines += $linesCreated;
        }

        return [$createdReceipts, $createdLines];
    }

    /**
     * @param  list<StockMovement>  $movements
     * @param  array<string, string>  $invoicedRemaining
     */
    private function createLinesForGroup(Document $po, GoodsReceipt $receipt, array $movements, array &$invoicedRemaining): int
    {
        $linesCreated = 0;
        $paidMovements = array_values(array_filter(
            $movements,
            static fn (StockMovement $movement): bool => bccomp((string) $movement->unit_cost, '0.000000', 6) > 0,
        ));
        $freeMovements = array_values(array_filter(
            $movements,
            static fn (StockMovement $movement): bool => bccomp((string) $movement->unit_cost, '0.000000', 6) <= 0,
        ));

        foreach ($paidMovements as $paidMovement) {
            $poLine = $this->resolvePoLine($po, $paidMovement);
            if (! $poLine instanceof DocumentLine) {
                continue;
            }

            $freeMovement = $this->shiftMatchingFreeMovement($freeMovements, $paidMovement);
            $this->createReceiptLine($receipt, $poLine, $paidMovement, $freeMovement, $invoicedRemaining);
            $linesCreated++;
        }

        foreach ($freeMovements as $freeMovement) {
            $poLine = $this->resolvePoLine($po, $freeMovement);
            if (! $poLine instanceof DocumentLine) {
                continue;
            }

            $this->createReceiptLine($receipt, $poLine, null, $freeMovement, $invoicedRemaining);
            $linesCreated++;
        }

        return $linesCreated;
    }

    private function resolvePoLine(Document $po, StockMovement $movement): ?DocumentLine
    {
        $matches = $po->lines
            ->filter(fn (DocumentLine $line): bool => $line->product_id === $movement->product_id
                && (string) ($line->variant_id ?? '') === (string) ($movement->variant_id ?? ''))
            ->values();

        if ($matches->count() > 1) {
            $this->warn("PO {$po->document_number} has duplicate product PO lines; falling back to FIFO line assignment.");
        }

        if ($matches->isEmpty()) {
            return null;
        }

        return $matches->first();
    }

    /**
     * @param  list<StockMovement>  $freeMovements
     */
    private function shiftMatchingFreeMovement(array &$freeMovements, StockMovement $paidMovement): ?StockMovement
    {
        foreach ($freeMovements as $index => $freeMovement) {
            if ($freeMovement->product_id === $paidMovement->product_id
                && (string) ($freeMovement->variant_id ?? '') === (string) ($paidMovement->variant_id ?? '')) {
                unset($freeMovements[$index]);
                $freeMovements = array_values($freeMovements);

                return $freeMovement;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $invoicedRemaining
     */
    private function createReceiptLine(
        GoodsReceipt $receipt,
        DocumentLine $poLine,
        ?StockMovement $paidMovement,
        ?StockMovement $freeMovement,
        array &$invoicedRemaining,
    ): void {
        $poLineId = (string) $poLine->id;
        $receivedQty = $paidMovement !== null ? (string) $paidMovement->quantity : '0.0000';
        $freeQty = $freeMovement !== null ? (string) $freeMovement->quantity : '0.0000';
        $basis = (string) ($poLine->accrual_unit_cost ?? $poLine->landed_unit_cost ?? $poLine->unit_price);

        if (! array_key_exists($poLineId, $invoicedRemaining)) {
            $invoicedRemaining[$poLineId] = (string) ($poLine->quantity_invoiced ?? '0.0000');
        }

        $quantityInvoiced = '0.0000';
        if (bccomp($receivedQty, '0.0000', 4) > 0 && bccomp($invoicedRemaining[$poLineId], '0.0000', 4) > 0) {
            $quantityInvoiced = bccomp($invoicedRemaining[$poLineId], $receivedQty, 4) >= 0
                ? $receivedQty
                : $invoicedRemaining[$poLineId];
            $invoicedRemaining[$poLineId] = bcsub($invoicedRemaining[$poLineId], $quantityInvoiced, 4);
        }

        GoodsReceiptLine::create([
            'tenant_id' => $receipt->tenant_id,
            'company_id' => $receipt->company_id,
            'goods_receipt_id' => $receipt->id,
            'po_line_id' => $poLine->id,
            'product_id' => $poLine->product_id,
            'variant_id' => $poLine->variant_id,
            'received_qty' => $receivedQty,
            'free_qty' => $freeQty,
            'received_unit_price' => null,
            'landed_unit_cost' => CurrencyScale::bcround($basis, 6),
            'accrual_unit_cost' => CurrencyScale::bcround($basis, 6),
            'effective_unit_cost' => $this->effectiveUnitCost($receivedQty, $freeQty, $basis),
            'movement_id' => $paidMovement?->id,
            'free_movement_id' => $freeMovement?->id,
            'quantity_invoiced' => $quantityInvoiced,
        ]);
    }

    private function effectiveUnitCost(string $receivedQty, string $freeQty, string $basis): string
    {
        $totalQty = bcadd($receivedQty, $freeQty, 4);

        if (bccomp($receivedQty, '0.0000', 4) <= 0 || bccomp($totalQty, '0.0000', 4) <= 0) {
            return '0.000000';
        }

        return CurrencyScale::bcround(bcdiv(bcmul($receivedQty, $basis, 10), $totalQty, 10), 6);
    }
}
