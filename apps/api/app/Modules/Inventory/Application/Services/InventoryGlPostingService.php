<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Inventory\Application\DTOs\MovementGlContext;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\Log;

final class InventoryGlPostingService
{
    public function __construct(
        private readonly GeneralLedgerService $gl,
        private readonly InventoryValuationModeResolver $valuationMode,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function postForExit(MovementGlContext $ctx): ?JournalEntry
    {
        return $this->postMovement($ctx, false);
    }

    public function postForEntry(MovementGlContext $ctx): ?JournalEntry
    {
        return $this->postMovement($ctx, true);
    }

    public function postForCountCorrection(MovementGlContext $ctx): ?JournalEntry
    {
        if ($ctx->isHistorical || ! $ctx->reason->requiresGLEntry()) {
            return null;
        }

        $movement = $this->movement($ctx);
        $direction = $movement->directionForRow();
        if ($direction === 'flat') {
            return null;
        }

        $this->valuationMode->requirePerpetual($ctx->companyId);
        $counterPurpose = $direction === 'in'
            ? SystemAccountPurpose::InventoryGainIncome
            : SystemAccountPurpose::InventoryShrinkageExpense;

        if (! $this->gl->hasAccountForPurpose($ctx->companyId, SystemAccountPurpose::Inventory)
            || ! $this->gl->hasAccountForPurpose($ctx->companyId, $counterPurpose)) {
            Log::warning('Count correction exists but its inventory shrinkage/gain accounts are not mapped.', [
                'company_id' => $ctx->companyId,
                'movement_id' => $ctx->movementId,
            ]);

            return null;
        }

        $amount = $this->amount($ctx);
        $scale = $this->scaleResolver->getScale($ctx->currencyCode);
        if (bccomp($amount, '0', $scale) <= 0) {
            Log::warning('Count correction moved stock at a non-positive value; no shrinkage/gain entry was recorded.', [
                'movement_id' => $ctx->movementId,
            ]);

            return null;
        }

        return $this->gl->createInventoryMovementEntry(
            companyId: $ctx->companyId,
            movementId: $ctx->movementId,
            sourceType: 'inventory_shrinkage',
            amount: $amount,
            reason: $ctx->reason,
            counterPurpose: $counterPurpose,
            debitInventory: $direction === 'in',
            entryDate: $ctx->entryDate,
            description: 'Inventory count correction; occurred '.$ctx->occurredAt->format(DATE_ATOM),
            postedByUserId: $ctx->postedByUserId,
            currencyCode: $ctx->currencyCode,
            postSynchronously: true,
        );
    }

    public function postForBatchWriteOff(MovementGlContext $ctx): ?JournalEntry
    {
        if ($ctx->isHistorical) {
            return null;
        }

        if ($ctx->batchNumber === null || $ctx->productId === null) {
            throw new \InvalidArgumentException('Batch write-off GL context requires batchNumber and productId.');
        }

        if (! $this->gl->hasInventoryWriteOffAccounts($ctx->companyId)) {
            Log::warning('Inventory write-off movement exists but its COGS/Inventory accounts are not mapped.', [
                'company_id' => $ctx->companyId,
                'movement_id' => $ctx->movementId,
            ]);

            return null;
        }

        $amount = $this->amount($ctx);
        if (bccomp($amount, '0', $this->scaleResolver->getScale($ctx->currencyCode)) <= 0) {
            Log::warning('Inventory batch write-off has a non-positive amount; stock changed without a GL entry.', [
                'movement_id' => $ctx->movementId,
            ]);

            return null;
        }

        return $this->gl->createInventoryWriteOffEntry(
            companyId: $ctx->companyId,
            batchNumber: $ctx->batchNumber,
            productId: $ctx->productId,
            amount: $amount,
            reason: $ctx->reason,
            movementId: $ctx->movementId,
            postedByUserId: $ctx->postedByUserId,
            currencyCode: $ctx->currencyCode,
            postSynchronously: true,
        );
    }

    private function postMovement(MovementGlContext $ctx, bool $debitInventory): ?JournalEntry
    {
        if ($ctx->isHistorical || ! $ctx->reason->affectsCOGS()) {
            return null;
        }

        $movement = $this->movement($ctx);
        $direction = $movement->directionForRow();

        if ($direction === 'flat') {
            return null;
        }

        $isPos = in_array($ctx->reason, [MovementReason::POSSale, MovementReason::POSReturn], true);
        if ($isPos) {
            if (! $this->valuationMode->resolve($ctx->companyId)->isSupported()) {
                Log::warning('Inventory GL posting skipped for a periodic POS company.', [
                    'company_id' => $ctx->companyId,
                    'movement_id' => $ctx->movementId,
                ]);

                return null;
            }
        } else {
            $this->valuationMode->requirePerpetual($ctx->companyId);
        }

        if (! $this->gl->hasInventoryMovementAccounts($ctx->companyId, $ctx->reason)) {
            Log::warning('Inventory movement exists but its GL accounts are not mapped.', [
                'company_id' => $ctx->companyId,
                'movement_id' => $ctx->movementId,
                'reason' => $ctx->reason->value,
            ]);

            return null;
        }

        $amount = $this->amount($ctx);
        $scale = $this->scaleResolver->getScale($ctx->currencyCode);
        if (bccomp($amount, '0', $scale) <= 0) {
            Log::warning('Inventory moved at a non-positive value; no GL relief was recorded.', [
                'movement_id' => $ctx->movementId,
            ]);

            return null;
        }

        if ($direction !== $ctx->reason->getMovementType()) {
            throw new \DomainException("Inventory movement {$ctx->movementId} direction contradicts {$ctx->reason->value}.");
        }

        return $this->gl->createInventoryMovementEntry(
            companyId: $ctx->companyId,
            movementId: $ctx->movementId,
            sourceType: $debitInventory ? 'inventory_entry' : 'inventory_exit',
            amount: $amount,
            reason: $ctx->reason,
            counterPurpose: SystemAccountPurpose::CostOfGoodsSold,
            debitInventory: $debitInventory,
            entryDate: $ctx->entryDate,
            description: sprintf(
                'Inventory %s (%s; occurred %s)',
                $debitInventory ? 'entry' : 'exit',
                $ctx->reason->label(),
                $ctx->occurredAt->format(DATE_ATOM),
            ),
            postedByUserId: $ctx->postedByUserId,
            currencyCode: $ctx->currencyCode,
            postSynchronously: true,
        );
    }

    /** @return numeric-string */
    private function amount(MovementGlContext $ctx): string
    {
        $movement = $this->movement($ctx);
        $scale = $this->scaleResolver->getScale($ctx->currencyCode);

        return CurrencyScale::bcround(
            bcmul($ctx->unitCost, $movement->absoluteDeltaForRow(), $scale + 6),
            $scale,
        );
    }

    private function movement(MovementGlContext $ctx): StockMovement
    {
        $movement = new StockMovement;
        $movement->setRawAttributes([
            'quantity_before' => $ctx->quantityBefore,
            'quantity_after' => $ctx->quantityAfter,
        ]);

        return $movement;
    }
}
