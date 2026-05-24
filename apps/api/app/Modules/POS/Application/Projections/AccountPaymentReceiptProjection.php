<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Projections;

use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Domain\AccountPaymentReceipt;
use Illuminate\Support\Facades\DB;

/**
 * Always-active POS-core printable projection for ACCOUNT_PAYMENT events.
 *
 * The projector reads only the verified canonical payload snapshot stored on
 * `fiscal_events`; it does not traverse live customer, Treasury, Accounting,
 * Contact, B2B, or Partner state. Treasury payment creation and FIFO
 * allocation are owned by the later gated bridge.
 */
final class AccountPaymentReceiptProjection implements FiscalEventProjector
{
    public function __construct(
        private readonly CanonicalPayloadReader $canonicalReader,
    ) {}

    public function name(): string
    {
        return 'pos_core_account_payment_receipt';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::ACCOUNT_PAYMENT;
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
        if (AccountPaymentReceipt::query()->where('fiscal_event_id', $event->id)->exists()) {
            return;
        }

        $view = $this->canonicalReader->forAccountPayment($event);
        $payload = $view->payload;

        DB::transaction(function () use ($event, $payload, $view): void {
            if (AccountPaymentReceipt::query()->where('fiscal_event_id', $event->id)->exists()) {
                return;
            }

            AccountPaymentReceipt::query()->create([
                'tenant_id' => $event->tenant_id,
                'company_id' => $event->company_id,
                'fiscal_event_id' => $event->id,
                'account_payment_uuid' => $payload->accountPaymentUuid,
                'customer_id' => $view->customer->customerId,
                'customer_name' => $view->customer->name,
                'amount' => $view->payment->amount,
                'currency_code' => $payload->currencyCode,
                'payload_snapshot' => $payload->toArray(),
            ]);
        });
    }
}
