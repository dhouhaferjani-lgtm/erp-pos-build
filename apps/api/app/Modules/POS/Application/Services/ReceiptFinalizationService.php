<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\POS\Application\Services\Fiscal\V3\V3ReceiptHashComputer;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Events\ReceiptCreated;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Terminal;
use Illuminate\Support\Facades\DB;

/**
 * Finalizes a pending_seal receipt, computing and persisting its fiscal_hash
 * then advancing the terminal's hash-chain counters under a FOR UPDATE lock.
 *
 * Idempotent: calling finalize() on an already-fiscalized receipt returns the
 * same record unchanged without a second hash computation or terminal advance.
 *
 * Schema version dispatch:
 *   v2 → ReceiptHashService::calculateHash() (legacy NF525 pipe-separated format)
 *   v3 → V3ReceiptHashComputer (canonical JSON, byte-stable across PHP/TS)
 */
final class ReceiptFinalizationService
{
    public function __construct(
        private readonly ReceiptHashService $legacyHashService,
        private readonly V3ReceiptHashComputer $v3Computer,
    ) {}

    /**
     * Finalize a receipt by computing its fiscal hash and transitioning its
     * status from pending_seal to fiscalized.
     *
     * **§14.3 chokepoint annotation (Task 30).** This method is the
     * server-side seal + terminal-chain advance for legacy server-authored
     * receipts. Phase 1 spec §14.2 retired the new-sale write surface
     * (POST /pos/receipts, POST /pos/receipts/{id}/payments, POST
     * /pos/orders/{id}/close are all 410 Gone — Task 29). Per the
     * checked-in `apps/api/scripts/saleReceipt-chokepoint-manifest.json`
     * the surviving callers are:
     *
     *   - `ReceiptReturnService::createReturn` — disposition (c)
     *     knowingly-retained carve-out. SALE_VOID / REFUND_RECEIPT /
     *     PARTIAL_REFUND are Phase 2+ reserved event types; void +
     *     processReturn (and the offline Tauri POS via VoidReturnModal)
     *     still depend on this path.
     *   - `ReceiptSyncService::sync` — disposition (b) queued for Task
     *     28 retirement.
     *   - `ReceiptPaymentService::processReceiptPayments` — disposition
     *     (b) route-disposed Task 29 §14.2; service body never reached
     *     via live HTTP.
     *   - `ExchangeService::processExchange` — disposition (b) and
     *     `live: false` per §14.3 (no live route / controller caller as
     *     of 2026-05-20).
     *
     * For new-sale SALE_RECEIPT authoring this method is NEVER invoked
     * server-side post-Phase-1 — the device seals via
     * `FiscalEventEngine.append()` per SoT v3 §1. Do NOT add new callers
     * without an explicit manifest disposition; the §14.3 CI gate
     * (`apps/api/scripts/check-saleReceipt-chokepoints.sh` +
     * `ChokepointCompletenessTest`) will fail.
     *
     * @throws \DomainException When the receipt is in an unrecoverable state (voided, etc.)
     * @throws \LogicException When the terminal's fiscal_schema_version is unsupported
     */
    public function finalize(Receipt $receipt): Receipt
    {
        // Idempotency guard: already sealed, return immediately
        if ($receipt->fiscal_status === FiscalStatus::Fiscalized) {
            return $receipt;
        }

        if ($receipt->fiscal_status !== FiscalStatus::PendingSeal) {
            throw new \DomainException(
                "Cannot finalize receipt {$receipt->receipt_number}: ".
                "status is {$receipt->fiscal_status->value}, expected pending_seal"
            );
        }

        return DB::transaction(function () use ($receipt): Receipt {
            /** @var Terminal $terminal */
            $terminal = Terminal::lockForUpdate()->findOrFail($receipt->terminal_id);

            // Ensure terminal relation is fresh (may have been loaded before the lock)
            $receipt->setRelation('terminal', $terminal);

            // Set previous_hash and chain_sequence on the receipt model BEFORE computing
            // the hash so that the v3 computer (and the legacy service) read the correct
            // chain state from the model.  The legacy service receives $terminal->last_hash
            // explicitly; the v3 computer reads $receipt->previous_hash.
            $receipt->previous_hash = $terminal->last_hash;
            $receipt->chain_sequence = $terminal->current_sequence;

            $hash = match ($terminal->fiscal_schema_version) {
                2 => $this->legacyHashService->calculateHash($receipt, $terminal->last_hash),
                3 => $this->v3Computer->compute($receipt),
                default => throw new \LogicException(
                    "Unsupported fiscal_schema_version: {$terminal->fiscal_schema_version}"
                ),
            };

            $receipt->fiscal_hash = $hash;
            $receipt->fiscal_status = FiscalStatus::Fiscalized;
            $receipt->save();

            $terminal->last_hash = $hash;
            $terminal->current_sequence++;
            $terminal->save();

            $sealed = $receipt->refresh();

            // Dispatch ReceiptCreated — the post-seal NF525 TICKET event.
            // At this point fiscalHash and chainSequence are always non-null.
            DB::afterCommit(function () use ($sealed): void {
                event(new ReceiptCreated(
                    receiptId: $sealed->id,
                    companyId: $sealed->company_id,
                    terminalId: $sealed->terminal_id,
                    receiptNumber: $sealed->receipt_number,
                    total: (string) $sealed->total,
                    currency: $sealed->currency,
                    fiscalHash: (string) $sealed->fiscal_hash,
                    chainSequence: (int) $sealed->chain_sequence,
                    postedAt: $sealed->posted_at->toIso8601String(),
                ));
            });

            return $sealed;
        });
    }
}
