<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Shared\Domain\CurrencyScale;

/**
 * Read model for how a deposit's money landed — settled against open invoices vs.
 * credited as advance. Owns all access to the Treasury `payments` / payment-
 * allocation tables so cross-module callers (the Partner deposit orchestrator)
 * never touch those models directly (CLAUDE.md rule 6).
 *
 * The figures are derived from the persisted `payment_allocations` (which the
 * shared allocation engine writes immediately), NOT from GL balances — customer-
 * advance journal entries are created as drafts and only affect the cached
 * partner balance once the accounting cycle posts them, so balances are
 * eventually-consistent while the allocation split is exact at write time.
 */
final class DepositAllocationSummaryService
{
    /**
     * @return array{settled: string, credited: string}
     */
    public function summaryForFiscalEvent(string $tenantId, string $companyId, string $fiscalEventId, int $scale): array
    {
        $zero = CurrencyScale::bcformat('0', $scale);

        $payment = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('fiscal_event_id', $fiscalEventId)
            ->first();

        if (! $payment instanceof Payment) {
            // Treasury inactive for this (tenant, company): the deposit recorded a
            // fiscal event + printable receipt but no money moved.
            return ['settled' => $zero, 'credited' => $zero];
        }

        $settledRaw = PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->get()
            ->reduce(
                static fn (string $carry, PaymentAllocation $allocation): string => bcadd($carry, (string) $allocation->amount, $scale),
                '0',
            );

        $settled = CurrencyScale::bcformat($settledRaw, $scale);
        $amount = CurrencyScale::bcformat((string) $payment->amount, $scale);

        $credited = bccomp($amount, $settled, $scale) > 0
            ? bcsub($amount, $settled, $scale)
            : $zero;

        return ['settled' => $settled, 'credited' => $credited];
    }
}
