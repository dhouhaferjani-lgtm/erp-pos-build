<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Domain\BankReconciliation;
use App\Modules\Treasury\Domain\BankReconciliationItem;
use App\Modules\Treasury\Domain\Enums\ReconciliationStatus;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Support\Facades\DB;

class BankReconciliationService
{
    /**
     * Start a new reconciliation session.
     *
     * @param array{
     *   repository_id: string,
     *   statement_date: string,
     *   statement_balance: string,
     *   notes?: string
     * } $data
     */
    public function startReconciliation(
        string $companyId,
        string $tenantId,
        string $userId,
        array $data
    ): BankReconciliation {
        $repository = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($data['repository_id']);

        // Get opening balance (last reconciled or 0)
        $openingBalance = $repository->last_reconciled_balance ?? '0.00';

        return DB::transaction(function () use ($companyId, $tenantId, $userId, $data, $repository, $openingBalance) {
            $reconciliation = BankReconciliation::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'repository_id' => $repository->id,
                'statement_date' => $data['statement_date'],
                'opening_balance' => $openingBalance,
                'closing_balance' => $repository->balance,
                'statement_balance' => $data['statement_balance'],
                'difference' => bcsub($data['statement_balance'], (string) $repository->balance, 2),
                'status' => ReconciliationStatus::Draft,
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            // Add unreconciled payments to the reconciliation
            $unreconciledPayments = Payment::query()
                ->where('tenant_id', $tenantId)
                ->where('repository_id', $repository->id)
                ->where('is_reconciled', false)
                ->get();

            foreach ($unreconciledPayments as $payment) {
                BankReconciliationItem::create([
                    'reconciliation_id' => $reconciliation->id,
                    'payment_id' => $payment->id,
                    'is_matched' => false,
                ]);
            }

            return $reconciliation->load(['items.payment', 'repository']);
        });
    }

    /**
     * Match a payment in a reconciliation.
     */
    public function matchItem(
        string $reconciliationId,
        string $paymentId,
        string $userId,
        ?string $bankReference = null,
        ?string $notes = null
    ): BankReconciliationItem {
        $item = BankReconciliationItem::query()
            ->where('reconciliation_id', $reconciliationId)
            ->where('payment_id', $paymentId)
            ->firstOrFail();

        $reconciliation = $item->reconciliation;
        if (! $reconciliation->isEditable()) {
            throw new \RuntimeException('Reconciliation is not editable');
        }

        $item->update([
            'is_matched' => true,
            'bank_reference' => $bankReference,
            'notes' => $notes,
            'matched_by' => $userId,
            'matched_at' => now(),
        ]);

        $this->updateReconciliationDifference($reconciliation);

        return $item->fresh(['payment']);
    }

    /**
     * Unmatch a payment in a reconciliation.
     */
    public function unmatchItem(string $reconciliationId, string $paymentId): BankReconciliationItem
    {
        $item = BankReconciliationItem::query()
            ->where('reconciliation_id', $reconciliationId)
            ->where('payment_id', $paymentId)
            ->firstOrFail();

        $reconciliation = $item->reconciliation;
        if (! $reconciliation->isEditable()) {
            throw new \RuntimeException('Reconciliation is not editable');
        }

        $item->update([
            'is_matched' => false,
            'bank_reference' => null,
            'notes' => null,
            'matched_by' => null,
            'matched_at' => null,
        ]);

        $this->updateReconciliationDifference($reconciliation);

        return $item->fresh(['payment']);
    }

    /**
     * Complete a reconciliation.
     */
    public function completeReconciliation(
        string $reconciliationId,
        string $userId
    ): BankReconciliation {
        /** @var BankReconciliation $reconciliation */
        $reconciliation = BankReconciliation::findOrFail($reconciliationId);

        if (! $reconciliation->isEditable()) {
            throw new \RuntimeException('Reconciliation is already completed or cancelled');
        }

        return DB::transaction(function () use ($reconciliation, $userId) {
            // Mark all matched payments as reconciled
            $matchedItems = $reconciliation->items()->where('is_matched', true)->with('payment')->get();

            foreach ($matchedItems as $item) {
                $item->payment->update([
                    'is_reconciled' => true,
                    'reconciled_at' => now(),
                ]);
            }

            // Update repository last reconciled info
            $reconciliation->repository->update([
                'last_reconciled_at' => now(),
                'last_reconciled_balance' => $reconciliation->statement_balance,
            ]);

            // Mark reconciliation as complete
            $reconciliation->update([
                'status' => ReconciliationStatus::Completed,
                'completed_by' => $userId,
                'completed_at' => now(),
            ]);

            return $reconciliation->fresh(['items.payment', 'repository']);
        });
    }

    /**
     * Cancel a reconciliation.
     */
    public function cancelReconciliation(string $reconciliationId): BankReconciliation
    {
        /** @var BankReconciliation $reconciliation */
        $reconciliation = BankReconciliation::findOrFail($reconciliationId);

        if (! $reconciliation->isEditable()) {
            throw new \RuntimeException('Reconciliation is already completed or cancelled');
        }

        $reconciliation->update([
            'status' => ReconciliationStatus::Cancelled,
        ]);

        return $reconciliation->fresh();
    }

    /**
     * Get reconciliation summary.
     *
     * @return array<string, mixed>
     */
    public function getReconciliationSummary(string $reconciliationId): array
    {
        /** @var BankReconciliation $reconciliation */
        $reconciliation = BankReconciliation::with(['items.payment', 'repository'])
            ->findOrFail($reconciliationId);

        $matchedItems = $reconciliation->items->where('is_matched', true);
        $unmatchedItems = $reconciliation->items->where('is_matched', false);

        $matchedTotal = $matchedItems->sum(fn ($item) => (float) $item->payment->amount);
        $unmatchedTotal = $unmatchedItems->sum(fn ($item) => (float) $item->payment->amount);

        return [
            'reconciliation_id' => $reconciliation->id,
            'repository_name' => $reconciliation->repository->name,
            'statement_date' => $reconciliation->statement_date->toDateString(),
            'opening_balance' => $reconciliation->opening_balance,
            'closing_balance' => $reconciliation->closing_balance,
            'statement_balance' => $reconciliation->statement_balance,
            'difference' => $reconciliation->difference,
            'status' => $reconciliation->status->value,
            'matched_count' => $matchedItems->count(),
            'unmatched_count' => $unmatchedItems->count(),
            'matched_total' => number_format($matchedTotal, 2, '.', ''),
            'unmatched_total' => number_format($unmatchedTotal, 2, '.', ''),
            'can_complete' => $reconciliation->isEditable() && bccomp($reconciliation->difference, '0.00', 2) === 0,
        ];
    }

    /**
     * Update reconciliation difference based on matched items.
     */
    private function updateReconciliationDifference(BankReconciliation $reconciliation): void
    {
        $matchedItems = $reconciliation->items()->where('is_matched', true)->with('payment')->get();
        $matchedTotal = $matchedItems->sum(fn ($item) => (float) $item->payment->amount);

        // Calculate expected balance based on matched transactions
        $expectedBalance = bcadd($reconciliation->opening_balance, number_format($matchedTotal, 2, '.', ''), 2);
        $difference = bcsub($reconciliation->statement_balance, $expectedBalance, 2);

        $reconciliation->update([
            'closing_balance' => $expectedBalance,
            'difference' => $difference,
        ]);
    }
}
