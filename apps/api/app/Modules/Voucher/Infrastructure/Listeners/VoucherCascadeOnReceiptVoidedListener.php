<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Listeners;

use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Events\ReceiptVoided;
use App\Modules\POS\Domain\Receipt;
use App\Modules\Voucher\Application\Services\VoucherCascadeService;
use Illuminate\Support\Facades\Log;

/**
 * Listens on ReceiptVoided and triggers VoucherCascadeService when the receipt
 * is a Return (credit note) type.
 *
 * Sale receipts have no voucher issuance association and are skipped silently.
 *
 * Wiring: EventServiceProvider::$listen[ReceiptVoided::class]
 */
final class VoucherCascadeOnReceiptVoidedListener
{
    public function __construct(
        private readonly VoucherCascadeService $cascadeService,
    ) {}

    public function handle(ReceiptVoided $event): void
    {
        $receipt = Receipt::find($event->receiptId);

        if ($receipt === null) {
            Log::warning('VoucherCascadeOnReceiptVoidedListener: receipt not found.', [
                'receipt_id' => $event->receiptId,
            ]);

            return;
        }

        // Only credit-note (Return) receipts may have issued vouchers.
        if ($receipt->receipt_type !== ReceiptType::Return) {
            return;
        }

        $this->cascadeService->onCreditNoteVoided($receipt);
    }
}
