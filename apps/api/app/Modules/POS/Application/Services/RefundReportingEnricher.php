<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Application\DTOs\RefundReportingData;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Receipt;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

final class RefundReportingEnricher
{
    public function __construct(
        private readonly CanonicalPayloadReader $canonicalPayloadReader,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  iterable<int, Receipt>  $receipts
     * @return array<string, RefundReportingData>
     */
    public function enrich(iterable $receipts): array
    {
        $refunds = collect($receipts)
            ->filter(static fn (Receipt $receipt): bool => self::isRefundLike($receipt))
            ->values();

        $eventIds = $refunds
            ->pluck('fiscal_event_id')
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();

        /** @var Collection<string, FiscalEvent> $events */
        $events = $eventIds === []
            ? collect()
            : FiscalEvent::query()->whereIn('id', $eventIds)->get()->keyBy('id');

        $result = [];
        foreach ($refunds as $receipt) {
            $result[$receipt->id] = $this->enrichReceipt($receipt, $events->get($receipt->fiscal_event_id));
        }

        return $result;
    }

    private static function isRefundLike(Receipt $receipt): bool
    {
        return in_array($receipt->invoice_type_code, ['REFUND', 'VOID'], true)
            || ($receipt->receipt_type === ReceiptType::Return
                && $receipt->fiscal_event_id === null
                && $receipt->invoice_type_code === 'SALE');
    }

    private function enrichReceipt(Receipt $receipt, ?FiscalEvent $event): RefundReportingData
    {
        $reason = $receipt->return_reason?->label();
        $reasonSource = 'legacy_enum';
        $destination = null;

        if ($event?->event_type === FiscalEventType::SALE_RECEIPT) {
            try {
                $view = $this->canonicalPayloadReader->forSaleReceipt($event);
                $reference = $view->originalReceiptReference();
                if ($reference !== null) {
                    $reason = $reference->refundReason;
                    $reasonSource = 'canonical';
                    $destination = $view->payload->refundDestination;
                }
            } catch (\InvalidArgumentException|\TypeError $exception) {
                $this->logger->warning('Refund reporting payload degraded to the legacy receipt fallback.', [
                    'fiscal_event_id' => $event->id,
                    'receipt_id' => $receipt->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        /** @var list<array<string, mixed>> $alerts */
        $alerts = is_array($receipt->refund_policy_alerts) ? $receipt->refund_policy_alerts : [];

        return new RefundReportingData(
            original_receipt_number: $receipt->originalReceipt?->receipt_number,
            refund_reason: $reason,
            refund_reason_source: $reasonSource,
            refund_destination: $destination,
            refund_policy_alerts: $alerts,
        );
    }
}
