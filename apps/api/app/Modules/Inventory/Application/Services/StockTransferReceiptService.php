<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Inventory\Application\DTOs\MovementGlContext;
use App\Modules\Inventory\Domain\Enums\MovementGlKind;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Enums\TransferActorRole;
use App\Modules\Inventory\Domain\Enums\TransferCloseDisposition;
use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use App\Modules\Inventory\Domain\Enums\TransferReceiptFailureReason as Failure;
use App\Modules\Inventory\Domain\Enums\TransferReceiptKind;
use App\Modules\Inventory\Domain\Enums\TransferReceiptStatus;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Events\StockTransferClosedV1;
use App\Modules\Inventory\Domain\Events\StockTransferCompleted;
use App\Modules\Inventory\Domain\Events\StockTransferReceiptLineRecordedV1;
use App\Modules\Inventory\Domain\Events\StockTransferReceiptPosted;
use App\Modules\Inventory\Domain\Events\StockTransferReceivedV1;
use App\Modules\Inventory\Domain\Exceptions\TransferReceiptFailureException;
use App\Modules\Inventory\Domain\Exceptions\TransferStateException;
use App\Modules\Inventory\Domain\Exceptions\UnsupportedValuationModeException;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Domain\StockTransferLine;
use App\Modules\Inventory\Domain\StockTransferLineBatchAllocation;
use App\Modules\Inventory\Domain\StockTransferReceipt;
use App\Modules\Inventory\Domain\StockTransferReceiptLine;
use App\Modules\Inventory\Domain\StockTransferReceiptLineLot;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use App\Shared\Domain\QuantityScale;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Repositories\StoredEventRepository;
use Throwable;

/**
 * The receipt document root owns row/product locks, stock, counters, events and GL.
 *
 * @phpstan-import-type ReceivePayload from ReceiptPayloadCanonicalizer
 * @phpstan-import-type ReceiveLine from ReceiptPayloadCanonicalizer
 * @phpstan-import-type ClosePayload from ReceiptPayloadCanonicalizer
 *
 * @phpstan-type Quantities array{received: numeric-string, damaged: numeric-string, written_off: numeric-string, returned: numeric-string}
 * @phpstan-type LotPosting array{allocation: StockTransferLineBatchAllocation, quantities: Quantities}
 * @phpstan-type Posting array{line: StockTransferLine, quantities: Quantities, reason: TransferDiscrepancyReason|null, note: string|null, lots: list<LotPosting>}
 * @phpstan-type RecordedLine array{line: StockTransferReceiptLine, previousReceived: numeric-string, previousDamaged: numeric-string, remaining: numeric-string, lots: list<StockTransferReceiptLineLot>}
 */
final class StockTransferReceiptService
{
    private const SEQUENCE_KEY = 'transfer_receipt';

    private const SEQUENCE_PREFIX = 'TRR';

    public function __construct(
        private readonly StockTransferMovementSupport $movementSupport,
        private readonly StockAdjustmentService $stockAdjustmentService,
        private readonly InventoryGlPostingBuffer $glBuffer,
        private readonly InventoryValuationModeResolver $valuationMode,
        private readonly ReceiptPayloadCanonicalizer $canonicalizer,
        private readonly StoredEventRepository $storedEvents,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly ProductCostLock $costLock,
        private readonly DocumentNumberingService $numberingService,
        private readonly GeneralLedgerService $generalLedger,
    ) {}

    /** @param ReceivePayload $payload */
    public function receive(string $transferId, string $userId, array $payload, string $tenantId, string $companyId): TransferReceiptResult
    {
        return $this->transact($transferId, $userId, $payload, TransferReceiptKind::Receipt, $tenantId, $companyId);
    }

    /** @param ClosePayload $payload */
    public function close(string $transferId, string $userId, array $payload, string $tenantId, string $companyId): TransferReceiptResult
    {
        return $this->transact($transferId, $userId, $payload, TransferReceiptKind::Close, $tenantId, $companyId);
    }

    public function receiveAllRemaining(string $transferId, string $userId, string $idempotencyKey, string $tenantId, string $companyId): TransferReceiptResult
    {
        return $this->transact($transferId, $userId, null, TransferReceiptKind::Receipt, $tenantId, $companyId, $idempotencyKey);
    }

    /** @param ReceivePayload|ClosePayload|null $input */
    private function transact(string $transferId, string $userId, ?array $input, TransferReceiptKind $kind, string $tenantId, string $companyId, ?string $serverKey = null): TransferReceiptResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('StockTransferReceiptService must be the outermost transaction');
        }
        $key = $input['idempotency_key'] ?? $serverKey;
        if ($key === null) {
            throw new \InvalidArgumentException('A receipt key is required.');
        }
        if (! Str::isUuid($transferId)) {
            throw (new ModelNotFoundException)->setModel(StockTransfer::class, [$transferId]);
        }
        $identity = StockTransfer::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)->findOrFail($transferId);
        $hash = $input === null ? null : $this->canonicalizer->hash($input);
        try {
            return DB::transaction(function () use ($identity, $userId, $input, $kind, $key, $hash): TransferReceiptResult {
                $marker = $this->glBuffer->mark();
                try {
                    $transfer = $this->movementSupport->lockTransfer($identity);
                    $existing = $this->findReceipt($transfer, $key);
                    if ($existing !== null) {
                        return $this->replay($existing, $transfer, $hash, $kind);
                    }
                    if (! $transfer->status->canReceive()) {
                        throw new TransferStateException($transfer->id, $transfer->status, $kind === TransferReceiptKind::Close ? 'close' : 'receive');
                    }
                    $ids = [];
                    foreach ($transfer->lines as $line) {
                        $ids[] = $line->product_id;
                    }

                    return $this->costLock->acquire($transfer->tenant_id, $transfer->company_id, array_values(array_unique($ids)), function () use ($transfer, $userId, $input, $kind, $key): TransferReceiptResult {
                        $transfer->loadMissing('company', 'lines.product', 'lines.batchAllocations.batch');
                        $payload = $input ?? $this->completionPayload($transfer, $key);
                        $postings = $kind === TransferReceiptKind::Close ? $this->validateClose($transfer, $payload) : $this->validateReceive($transfer, $payload);
                        if ($postings === []) {
                            throw new TransferReceiptFailureException(Failure::NothingToReceive);
                        }
                        foreach ($postings as $posting) {
                            if (bccomp(bcadd($posting['quantities']['damaged'], $posting['quantities']['written_off'], QuantityScale::SCALE), '0', QuantityScale::SCALE) > 0) {
                                try {
                                    $this->valuationMode->requirePerpetual($transfer->company_id);
                                } catch (UnsupportedValuationModeException) {
                                    throw new TransferReceiptFailureException(Failure::ValuationModeUnsupported);
                                }
                                if (! $this->generalLedger->hasInventoryWriteOffAccounts($transfer->company_id)) {
                                    throw new TransferReceiptFailureException(Failure::GlAccountsUnmapped);
                                }
                                break;
                            }
                        }

                        return $this->post($transfer, $userId, $payload, $kind, $postings);
                    });
                } catch (Throwable $exception) {
                    $this->glBuffer->rollbackTo(min($marker, $this->glBuffer->mark()));
                    throw $exception;
                }
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findReceipt($identity, $key);
            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $identity->refresh(), $hash, $kind);
        }
    }

    private function findReceipt(StockTransfer $transfer, string $key): ?StockTransferReceipt
    {
        return StockTransferReceipt::query()->where('tenant_id', $transfer->tenant_id)->where('company_id', $transfer->company_id)->where('idempotency_key', $key)->first();
    }

    private function replay(StockTransferReceipt $receipt, StockTransfer $transfer, ?string $hash, TransferReceiptKind $kind): TransferReceiptResult
    {
        if ($receipt->transfer_id !== $transfer->id || $receipt->kind !== $kind || ($hash !== null && ! hash_equals($receipt->payload_hash, $hash))) {
            throw new TransferReceiptFailureException(Failure::IdempotencyKeyReused);
        }

        return new TransferReceiptResult($receipt->load('lines.lots.batch', 'receivedBy'), $transfer, true);
    }

    /** @return ReceivePayload */
    private function completionPayload(StockTransfer $transfer, string $key): array
    {
        $lines = [];
        foreach ($transfer->lines as $line) {
            if (bccomp($line->remainingQuantity(), '0', QuantityScale::SCALE) <= 0) {
                continue;
            }
            $lots = [];
            foreach ($line->batchAllocations as $allocation) {
                if (bccomp($allocation->remainingQuantity(), '0', QuantityScale::SCALE) > 0) {
                    $lots[] = ['batch_id' => $allocation->batch_id, 'quantity_received' => $allocation->remainingQuantity(), 'quantity_damaged' => '0.0000'];
                }
            }
            $lines[] = ['transfer_line_id' => $line->id, 'quantity_received' => $line->remainingQuantity(), 'quantity_damaged' => '0.0000', 'lots' => $lots];
        }

        return ['idempotency_key' => $key, 'lines' => $lines];
    }

    /**
     * @param  ReceivePayload|ClosePayload  $payload
     * @return list<Posting>
     */
    private function validateReceive(StockTransfer $transfer, array $payload): array
    {
        if (! isset($payload['lines'])) {
            throw new \InvalidArgumentException('Receipt lines are required.');
        }
        $postings = [];
        foreach ($payload['lines'] as $input) {
            $line = $transfer->lines->firstWhere('id', $input['transfer_line_id']);
            $details = ['transfer_line_id' => $input['transfer_line_id']];
            if ($line === null || $line->company_id !== $transfer->company_id || $line->tenant_id !== $transfer->tenant_id) {
                throw new TransferReceiptFailureException(Failure::LineNotOnTransfer, $details);
            }
            $q = $this->quantities(received: (string) $input['quantity_received'], damaged: (string) $input['quantity_damaged']);
            $amount = bcadd($q['received'], $q['damaged'], QuantityScale::SCALE);
            if (bccomp($line->remainingQuantity(), '0', QuantityScale::SCALE) <= 0) {
                throw new TransferReceiptFailureException(Failure::NothingToReceive, $details);
            }
            if (bccomp($amount, $line->remainingQuantity(), QuantityScale::SCALE) > 0) {
                throw new TransferReceiptFailureException(Failure::OverReceipt, $details);
            }
            $tracked = $line->batchAllocations->isNotEmpty();
            if ($line->product->requires_batch_tracking && ! $tracked) {
                throw new TransferReceiptFailureException(Failure::LotTrackingMismatch, $details);
            }
            if ($tracked && empty($input['lots'])) {
                throw new TransferReceiptFailureException(Failure::LotRequired, $details);
            }
            if (! $tracked && ! empty($input['lots'])) {
                throw new TransferReceiptFailureException(Failure::LotNotAllowed, $details);
            }
            $reason = isset($input['discrepancy_reason']) ? TransferDiscrepancyReason::tryFrom($input['discrepancy_reason']) : null;
            $damaged = bccomp($q['damaged'], '0', QuantityScale::SCALE) > 0;
            if ($damaged && ! isset($input['discrepancy_reason'])) {
                throw new TransferReceiptFailureException(Failure::DiscrepancyReasonRequired, $details);
            }
            if (($damaged && ($reason === null || ! $reason->allowedFor(TransferReceiptKind::Receipt))) || (! $damaged && isset($input['discrepancy_reason']))) {
                throw new TransferReceiptFailureException(Failure::DiscrepancyReasonInvalid, $details);
            }
            $lots = [];
            $receivedSum = '0';
            $damagedSum = '0';
            foreach ($input['lots'] ?? [] as $lot) {
                $allocation = $line->batchAllocations->firstWhere('batch_id', $lot['batch_id']);
                $lotDetails = $details + ['batch_id' => $lot['batch_id']];
                if ($allocation === null) {
                    throw new TransferReceiptFailureException(Failure::UnknownLot, $lotDetails);
                }
                $lotQ = $this->quantities(received: (string) $lot['quantity_received'], damaged: (string) $lot['quantity_damaged']);
                if (bccomp(bcadd($lotQ['received'], $lotQ['damaged'], QuantityScale::SCALE), $allocation->remainingQuantity(), QuantityScale::SCALE) > 0) {
                    throw new TransferReceiptFailureException(Failure::LotOverReceipt, $lotDetails);
                }
                $receivedSum = bcadd($receivedSum, $lotQ['received'], QuantityScale::SCALE);
                $damagedSum = bcadd($damagedSum, $lotQ['damaged'], QuantityScale::SCALE);
                $lots[] = ['allocation' => $allocation, 'quantities' => $lotQ];
            }
            if ($tracked && (bccomp($receivedSum, $q['received'], QuantityScale::SCALE) !== 0 || bccomp($damagedSum, $q['damaged'], QuantityScale::SCALE) !== 0)) {
                throw new TransferReceiptFailureException(Failure::LotSumMismatch, $details);
            }
            $postings[] = ['line' => $line, 'quantities' => $q, 'reason' => $reason, 'note' => $input['discrepancy_note'] ?? null, 'lots' => $lots];
        }

        return $postings;
    }

    /**
     * @param  ReceivePayload|ClosePayload  $payload
     * @return list<Posting>
     */
    private function validateClose(StockTransfer $transfer, array $payload): array
    {
        $disposition = isset($payload['disposition']) ? TransferCloseDisposition::tryFrom($payload['disposition']) : null;
        if ($disposition === null) {
            throw new TransferReceiptFailureException(Failure::DispositionRequired);
        }
        $reason = TransferDiscrepancyReason::tryFrom($payload['reason']);
        if ($reason === null) {
            throw new TransferReceiptFailureException(Failure::DiscrepancyReasonRequired);
        }
        $postings = [];
        foreach ($transfer->lines as $line) {
            $remaining = $line->remainingQuantity();
            if (bccomp($remaining, '0', QuantityScale::SCALE) <= 0) {
                continue;
            }
            if ($line->product->requires_batch_tracking && $line->batchAllocations->isEmpty()) {
                throw new TransferReceiptFailureException(Failure::LotTrackingMismatch, ['transfer_line_id' => $line->id]);
            }
            $column = $disposition === TransferCloseDisposition::WriteOff ? 'written_off' : 'returned';
            $q = $this->quantities();
            $q[$column] = $remaining;
            $lots = [];
            $lotTotal = '0';
            foreach ($line->batchAllocations as $allocation) {
                $lotRemaining = $allocation->remainingQuantity();
                if (bccomp($lotRemaining, '0', QuantityScale::SCALE) <= 0) {
                    continue;
                }
                $lotQ = $this->quantities();
                $lotQ[$column] = $lotRemaining;
                $lots[] = ['allocation' => $allocation, 'quantities' => $lotQ];
                $lotTotal = bcadd($lotTotal, $lotRemaining, QuantityScale::SCALE);
            }
            if ($line->batchAllocations->isNotEmpty() && bccomp($lotTotal, $remaining, QuantityScale::SCALE) !== 0) {
                throw new TransferReceiptFailureException(Failure::LotSumMismatch, ['transfer_line_id' => $line->id]);
            }
            $postings[] = ['line' => $line, 'quantities' => $q, 'reason' => $reason, 'note' => $payload['note'] ?? null, 'lots' => $lots];
        }

        return $postings;
    }

    /**
     * @param  numeric-string  $received
     * @param  numeric-string  $damaged
     * @return Quantities
     */
    private function quantities(string $received = '0', string $damaged = '0'): array
    {
        return ['received' => bcadd($received, '0', QuantityScale::SCALE), 'damaged' => bcadd($damaged, '0', QuantityScale::SCALE), 'written_off' => '0.0000', 'returned' => '0.0000'];
    }

    /**
     * @param  ReceivePayload|ClosePayload  $payload
     * @param  list<Posting>  $postings
     */
    private function post(StockTransfer $transfer, string $userId, array $payload, TransferReceiptKind $kind, array $postings): TransferReceiptResult
    {
        $previousStatus = $transfer->status->value;
        $receipt = new StockTransferReceipt;
        $receipt->id = (string) Str::orderedUuid();
        $receipt->fill([
            'tenant_id' => $transfer->tenant_id, 'company_id' => $transfer->company_id, 'transfer_id' => $transfer->id,
            'receipt_number' => $this->numberingService->generateForKey($transfer->tenant_id, $transfer->company_id, self::SEQUENCE_KEY, self::SEQUENCE_PREFIX),
            'kind' => $kind, 'disposition' => isset($payload['disposition']) ? TransferCloseDisposition::from($payload['disposition']) : null,
            'status' => TransferReceiptStatus::Posted, 'sequence' => $transfer->receipts()->count() + 1,
            'is_blind' => false, 'has_discrepancy' => false, 'idempotency_key' => $payload['idempotency_key'],
            'payload_hash' => $this->canonicalizer->hash($payload), 'received_by_user_id' => $userId, 'received_at' => now()->startOfSecond(),
            'notes' => $kind === TransferReceiptKind::Close ? ($payload['note'] ?? null) : ($payload['notes'] ?? null),
        ]);
        $recorded = [];
        $totals = $this->quantities();
        $discrepancyCount = 0;
        foreach ($postings as $posting) {
            $line = $posting['line'];
            $q = $posting['quantities'];
            $receiptLine = new StockTransferReceiptLine;
            $receiptLine->id = (string) Str::orderedUuid();
            $receiptLine->fill([
                'receipt_id' => $receipt->id, 'transfer_line_id' => $line->id, 'tenant_id' => $transfer->tenant_id,
                'company_id' => $transfer->company_id, 'product_id' => $line->product_id, 'variant_id' => $line->variant_id,
                'is_lot_tracked' => $line->batchAllocations->isNotEmpty(), 'quantity_sent_snapshot' => $line->quantity,
                'discrepancy_reason' => $posting['reason'], 'discrepancy_note' => $posting['note'],
            ]);
            $previousReceived = $line->quantity_received;
            $previousDamaged = $line->quantity_damaged;
            $lotRows = [];
            if ($receiptLine->is_lot_tracked) {
                $receiptLine->fill(['in_movement_id' => null, 'scrap_movement_id' => null, 'return_movement_id' => null]);
                foreach ($posting['lots'] as $lotPosting) {
                    $allocation = $lotPosting['allocation'];
                    $lot = new StockTransferReceiptLineLot;
                    $lot->id = (string) Str::orderedUuid();
                    $lot->fill(['receipt_line_id' => $receiptLine->id, 'batch_allocation_id' => $allocation->id,
                        'tenant_id' => $transfer->tenant_id, 'company_id' => $transfer->company_id, 'batch_id' => $allocation->batch_id]);
                    $lot->fill($this->writeMovements($transfer, $line, $receipt, $lotPosting['quantities'], $allocation));
                    foreach ($lotPosting['quantities'] as $name => $quantity) {
                        $column = 'quantity_'.$name;
                        $lot->setAttribute($column, $quantity);
                    }
                    $this->addCounters($allocation, $lotPosting['quantities']);
                    $allocation->save();
                    $lotRows[] = $lot;
                }
            } else {
                $receiptLine->fill($this->writeMovements($transfer, $line, $receipt, $q));
            }
            foreach ($q as $name => $quantity) {
                $column = 'quantity_'.$name;
                $receiptLine->setAttribute($column, $quantity);
                $totals[$name] = bcadd($totals[$name], $quantity, QuantityScale::SCALE);
            }
            $this->addCounters($line, $q);
            $line->save();
            if (bccomp(bcadd(bcadd($q['damaged'], $q['written_off'], QuantityScale::SCALE), $q['returned'], QuantityScale::SCALE), '0', QuantityScale::SCALE) > 0) {
                $discrepancyCount++;
            }
            $recorded[] = ['line' => $receiptLine, 'previousReceived' => $previousReceived, 'previousDamaged' => $previousDamaged, 'remaining' => $line->remainingQuantity(), 'lots' => $lotRows];
        }
        $receipt->has_discrepancy = $discrepancyCount > 0;
        $receipt->save();
        $receipt->refresh();
        foreach ($recorded as $record) {
            $record['line']->save();
            foreach ($record['lots'] as $lot) {
                $lot->save();
            }
        }
        if ($kind === TransferReceiptKind::Close) {
            $transfer->fill(['status' => $receipt->disposition === TransferCloseDisposition::WriteOff ? TransferStatus::ClosedWithWriteoff : TransferStatus::ClosedReturned,
                'closed_by_user_id' => $userId, 'closed_at' => $receipt->received_at, 'close_disposition' => $receipt->disposition,
                'close_reason' => $postings[0]['reason'], 'close_note' => $receipt->notes]);
        } else {
            $remaining = '0';
            foreach ($transfer->lines as $line) {
                $remaining = bcadd($remaining, $line->remainingQuantity(), QuantityScale::SCALE);
            }
            $transfer->status = bccomp($remaining, '0', QuantityScale::SCALE) === 0 ? TransferStatus::Completed : TransferStatus::PartiallyReceived;
            if ($transfer->status === TransferStatus::Completed) {
                $transfer->completed_at = $receipt->received_at;
                $transfer->completed_by_user_id = $userId;
            }
        }
        $transfer->save();
        if ($transfer->status->isTerminal()) {
            $this->capitalizeFreight($transfer);
        }
        $transfer->refresh();
        $this->persistEvents($transfer, $receipt, $recorded, $totals, $previousStatus);
        $this->glBuffer->flushIfOutermost();
        DB::afterCommit(function () use ($transfer, $receipt, $discrepancyCount, $userId): void {
            event(new StockTransferReceiptPosted($receipt->id, $transfer->id, $transfer->tenant_id, $transfer->company_id,
                $receipt->kind->value, $receipt->disposition?->value, $receipt->has_discrepancy, $discrepancyCount,
                $userId, $transfer->destination_location_id, $receipt->receipt_number, $transfer->transfer_number));
            if ($transfer->status === TransferStatus::Completed) {
                event(new StockTransferCompleted($transfer->id, $transfer->tenant_id, $transfer->company_id, $transfer->transfer_number,
                    $transfer->source_location_id, $transfer->destination_location_id, $transfer->transfer_cost, $userId, $receipt->received_at->toIso8601String()));
            }
        });

        return new TransferReceiptResult($receipt->load('lines.lots.batch', 'receivedBy'), $transfer, false);
    }

    /**
     * @param  Quantities  $quantities
     * @return array{in_movement_id: string|null, scrap_movement_id: string|null, return_movement_id: string|null}
     */
    private function writeMovements(StockTransfer $transfer, StockTransferLine $line, StockTransferReceipt $receipt, array $quantities, ?StockTransferLineBatchAllocation $allocation = null): array
    {
        $ids = ['in_movement_id' => null, 'scrap_movement_id' => null, 'return_movement_id' => null];
        if (bccomp($quantities['returned'], '0', QuantityScale::SCALE) > 0) {
            $returns = $this->movementSupport->restockAtSource($transfer, $line, $quantities['returned'], $allocation === null ? [] : [$allocation->batch_id => $quantities['returned']], $receipt->received_by_user_id, $transfer->transfer_number.'-RETURN');
            $ids['return_movement_id'] = $returns[$allocation === null ? '' : $allocation->batch_id];

            return $ids;
        }
        $scrap = bcadd($quantities['damaged'], $quantities['written_off'], QuantityScale::SCALE);
        $land = bcadd($quantities['received'], $scrap, QuantityScale::SCALE);
        if (bccomp($land, '0', QuantityScale::SCALE) > 0) {
            $movement = $this->stockAdjustmentService->receive($line->product_id, $transfer->destination_location_id, $land, $transfer->transfer_number,
                $receipt->received_by_user_id, batchId: $allocation?->batch_id, expectedCompanyId: $transfer->company_id,
                variantId: $line->variant_id, movementType: MovementType::TransferIn, transferId: $transfer->id);
            $ids['in_movement_id'] = $movement->id;
        }
        if (bccomp($scrap, '0', QuantityScale::SCALE) > 0) {
            $reason = $receipt->kind === TransferReceiptKind::Close ? MovementReason::WriteOff : MovementReason::Damage;
            $unitCost = $line->product->resolveMovementUnitCost();
            if (! is_numeric($unitCost)) {
                throw new \LogicException('Movement unit cost must be a decimal.');
            }
            $movement = $this->stockAdjustmentService->issue($line->product_id, $transfer->destination_location_id, $scrap, $receipt->receipt_number,
                $receipt->received_by_user_id, batchId: $allocation?->batch_id, expectedCompanyId: $transfer->company_id,
                variantId: $line->variant_id, reason: $reason, unitCost: $unitCost,
                referenceType: StockMovementReferenceType::StockTransferReceipt, referenceId: $receipt->id);
            $movement->refresh();
            $ids['scrap_movement_id'] = $movement->id;
            $occurredAt = \DateTimeImmutable::createFromInterface($movement->occurred_at ?? $movement->created_at ?? $receipt->received_at);
            $this->glBuffer->enqueue(new MovementGlContext(
                kind: $allocation === null ? MovementGlKind::Exit : MovementGlKind::BatchWriteOff,
                movementId: $movement->id, companyId: $movement->company_id, currencyCode: $transfer->company->currency,
                reason: $reason, quantityBefore: $movement->quantity_before, quantityAfter: $movement->quantity_after,
                unitCost: $movement->unit_cost ?? '0', sourceType: $movement->reference_type, sourceId: $movement->reference_id,
                occurredAt: $occurredAt, entryDate: $occurredAt, postedByUserId: $receipt->received_by_user_id,
                isHistorical: $movement->is_historical, batchNumber: $allocation?->batch->batch_number, productId: $allocation === null ? null : $line->product_id,
            ));
        }

        return $ids;
    }

    /** @param Quantities $quantities */
    private function addCounters(StockTransferLine|StockTransferLineBatchAllocation $line, array $quantities): void
    {
        $line->quantity_received = bcadd($line->quantity_received, $quantities['received'], QuantityScale::SCALE);
        $line->quantity_damaged = bcadd($line->quantity_damaged, $quantities['damaged'], QuantityScale::SCALE);
        $line->quantity_written_off = bcadd($line->quantity_written_off, $quantities['written_off'], QuantityScale::SCALE);
        $line->quantity_returned = bcadd($line->quantity_returned, $quantities['returned'], QuantityScale::SCALE);
    }

    private function capitalizeFreight(StockTransfer $transfer): void
    {
        $working = max(StockTransferMovementSupport::ALLOCATION_SCALE, $this->scaleResolver->getScaleSafe($transfer->company->currency, 3) + 4);
        $sent = '0';
        $good = '0';
        $landed = [];
        foreach ($transfer->lines as $line) {
            $sent = bcadd($sent, $line->quantity, QuantityScale::SCALE);
            $good = bcadd($good, $line->quantity_received, QuantityScale::SCALE);
            $landed[$line->id] = $line->quantity_received;
        }
        // Truncate the nonnegative freight pool toward zero; retain the remainder as uncapitalized.
        $pool = bccomp($sent, '0', QuantityScale::SCALE) > 0 ? bcmul($transfer->transfer_cost, bcdiv($good, $sent, $working), StockTransferMovementSupport::MONEY_SCALE) : '0.0000';
        $transfer->freight_uncapitalized = bcsub($transfer->transfer_cost, $pool, StockTransferMovementSupport::MONEY_SCALE);
        $transfer->save();
        if (bccomp($pool, '0', StockTransferMovementSupport::MONEY_SCALE) > 0) {
            $this->movementSupport->capitalizeTransferCost($transfer, $pool, $landed);
        }
    }

    /**
     * @param  list<RecordedLine>  $records
     * @param  Quantities  $totals
     */
    private function persistEvents(StockTransfer $transfer, StockTransferReceipt $receipt, array $records, array $totals, string $previousStatus): void
    {
        $versions = range(2, count($records) + 1);
        $occurredAt = $receipt->received_at->toIso8601String();
        $header = $receipt->kind === TransferReceiptKind::Close
            ? new StockTransferClosedV1(
                receiptId: $receipt->id, transferId: $transfer->id, tenantId: $transfer->tenant_id, companyId: $transfer->company_id,
                transferNumber: $transfer->transfer_number, receiptNumber: $receipt->receipt_number, sequence: $receipt->sequence,
                sourceLocationId: $transfer->source_location_id, destinationLocationId: $transfer->destination_location_id,
                closedByUserId: $receipt->received_by_user_id, disposition: ($receipt->disposition ?? throw new \LogicException('Close receipt lacks its disposition.'))->value,
                closeReason: ($transfer->close_reason ?? throw new \LogicException('Closed transfer lacks its reason.'))->value, closeNote: $transfer->close_note,
                previousStatus: $previousStatus, newStatus: $transfer->status->value, lineCount: count($records),
                totalWrittenOff: $totals['written_off'], totalReturned: $totals['returned'], idempotencyKey: $receipt->idempotency_key,
                payloadHash: $receipt->payload_hash, freightUncapitalized: $transfer->freight_uncapitalized,
                lineEventVersions: $versions, occurredAt: $occurredAt,
            )
            : new StockTransferReceivedV1(
                receiptId: $receipt->id, transferId: $transfer->id, tenantId: $transfer->tenant_id, companyId: $transfer->company_id,
                transferNumber: $transfer->transfer_number, receiptNumber: $receipt->receipt_number, sequence: $receipt->sequence,
                sourceLocationId: $transfer->source_location_id, destinationLocationId: $transfer->destination_location_id,
                receivedByUserId: $receipt->received_by_user_id, isBlind: false, hasDiscrepancy: $receipt->has_discrepancy,
                previousStatus: $previousStatus, newStatus: $transfer->status->value, lineCount: count($records),
                totalReceived: $totals['received'], totalDamaged: $totals['damaged'], receiptNotes: $receipt->notes,
                idempotencyKey: $receipt->idempotency_key, payloadHash: $receipt->payload_hash,
                freightUncapitalized: $transfer->freight_uncapitalized, lineEventVersions: $versions, occurredAt: $occurredAt,
            );
        $header->setAggregateRootVersion(1);
        $this->storedEvents->persist($header, $receipt->id)->handle();
        foreach ($records as $index => $record) {
            $line = $record['line'];
            $lots = [];
            foreach ($record['lots'] as $lot) {
                $lots[] = ['receiptLotId' => $lot->id, 'batchAllocationId' => $lot->batch_allocation_id, 'batchId' => $lot->batch_id,
                    'batchNumber' => $lot->batch->batch_number, 'quantityReceived' => $lot->quantity_received, 'quantityDamaged' => $lot->quantity_damaged,
                    'quantityWrittenOff' => $lot->quantity_written_off, 'quantityReturned' => $lot->quantity_returned,
                    'inMovementId' => $lot->in_movement_id, 'scrapMovementId' => $lot->scrap_movement_id, 'returnMovementId' => $lot->return_movement_id];
            }
            $event = new StockTransferReceiptLineRecordedV1(
                receiptLineId: $line->id, receiptId: $receipt->id, transferId: $transfer->id, transferLineId: $line->transfer_line_id,
                tenantId: $transfer->tenant_id, companyId: $transfer->company_id, kind: $receipt->kind->value, disposition: $receipt->disposition?->value,
                sequence: $receipt->sequence, sourceLocationId: $transfer->source_location_id, destinationLocationId: $transfer->destination_location_id,
                actorUserId: $receipt->received_by_user_id, actorRole: $receipt->kind === TransferReceiptKind::Close ? TransferActorRole::Closer->value : TransferActorRole::Receiver->value,
                isBlind: false, productId: $line->product_id, variantId: $line->variant_id, isLotTracked: $line->is_lot_tracked,
                quantitySent: $line->quantity_sent_snapshot, quantityPreviouslyReceived: $record['previousReceived'], quantityPreviouslyDamaged: $record['previousDamaged'],
                quantityReceived: $line->quantity_received, quantityDamaged: $line->quantity_damaged,
                quantityWrittenOff: $line->quantity_written_off, quantityReturned: $line->quantity_returned,
                quantityRemainingAfter: $record['remaining'], discrepancyReason: $line->discrepancy_reason?->value, discrepancyNote: $line->discrepancy_note,
                inMovementId: $line->in_movement_id, scrapMovementId: $line->scrap_movement_id, returnMovementId: $line->return_movement_id,
                lots: $lots, occurredAt: $occurredAt,
            );
            $event->setAggregateRootVersion($index + 2);
            $this->storedEvents->persist($event, $receipt->id)->handle();
        }
    }
}
