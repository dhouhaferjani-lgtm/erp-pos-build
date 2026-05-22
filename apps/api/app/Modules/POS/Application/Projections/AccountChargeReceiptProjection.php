<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Projections;

use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Domain\AccountChargeReceipt;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Always-active POS-core printable projection for ACCOUNT_CHARGE events.
 *
 * Reads only the verified canonical payload stored on `fiscal_events`.
 * Treasury allocation, GL effects, and B2B aggregate updates are owned by
 * later gated bridge projectors, not by this POS-core printable projection.
 */
final class AccountChargeReceiptProjection implements FiscalEventProjector
{
    public function __construct(
        private readonly CanonicalPayloadReader $canonicalReader,
    ) {}

    public function name(): string
    {
        return 'pos_core_account_charge_receipt';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::ACCOUNT_CHARGE;
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
        if (AccountChargeReceipt::query()->where('fiscal_event_id', $event->id)->exists()) {
            return;
        }

        $view = $this->canonicalReader->forAccountCharge($event);
        $payload = $view->payload;

        $now = now();

        DB::table('pos_account_charge_receipts')->insertOrIgnore([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $event->tenant_id,
            'company_id' => $event->company_id,
            'fiscal_event_id' => $event->id,
            'account_charge_uuid' => $payload->accountChargeUuid,
            'customer_id' => $view->customer->customerId,
            'customer_name' => $view->customer->name,
            'amount_charged' => $view->totals->amountChargedToAccount,
            'currency_code' => $payload->currencyCode,
            'payload_snapshot' => json_encode($payload->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
