<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Projections;

use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Domain\ZReport;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * POS-core projection for device-authored Z_REPORT fiscal events.
 *
 * The fiscal event is the authority; pos_z_reports is the read/export mirror.
 * Legacy server/local sync rows keep fiscal_event_id null and remain readable.
 */
final class ZReportProjection implements FiscalEventProjector
{
    public function name(): string
    {
        return 'pos_core_z_report';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::Z_REPORT;
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
        if (ZReport::query()->where('fiscal_event_id', $event->id)->exists()) {
            return;
        }

        $payload = $event->payload;
        if (! is_array($payload)) {
            throw new RuntimeException(sprintf(
                'Cannot project Z_REPORT fiscal event %s without parsed payload.',
                $event->id,
            ));
        }

        DB::transaction(function () use ($event, $payload): void {
            if (ZReport::query()->where('fiscal_event_id', $event->id)->exists()) {
                return;
            }

            $existing = ZReport::query()
                ->where('shift_id', (string) $payload['shift_id'])
                ->lockForUpdate()
                ->first();

            $row = $this->rowFromPayload($event, $payload);
            if ($existing !== null) {
                if ($existing->fiscal_event_id !== null && $existing->fiscal_event_id !== $event->id) {
                    throw new RuntimeException(sprintf(
                        'Shift %s already has a different fiscal Z_REPORT projection.',
                        (string) $payload['shift_id'],
                    ));
                }

                $existing->fill($row);
                $existing->save();

                return;
            }

            ZReport::query()->create($row);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function rowFromPayload(FiscalEvent $event, array $payload): array
    {
        return [
            'id' => (string) $payload['z_report_uuid'],
            'terminal_id' => (string) $payload['terminal_id'],
            'shift_id' => (string) $payload['shift_id'],
            'z_number' => (int) $payload['z_number'],
            'fiscal_hash' => $event->current_hash,
            'previous_z_hash' => $event->previous_hash === 'GENESIS' ? null : $event->previous_hash,
            'report_data' => $this->legacyReportData($payload),
            'receipt_snapshots' => [],
            'grand_totals' => $payload['grand_totals_after'] ?? null,
            'canonical_bytes' => $event->canonical_bytes,
            'canonical_bytes_hash' => hash('sha256', $event->canonical_bytes),
            'fiscal_event_id' => $event->id,
            'generated_by' => (string) $payload['operator_id'],
            'generated_at' => (string) $payload['closed_at_device'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function legacyReportData(array $payload): array
    {
        /** @var array<string, mixed> $receiptTotals */
        $receiptTotals = is_array($payload['receipt_totals'] ?? null) ? $payload['receipt_totals'] : [];
        /** @var array<string, mixed> $refundsTotals */
        $refundsTotals = is_array($payload['refunds_totals'] ?? null) ? $payload['refunds_totals'] : [];
        /** @var array<string, mixed> $voidsTotals */
        $voidsTotals = is_array($payload['voids_totals'] ?? null) ? $payload['voids_totals'] : [];
        /** @var array<string, mixed> $cashCount */
        $cashCount = is_array($payload['cash_count'] ?? null) ? $payload['cash_count'] : [];

        return [
            'schema_version' => 3,
            'business_date' => $payload['business_date'] ?? null,
            'period_start' => $payload['period_start'] ?? null,
            'period_end' => $payload['period_end'] ?? null,
            'sales_count' => (int) ($receiptTotals['count'] ?? 0),
            'gross_sales' => (string) ($receiptTotals['gross_sales'] ?? '0.000'),
            'net_sales' => (string) ($receiptTotals['net_sales'] ?? '0.000'),
            'tax_amount' => (string) ($receiptTotals['tax_amount'] ?? '0.000'),
            'refunds_count' => (int) ($refundsTotals['count'] ?? 0),
            'refunds_amount' => (string) ($refundsTotals['amount'] ?? '0.000'),
            'voided_count' => (int) ($voidsTotals['count'] ?? 0),
            'vat_breakdown' => $payload['vat_breakdown'] ?? [],
            'payment_methods' => $payload['payment_method_totals'] ?? [],
            'cash_drawer_totals' => $payload['cash_drawer_totals'] ?? [],
            'cash_count' => $cashCount,
            'expected_cash' => $cashCount['expected_cash'] ?? null,
            'actual_cash' => $cashCount['counted_cash'] ?? null,
            'variance' => $cashCount['variance_amount'] ?? null,
            'tolerance_summary' => $payload['tolerance_summary'] ?? null,
            'canonical_z_report' => $payload,
        ];
    }
}
