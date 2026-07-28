<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Projections;

use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Domain\ZReport;
use App\Shared\Domain\CashRoundingCutover;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
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
    /**
     * Storage scale of `pos_receipts.cash_rounding_adjustment` — `decimal(12,3)`.
     * This is a projection running on a queue worker with no CompanyContext, so
     * the scale is the column's, never a resolver call (rule 19 / spec §4.5).
     */
    private const ROUNDING_SUMMARY_SCALE = 3;

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
        if ($event->integrity_status !== IntegrityStatus::Verified) {
            return;
        }

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

                unset($row['id']);

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
            'report_data' => $this->legacyReportData($event, $payload),
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
    private function legacyReportData(FiscalEvent $event, array $payload): array
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
            'cash_rounding_summary' => $this->cashRoundingSummary($event, $payload),
            'canonical_z_report' => $payload,
        ];
    }

    /**
     * Derive the Z-level cash-rounding summary from PROJECTED receipts
     * (spec Rev 2.2 §4.5).
     *
     * This is DERIVED OBSERVABILITY, not fiscal authority: the per-receipt
     * SALE_RECEIPT signatures are what a verifier checks. It is derived
     * server-side rather than read off the device payload because
     * `Z_REPORT` stays v1 — adding a key to the canonical payload would
     * quarantine 100% of Z events against `ZReportPayload::PAYLOAD_KEYS` —
     * and because this method rebuilds `report_data` from a FIXED key list,
     * so a device-authored `cash_rounding_summary` could never survive here
     * anyway.
     *
     * v3-gated by joining `fiscal_events.event_version` against
     * {@see CashRoundingCutover::EVENT_VERSION}, so a mixed window during
     * cutover reports only what was actually signed with a rounding
     * adjustment. Receipts carry no `shift_id`; the canonical shift→receipts
     * derivation is terminal + `posted_at` window, mirroring
     * `PaymentToleranceQueryService::shiftReceiptsQuery`. Voided and training
     * receipts are excluded, matching every other receipt aggregator.
     *
     * `receipt_count` counts only rows whose adjustment is non-zero: a v3
     * receipt that needed no rounding stores '0.000', not NULL, so a bare
     * `whereNotNull` would count every v3 receipt on the terminal.
     *
     * Runs on a Horizon worker with NO CompanyContext: scale 3 is the
     * projection's fixed storage scale (`pos_receipts.cash_rounding_adjustment`
     * is `decimal(12,3)`, and every money string this method's siblings emit is
     * already 3dp), never a resolver call.
     *
     * @param  array<string, mixed>  $payload
     * @return array{total_adjustment: string, receipt_count: int}
     */
    private function cashRoundingSummary(FiscalEvent $event, array $payload): array
    {
        $zero = ['total_adjustment' => bcadd('0', '0', self::ROUNDING_SUMMARY_SCALE), 'receipt_count' => 0];

        $periodStart = $this->windowBoundary($payload['period_start'] ?? null);
        $periodEnd = $this->windowBoundary($payload['period_end'] ?? null);
        $terminalId = $event->terminal_id;

        // `fiscal_events.terminal_id` is the server-validated scope column the
        // ingress bound; it equals `payload['terminal_id']` by construction
        // (both are written from the same signed envelope) but is the one the
        // join can be trusted against.
        if ($terminalId === '' || $periodStart === null || $periodEnd === null) {
            return $zero;
        }

        /** @var object{total: mixed, cnt: mixed}|null $row */
        $row = DB::table('pos_receipts')
            ->join('fiscal_events', 'pos_receipts.fiscal_event_id', '=', 'fiscal_events.id')
            ->where('pos_receipts.terminal_id', $terminalId)
            ->whereBetween('pos_receipts.posted_at', [$periodStart, $periodEnd])
            ->where('pos_receipts.is_voided', false)
            ->where('pos_receipts.is_training', false)
            ->where('fiscal_events.event_version', '>=', CashRoundingCutover::EVENT_VERSION)
            ->whereNotNull('pos_receipts.cash_rounding_adjustment')
            ->where('pos_receipts.cash_rounding_adjustment', '!=', 0)
            ->selectRaw('COALESCE(SUM(pos_receipts.cash_rounding_adjustment), 0) AS total, COUNT(*) AS cnt')
            ->first();

        if ($row === null) {
            return $zero;
        }

        $total = is_scalar($row->total) ? (string) $row->total : '0';
        if (! is_numeric($total)) {
            $total = '0';
        }

        /** @var numeric-string $total */

        return [
            // Signed: bcadd preserves the sign, and the sum of a mixed-sign
            // window is legitimately negative.
            'total_adjustment' => bcadd($total, '0', self::ROUNDING_SUMMARY_SCALE),
            'receipt_count' => is_numeric($row->cnt) ? (int) $row->cnt : 0,
        ];
    }

    /**
     * Normalise a canonical ISO-8601 period boundary to the wall-clock UTC
     * form `pos_receipts.posted_at` is stored in (`timestamp without time
     * zone`, UTC). Returns null when the payload value is missing or not a
     * parseable timestamp — the caller then emits the zero shape rather than
     * running an unbounded aggregate.
     */
    private function windowBoundary(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.u');
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
