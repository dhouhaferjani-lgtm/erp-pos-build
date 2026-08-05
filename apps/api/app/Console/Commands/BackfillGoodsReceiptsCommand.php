<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tenant-isolation: cat-(a-per-tenant-iter) behind an EXPLICIT scope,
 * converted 2026-08-05 (cat-(b) wave 2).
 *
 * `Company::query()` at the top of the command ran on the console's CENTRAL
 * connection. `companies`, `goods_receipt_lines` and `stock_movements` are all
 * TENANT tables, so after the 2026-05-28 database-per-tenant flip the backfill
 * raised 42P01 and synthesized nothing.
 *
 * A one-shot ledger backfill that silently skips a tenant leaves a permanent
 * hole in the GR/IR ledger, so the scope must be named: `--tenant=<uuid>` or
 * `--all-tenants`. `--company` remains an in-tenant narrowing filter.
 *
 * The receipt-synthesis LOGIC is untouched.
 */
final class BackfillGoodsReceiptsCommand extends TenantScopedCommand
{
    protected $signature = 'procurement:backfill-goods-receipts
        {--tenant= : Tenant UUID to backfill (required unless --all-tenants)}
        {--all-tenants : Deliberate fleet-wide run over every reachable tenant}
        {--company= : Specific company UUID to backfill}
        {--dry-run : Count eligible movements without writing receipt rows}';

    protected $description = 'Backfill goods_receipts and goods_receipt_lines from historical purchase stock movements';

    public function __construct(
        CompanyContext $companyContext,
        private readonly DocumentNumberingService $numberingService,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $companyFilter = $this->stringOption('company');

        if ($dryRun) {
            $this->warn('Dry run: no receipt rows will be written.');
        }

        $companiesSeen = 0;
        $eligibleMovements = 0;
        $createdReceipts = 0;
        $createdLines = 0;
        $skippedUnmappableGroups = 0;

        $exit = $this->forEachExplicitlySelectedTenant(
            $this->stringOption('tenant'),
            $this->option('all-tenants') === true,
            function (Tenant $tenant) use (
                $dryRun,
                $companyFilter,
                &$companiesSeen,
                &$eligibleMovements,
                &$createdReceipts,
                &$createdLines,
                &$skippedUnmappableGroups,
            ): int {
                // The explicit tenant_id predicate is redundant under
                // database-per-tenant and load-bearing in single-schema
                // compatibility mode.
                $companies = Company::query()
                    ->where('tenant_id', $tenant->id)
                    ->when($companyFilter !== null, fn ($query) => $query->where('id', $companyFilter))
                    ->orderBy('id')
                    ->get();

                $companiesSeen += $companies->count();

                foreach ($companies as $company) {
                    $movements = $this->eligibleMovements((string) $company->id);
                    $eligibleMovements += $movements->count();

                    if ($dryRun || $movements->isEmpty()) {
                        continue;
                    }

                    [$receipts, $lines, $skipped] = $this->backfillCompany($company, $movements);
                    $createdReceipts += $receipts;
                    $createdLines += $lines;
                    $skippedUnmappableGroups += $skipped;
                }

                return self::SUCCESS;
            },
        );

        if ($exit !== self::SUCCESS) {
            return $exit;
        }

        if ($companiesSeen === 0) {
            $this->error('No companies found.');

            return self::FAILURE;
        }

        $this->info("Eligible movements: {$eligibleMovements}");
        $this->info("Created receipts: {$createdReceipts}");
        $this->info("Created lines: {$createdLines}");
        $this->info("Skipped unmappable groups: {$skippedUnmappableGroups}");

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
            ->where('reference_type', 'Document')
            ->whereNotNull('reference_id')
            ->whereIn('reference_id', Document::query()
                ->select('id')
                ->where('company_id', $companyId)
                ->where('type', DocumentType::PurchaseOrder))
            ->when($claimedMovementIds !== [], fn ($query) => $query->whereNotIn('id', $claimedMovementIds))
            ->orderBy('reference_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     * @return array{int, int, int}
     */
    private function backfillCompany(Company $company, Collection $movements): array
    {
        $createdReceipts = 0;
        $createdLines = 0;
        $skippedUnmappableGroups = 0;

        $groups = $this->clusterMovementsByReceiptBatch($movements);

        /** @var array<string, string> $assignedPaidQuantities */
        $assignedPaidQuantities = [];
        /** @var array<string, string> $assignedFreeQuantities */
        $assignedFreeQuantities = [];

        foreach ($groups as $groupMovements) {
            // Reseed the invoiced remainder from the DB per group (inside the txn) rather than
            // carrying an in-memory tally across groups/runs. A resumed run that backfills new
            // movements for an already-partially-backfilled po_line must not re-apportion the
            // full quantity_invoiced; and a rolled-back group leaves no stale carryover.
            /** @var array<string, string> $invoicedRemaining */
            $invoicedRemaining = [];

            try {
                [$receiptCreated, $linesCreated, $skipped] = DB::transaction(function () use (
                    $company,
                    $groupMovements,
                    &$invoicedRemaining,
                    &$assignedPaidQuantities,
                    &$assignedFreeQuantities
                ): array {
                    $po = Document::query()
                        ->where('company_id', $company->id)
                        ->where('type', DocumentType::PurchaseOrder)
                        ->with('lines')
                        ->find($groupMovements[0]->reference_id);

                    if (! $po instanceof Document) {
                        return [0, 0, 1];
                    }

                    $linePlans = $this->planLinesForGroup($po, $groupMovements, $assignedPaidQuantities, $assignedFreeQuantities);
                    if ($linePlans === []) {
                        $this->warn("PO {$po->document_number} has an unmappable goods-receipt movement group; no header was created.");

                        return [0, 0, 1];
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

                    foreach ($linePlans as $linePlan) {
                        $this->createReceiptLine(
                            $receipt,
                            $linePlan['po_line'],
                            $linePlan['paid_movement'],
                            $linePlan['free_movement'],
                            $invoicedRemaining,
                        );
                    }

                    $this->warnOnCounterMismatch($po);

                    return [1, count($linePlans), 0];
                });
            } catch (QueryException $exception) {
                if (! $this->isMovementClaimUniqueViolation($exception)) {
                    throw $exception;
                }

                $this->warn('A goods-receipt movement was already claimed; skipping the concurrently claimed group.');
                [$receiptCreated, $linesCreated, $skipped] = [0, 0, 0];
            }

            $createdReceipts += $receiptCreated;
            $createdLines += $linesCreated;
            $skippedUnmappableGroups += $skipped;
        }

        return [$createdReceipts, $createdLines, $skippedUnmappableGroups];
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     * @return list<list<StockMovement>>
     */
    private function clusterMovementsByReceiptBatch(Collection $movements): array
    {
        /** @var list<list<StockMovement>> $groups */
        $groups = [];
        $currentReferenceId = null;
        $currentGroup = [];
        $previousCreatedAt = null;

        foreach ($movements as $movement) {
            $createdAt = $movement->created_at;
            $startsNewGroup = $currentGroup === []
                || $currentReferenceId !== $movement->reference_id
                || $createdAt === null
                || $previousCreatedAt === null
                || $createdAt->diffInSeconds($previousCreatedAt, true) > 5;

            if ($startsNewGroup && $currentGroup !== []) {
                $groups[] = $currentGroup;
                $currentGroup = [];
            }

            $currentReferenceId = $movement->reference_id;
            $previousCreatedAt = $createdAt;
            $currentGroup[] = $movement;
        }

        if ($currentGroup !== []) {
            $groups[] = $currentGroup;
        }

        return $groups;
    }

    /**
     * @param  list<StockMovement>  $movements
     * @param  array<string, string>  $assignedPaidQuantities
     * @param  array<string, string>  $assignedFreeQuantities
     * @return list<array{po_line: DocumentLine, paid_movement: ?StockMovement, free_movement: ?StockMovement}>
     */
    private function planLinesForGroup(
        Document $po,
        array $movements,
        array &$assignedPaidQuantities,
        array &$assignedFreeQuantities,
    ): array {
        $linePlans = [];
        $paidMovements = array_values(array_filter(
            $movements,
            fn (StockMovement $movement): bool => bccomp($this->decimalString($movement->unit_cost, 6), '0.000000', 6) > 0,
        ));
        $freeMovements = array_values(array_filter(
            $movements,
            fn (StockMovement $movement): bool => bccomp($this->decimalString($movement->unit_cost, 6), '0.000000', 6) <= 0,
        ));

        foreach ($paidMovements as $paidMovement) {
            $poLine = $this->resolvePoLine($po, $paidMovement, $assignedPaidQuantities, false);
            if (! $poLine instanceof DocumentLine) {
                continue;
            }

            $freeMovement = $this->shiftMatchingFreeMovement($freeMovements, $paidMovement);
            if ($freeMovement instanceof StockMovement) {
                $this->consumeLineCapacity($poLine, $this->decimalString($freeMovement->quantity, 4), $assignedFreeQuantities);
            }
            $linePlans[] = [
                'po_line' => $poLine,
                'paid_movement' => $paidMovement,
                'free_movement' => $freeMovement,
            ];
        }

        foreach ($freeMovements as $freeMovement) {
            $poLine = $this->resolvePoLine($po, $freeMovement, $assignedFreeQuantities, true);
            if (! $poLine instanceof DocumentLine) {
                continue;
            }

            $linePlans[] = [
                'po_line' => $poLine,
                'paid_movement' => null,
                'free_movement' => $freeMovement,
            ];
        }

        return $linePlans;
    }

    /**
     * @param  array<string, string>  $assignedQuantities
     */
    private function resolvePoLine(Document $po, StockMovement $movement, array &$assignedQuantities, bool $free): ?DocumentLine
    {
        $matches = $po->lines
            ->filter(fn (DocumentLine $line): bool => $line->product_id === $movement->product_id
                && (string) ($line->variant_id ?? '') === (string) ($movement->variant_id ?? ''))
            ->sortBy('line_number')
            ->values();

        if ($matches->count() > 1) {
            $this->warn("PO {$po->document_number} has duplicate product PO lines; falling back to FIFO line assignment.");
        }

        if ($matches->isEmpty()) {
            return null;
        }

        foreach ($matches as $line) {
            $freeCapacity = $this->decimalString($line->free_quantity, 4);
            $capacity = $free && bccomp($freeCapacity, '0.0000', 4) > 0
                ? $freeCapacity
                : $this->decimalString($line->quantity, 4);
            $alreadyAssigned = $this->decimalString($assignedQuantities[(string) $line->id] ?? '0.0000', 4);
            $remaining = bcsub($capacity, $alreadyAssigned, 4);

            if (bccomp($remaining, '0.0000', 4) <= 0) {
                continue;
            }

            $movementQuantity = $this->decimalString($movement->quantity, 4);
            if (bccomp($movementQuantity, $remaining, 4) > 0 && $matches->count() > 1) {
                continue;
            }

            $this->consumeLineCapacity($line, $movementQuantity, $assignedQuantities);

            return $line;
        }

        return null;
    }

    /**
     * @param  array<string, string>  $assignedQuantities
     * @param  numeric-string  $quantity
     */
    private function consumeLineCapacity(DocumentLine $line, string $quantity, array &$assignedQuantities): void
    {
        $lineId = (string) $line->id;
        $assignedQuantities[$lineId] = bcadd($this->decimalString($assignedQuantities[$lineId] ?? '0.0000', 4), $quantity, 4);
    }

    /**
     * @param  array<int, StockMovement>  $freeMovements
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
        $receivedQty = $paidMovement !== null ? $this->decimalString($paidMovement->quantity, 4) : '0.0000';
        $freeQty = $freeMovement !== null ? $this->decimalString($freeMovement->quantity, 4) : '0.0000';
        $basis = $this->decimalString($poLine->accrual_unit_cost ?? $poLine->landed_unit_cost ?? $poLine->unit_price, 6);

        if (! array_key_exists($poLineId, $invoicedRemaining)) {
            $alreadyInvoiced = GoodsReceiptLine::query()
                ->where('po_line_id', $poLine->id)
                ->selectRaw('COALESCE(SUM(quantity_invoiced), 0) as invoiced')
                ->first();

            $remainingInvoiced = bcsub(
                $this->decimalString($poLine->quantity_invoiced, 4),
                $this->decimalString($alreadyInvoiced->invoiced ?? '0', 4),
                4,
            );

            $invoicedRemaining[$poLineId] = bccomp($remainingInvoiced, '0.0000', 4) > 0
                ? $remainingInvoiced
                : '0.0000';
        }

        $quantityInvoiced = '0.0000';
        $invoicedRemainingForLine = $this->decimalString($invoicedRemaining[$poLineId], 4);
        if (bccomp($receivedQty, '0.0000', 4) > 0 && bccomp($invoicedRemainingForLine, '0.0000', 4) > 0) {
            $quantityInvoiced = bccomp($invoicedRemainingForLine, $receivedQty, 4) >= 0
                ? $receivedQty
                : $invoicedRemainingForLine;
            $invoicedRemaining[$poLineId] = bcsub($invoicedRemainingForLine, $quantityInvoiced, 4);
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

    private function warnOnCounterMismatch(Document $po): void
    {
        foreach ($po->lines as $poLine) {
            $received = GoodsReceiptLine::query()
                ->postedReceipts()
                ->where('po_line_id', $poLine->id)
                ->selectRaw('COALESCE(SUM(received_qty), 0) as received_qty, COALESCE(SUM(free_qty), 0) as free_qty')
                ->first();

            $synthesizedReceived = $this->decimalString($received->received_qty ?? '0.0000', 4);
            $synthesizedFree = $this->decimalString($received->free_qty ?? '0.0000', 4);
            $poReceived = $this->decimalString($poLine->quantity_received, 4);
            $poFree = $this->decimalString($poLine->free_quantity_received, 4);

            if (bccomp($synthesizedReceived, $poReceived, 4) === 0 && bccomp($synthesizedFree, $poFree, 4) === 0) {
                continue;
            }

            $message = "PO {$po->document_number} synthesized received/free quantities do not match PO counters";
            $this->warn($message);
            Log::warning($message, [
                'purchase_order_id' => $po->id,
                'po_line_id' => $poLine->id,
                'synthesized_received_qty' => $synthesizedReceived,
                'po_received_qty' => $poReceived,
                'synthesized_free_qty' => $synthesizedFree,
                'po_free_qty' => $poFree,
            ]);
        }
    }

    private function isMovementClaimUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        $isUniqueViolation = $exception->getCode() === '23505'
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'unique');

        if (! $isUniqueViolation) {
            return false;
        }

        return str_contains($message, 'goods_receipt_lines_movement_id_unique')
            || str_contains($message, 'goods_receipt_lines_free_movement_id_unique')
            || str_contains($message, 'goods_receipt_lines.movement_id')
            || str_contains($message, 'goods_receipt_lines.free_movement_id');
    }

    /**
     * @param  numeric-string  $receivedQty
     * @param  numeric-string  $freeQty
     * @param  numeric-string  $basis
     * @return numeric-string
     */
    private function effectiveUnitCost(string $receivedQty, string $freeQty, string $basis): string
    {
        $totalQty = bcadd($receivedQty, $freeQty, 4);

        if (bccomp($receivedQty, '0.0000', 4) <= 0 || bccomp($totalQty, '0.0000', 4) <= 0) {
            return '0.000000';
        }

        return CurrencyScale::bcround(bcdiv(bcmul($receivedQty, $basis, 10), $totalQty, 10), 6);
    }

    /**
     * @return numeric-string
     */
    private function decimalString(mixed $value, int $scale): string
    {
        if ($value === null || $value === '') {
            return CurrencyScale::bcformatStrict('0', $scale);
        }

        return CurrencyScale::bcformatStrict((string) $value, $scale);
    }
}
