<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Projections;

use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Domain\DepositReceipt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Always-active POS-core printable projection for server-authored DEPOSIT_RECEIPT
 * events — the back-office counterpart of {@see AccountPaymentReceiptProjection}.
 *
 * The projector reads only the verified canonical payload snapshot stored on
 * `fiscal_events`; it does not traverse live customer, Treasury, Accounting,
 * Contact, B2B, or Partner state. Treasury payment creation and FIFO allocation
 * are owned by the later gated bridge (Phase 4 `TreasuryDepositBridge`).
 *
 * Idempotent on `(fiscal_event_id)` — `pos_deposit_receipts.fiscal_event_id` is
 * UNIQUE; a re-delivery no-ops.
 */
final class DepositReceiptProjection implements FiscalEventProjector
{
    public function __construct(
        private readonly CanonicalPayloadReader $canonicalReader,
    ) {}

    public function name(): string
    {
        return 'pos_core_deposit_receipt';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::DEPOSIT_RECEIPT;
    }

    public function requiresModule(): ?string
    {
        return null;
    }

    public function priority(): int
    {
        return 50;
    }

    public function apply(FiscalEvent $event): void
    {
        if ($this->receiptExistsFor($event->id)) {
            return;
        }

        $view = $this->canonicalReader->forDepositReceipt($event);
        $payload = $view->payload;

        try {
            DB::transaction(function () use ($event, $payload, $view): void {
                if ($this->receiptExistsFor($event->id)) {
                    return;
                }

                DepositReceipt::query()->create([
                    'tenant_id' => $event->tenant_id,
                    'company_id' => $event->company_id,
                    'fiscal_event_id' => $event->id,
                    'deposit_receipt_uuid' => $payload->depositReceiptUuid,
                    'customer_id' => $view->customer->customerId,
                    'customer_name' => $view->customer->name,
                    'amount' => $view->payment->amount,
                    'currency_code' => $payload->currencyCode,
                    'payload_snapshot' => $payload->toArray(),
                ]);
            });
        } catch (QueryException $e) {
            // Make the UNIQUE(fiscal_event_id) constraint the real idempotency
            // primitive: a concurrent apply() that loses the insert race (both
            // workers passed the exists() checks before either committed) must
            // STILL no-op, not surface a duplicate-key error. If a row now
            // exists for this event, the constraint did its job — swallow.
            // Otherwise the failure is unrelated; re-throw so the job retries.
            if ($this->receiptExistsFor($event->id)) {
                return;
            }

            throw $e;
        }
    }

    /**
     * Reads mutable DB state — the result changes once a row is written (by this
     * worker's insert or a concurrent one), so each call must be re-evaluated.
     *
     * @phpstan-impure
     */
    private function receiptExistsFor(string $fiscalEventId): bool
    {
        return DepositReceipt::query()->where('fiscal_event_id', $fiscalEventId)->exists();
    }
}
