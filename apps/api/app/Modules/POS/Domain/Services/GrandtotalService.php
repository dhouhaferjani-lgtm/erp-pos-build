<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services;

use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\GrandtotalEvent;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing NF525 Grand Total events.
 *
 * Grand totals are CRITICAL for NF525 certification.
 * They track both:
 * - Period totals: Reset at each closing (daily/monthly/yearly)
 * - Perpetual totals: NEVER reset (cumulative since terminal activation)
 *
 * Each event type (DAILY/MONTHLY/YEARLY) has its own independent hash chain.
 */
final class GrandtotalService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Create grand total event (daily/monthly/yearly)
     *
     * Business Rules:
     * - Each event type has separate hash chain
     * - Sequence numbers are per event type
     * - Period totals reset at each closing
     * - Perpetual totals NEVER reset (cumulative since activation)
     *
     * @param  Terminal  $terminal  The terminal to create event for
     * @param  string  $eventType  Event type: DAILY, MONTHLY, or YEARLY
     * @param  Carbon  $periodStart  Period start timestamp
     * @param  Carbon  $periodEnd  Period end timestamp
     * @param  User  $generatedBy  User generating the event
     */
    public function createGrandtotalEvent(
        Terminal $terminal,
        string $eventType,
        Carbon $periodStart,
        Carbon $periodEnd,
        User $generatedBy
    ): GrandtotalEvent {
        return DB::transaction(function () use (
            $terminal,
            $eventType,
            $periodStart,
            $periodEnd,
            $generatedBy
        ) {
            // Get previous grand total event of same type
            $previousEvent = GrandtotalEvent::where('terminal_id', $terminal->id)
                ->where('event_type', $eventType)
                ->orderByDesc('sequence_number')
                ->first();

            $previousHash = $previousEvent?->fiscal_hash;
            $sequenceNumber = $previousEvent ? $previousEvent->sequence_number + 1 : 1;

            // Calculate period totals (for this period only)
            $periodTotals = $this->calculatePeriodTotals($terminal, $periodStart, $periodEnd);

            // Calculate perpetual totals (cumulative since activation)
            $perpetualTotals = $this->calculatePerpetualTotals($terminal);

            // Calculate fiscal hash
            $fiscalHash = $this->calculateHash(
                $eventType,
                $sequenceNumber,
                $periodStart,
                $periodEnd,
                $periodTotals,
                $perpetualTotals,
                $previousHash
            );

            // Create grand total event
            return GrandtotalEvent::create([
                'terminal_id' => $terminal->id,
                'event_type' => $eventType,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'period_totals' => $periodTotals,
                'perpetual_totals' => $perpetualTotals,
                'fiscal_hash' => $fiscalHash,
                'previous_hash' => $previousHash,
                'sequence_number' => $sequenceNumber,
                'generated_by' => $generatedBy->id,
                'generated_at' => now(),
            ]);
        });
    }

    /**
     * Calculate period totals (reset at each closing)
     *
     * Totals for receipts within the period only.
     *
     * @param  Terminal  $terminal  The terminal to calculate for
     * @param  Carbon  $periodStart  Period start timestamp
     * @param  Carbon  $periodEnd  Period end timestamp
     * @return array{gross_sales: string, net_sales: string, tax_amount: string, sales_count: int, refunds_count: int, refunds_amount: string}
     */
    public function calculatePeriodTotals(
        Terminal $terminal,
        Carbon $periodStart,
        Carbon $periodEnd
    ): array {
        // Get all production receipts in period (exclude training)
        $receipts = Receipt::where('terminal_id', $terminal->id)
            ->where('is_training', false)
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->get();

        $grossSales = '0.00';
        $taxAmount = '0.00';
        $salesCount = 0;
        $refundsCount = 0;
        $refundsAmount = '0.00';

        foreach ($receipts as $receipt) {
            if ($receipt->is_voided) {
                $refundsCount++;
                $refundsAmount = bcadd($refundsAmount, $receipt->total, $this->scale());
            } else {
                $salesCount++;
                $grossSales = bcadd($grossSales, $receipt->total, $this->scale());
                $taxAmount = bcadd($taxAmount, $receipt->tax_amount, $this->scale());
            }
        }

        $netSales = bcsub($grossSales, $taxAmount, $this->scale());

        return [
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'tax_amount' => $taxAmount,
            'sales_count' => $salesCount,
            'refunds_count' => $refundsCount,
            'refunds_amount' => $refundsAmount,
        ];
    }

    /**
     * Calculate perpetual totals (never reset)
     *
     * Cumulative totals since terminal activation.
     *
     * @param  Terminal  $terminal  The terminal to calculate for
     * @return array{lifetime_sales: string, lifetime_tax: string, lifetime_transactions: int}
     */
    public function calculatePerpetualTotals(Terminal $terminal): array
    {
        // Get ALL production receipts for terminal (excluding voids/refunds and training)
        $totals = Receipt::where('terminal_id', $terminal->id)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->selectRaw('
                SUM(total) as lifetime_sales,
                SUM(tax_amount) as lifetime_tax,
                COUNT(*) as lifetime_transactions
            ')
            ->first();

        return [
            'lifetime_sales' => number_format((float) ($totals->lifetime_sales ?? 0), $this->scale(), '.', ''),
            'lifetime_tax' => number_format((float) ($totals->lifetime_tax ?? 0), $this->scale(), '.', ''),
            'lifetime_transactions' => (int) ($totals->lifetime_transactions ?? 0),
        ];
    }

    /**
     * Calculate fiscal hash for grand total event
     *
     * Hash Input: event_type|sequence|period_start|period_end|period_totals|perpetual_totals|previous_hash
     *
     * @param  string  $eventType  Event type (DAILY/MONTHLY/YEARLY)
     * @param  int  $sequenceNumber  Sequence number for this event type
     * @param  Carbon  $periodStart  Period start timestamp
     * @param  Carbon  $periodEnd  Period end timestamp
     * @param  array<string, mixed>  $periodTotals  Period totals array
     * @param  array<string, mixed>  $perpetualTotals  Perpetual totals array
     * @param  string|null  $previousHash  Previous event hash (null for first)
     * @return string SHA-256 hash (64 characters)
     */
    private function calculateHash(
        string $eventType,
        int $sequenceNumber,
        Carbon $periodStart,
        Carbon $periodEnd,
        array $periodTotals,
        array $perpetualTotals,
        ?string $previousHash
    ): string {
        // Serialize data for hashing
        $data = sprintf(
            '%s|%d|%s|%s|%s|%s',
            $eventType,
            $sequenceNumber,
            $periodStart->toIso8601String(),
            $periodEnd->toIso8601String(),
            json_encode($periodTotals, JSON_UNESCAPED_UNICODE),
            json_encode($perpetualTotals, JSON_UNESCAPED_UNICODE)
        );

        // Concatenate previous hash + current data
        $payload = ($previousHash ?? 'GENESIS').'|'.$data;

        return hash('sha256', $payload);
    }

    /**
     * Verify grand total hash chain for an event type
     *
     * @param  Terminal  $terminal  The terminal to verify
     * @param  string  $eventType  Event type to verify (DAILY/MONTHLY/YEARLY)
     * @return bool True if chain is valid
     */
    public function verifyChain(Terminal $terminal, string $eventType): bool
    {
        $events = GrandtotalEvent::where('terminal_id', $terminal->id)
            ->where('event_type', $eventType)
            ->orderBy('sequence_number')
            ->get();

        if ($events->isEmpty()) {
            return true;  // No events yet, chain is valid
        }

        $previousHash = null;

        foreach ($events as $event) {
            // Check previous_hash linkage
            if ($event->previous_hash !== $previousHash) {
                return false;  // Chain broken
            }

            // Recalculate hash
            $expectedHash = $this->calculateHash(
                $event->event_type,
                $event->sequence_number,
                $event->period_start,
                $event->period_end,
                $event->period_totals,
                $event->perpetual_totals,
                $previousHash
            );

            if ($expectedHash !== $event->fiscal_hash) {
                return false;  // Hash mismatch
            }

            $previousHash = $event->fiscal_hash;
        }

        return true;
    }

    /**
     * Get last grand total event for an event type
     *
     * @param  Terminal  $terminal  The terminal to check
     * @param  string  $eventType  Event type (DAILY/MONTHLY/YEARLY)
     * @return GrandtotalEvent|null Last event or null if none exist
     */
    public function getLastEvent(Terminal $terminal, string $eventType): ?GrandtotalEvent
    {
        return GrandtotalEvent::where('terminal_id', $terminal->id)
            ->where('event_type', $eventType)
            ->orderByDesc('sequence_number')
            ->first();
    }
}
