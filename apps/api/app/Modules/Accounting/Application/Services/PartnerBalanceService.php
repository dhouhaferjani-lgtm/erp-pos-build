<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Partner\Domain\Partner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing partner balances from the General Ledger subledger.
 *
 * This service provides:
 * - Real-time balance calculation from GL
 * - Cached balance management for performance
 * - Subledger reconciliation against control accounts
 * - Partner statements/transaction history
 */
class PartnerBalanceService
{
    /**
     * Get the current balance for a specific partner.
     *
     * For customers: positive = they owe us, negative = we owe them (credit balance)
     * For suppliers: positive = we owe them, negative = they owe us (debit balance)
     *
     * @return array{balance: string, debit_total: string, credit_total: string, transaction_count: int}
     */
    public function getPartnerBalance(
        string $companyId,
        string $partnerId,
        ?SystemAccountPurpose $purpose = null
    ): array {
        $query = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_lines.partner_id', $partnerId)
            ->where('journal_entries.status', 'posted');

        if ($purpose !== null) {
            $query->where('accounts.system_purpose', $purpose->value);
        }

        /** @var object{debit_total: string|null, credit_total: string|null, transaction_count: int|null}|null $result */
        $result = $query->selectRaw('
            COALESCE(SUM(journal_lines.debit), 0) as debit_total,
            COALESCE(SUM(journal_lines.credit), 0) as credit_total,
            COUNT(*) as transaction_count
        ')->first();

        /** @var numeric-string $debitTotal */
        $debitTotal = (string) ($result->debit_total ?? '0');
        /** @var numeric-string $creditTotal */
        $creditTotal = (string) ($result->credit_total ?? '0');
        $balance = bcsub($debitTotal, $creditTotal, 4);

        return [
            'balance' => $balance,
            'debit_total' => $debitTotal,
            'credit_total' => $creditTotal,
            'transaction_count' => (int) ($result->transaction_count ?? 0),
        ];
    }

    /**
     * Get customer receivable balance (what they owe us).
     */
    public function getCustomerReceivableBalance(string $companyId, string $partnerId): string
    {
        $result = $this->getPartnerBalance($companyId, $partnerId, SystemAccountPurpose::CustomerReceivable);

        return $result['balance'];
    }

    /**
     * Get customer advance balance (prepayments/credits we owe them).
     */
    public function getCustomerAdvanceBalance(string $companyId, string $partnerId): string
    {
        $result = $this->getPartnerBalance($companyId, $partnerId, SystemAccountPurpose::CustomerAdvance);

        return $result['balance'];
    }

    /**
     * Get supplier payable balance (what we owe them).
     */
    public function getSupplierPayableBalance(string $companyId, string $partnerId): string
    {
        $result = $this->getPartnerBalance($companyId, $partnerId, SystemAccountPurpose::SupplierPayable);

        return $result['balance'];
    }

    /**
     * Get all partner balances for a company (for listing/reporting).
     *
     * @return Collection<int, \stdClass>
     */
    public function getAllPartnerBalances(
        string $companyId,
        SystemAccountPurpose $purpose,
        bool $excludeZeroBalances = true
    ): Collection {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->join('partners', 'journal_lines.partner_id', '=', 'partners.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('accounts.system_purpose', $purpose->value)
            ->where('journal_entries.status', 'posted')
            ->whereNotNull('journal_lines.partner_id')
            ->groupBy('journal_lines.partner_id', 'partners.name', 'partners.code')
            ->selectRaw('
                journal_lines.partner_id,
                partners.name as partner_name,
                partners.code as partner_code,
                COALESCE(SUM(journal_lines.debit), 0) as debit_total,
                COALESCE(SUM(journal_lines.credit), 0) as credit_total,
                COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0) as balance,
                COUNT(*) as transaction_count
            ');

        if ($excludeZeroBalances) {
            $query->havingRaw('ABS(COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0)) > 0.0001');
        }

        return $query->orderBy('partners.name')->get();
    }

    /**
     * Get subledger total (sum of all partner balances for an account purpose).
     * This should match the control account balance.
     */
    public function getSubledgerTotal(string $companyId, SystemAccountPurpose $purpose): string
    {
        /** @var object{total: string|null}|null $result */
        $result = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('accounts.system_purpose', $purpose->value)
            ->where('journal_entries.status', 'posted')
            ->whereNotNull('journal_lines.partner_id')
            ->selectRaw('
                COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0) as total
            ')
            ->first();

        return (string) ($result->total ?? '0');
    }

    /**
     * Get control account balance (total balance of the account itself).
     */
    public function getControlAccountBalance(string $companyId, SystemAccountPurpose $purpose): string
    {
        $account = Account::findByPurposeOrFail($companyId, $purpose);

        /** @var object{total: string|null}|null $result */
        $result = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.status', 'posted')
            ->selectRaw('
                COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0) as total
            ')
            ->first();

        return (string) ($result->total ?? '0');
    }

    /**
     * Reconcile subledger against control account.
     *
     * Returns reconciliation status:
     * - If balanced: difference = 0
     * - If not balanced: shows the discrepancy
     *
     * @return array{is_balanced: bool, control_account_balance: string, subledger_total: string, difference: string, entries_without_partner: int, account_code: string, account_name: string}
     */
    public function reconcileSubledger(string $companyId, SystemAccountPurpose $purpose): array
    {
        /** @var numeric-string $subledgerTotal */
        $subledgerTotal = $this->getSubledgerTotal($companyId, $purpose);
        /** @var numeric-string $controlBalance */
        $controlBalance = $this->getControlAccountBalance($companyId, $purpose);
        $difference = bcsub($controlBalance, $subledgerTotal, 4);

        // Find entries without partner_id (potential cause of discrepancy)
        $account = Account::findByPurposeOrFail($companyId, $purpose);
        $entriesWithoutPartner = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.status', 'posted')
            ->whereNull('journal_lines.partner_id')
            ->count();

        return [
            'is_balanced' => bccomp($difference, '0', 4) === 0,
            'control_account_balance' => $controlBalance,
            'subledger_total' => $subledgerTotal,
            'difference' => $difference,
            'entries_without_partner' => $entriesWithoutPartner,
            'account_code' => $account->code,
            'account_name' => $account->name,
        ];
    }

    /**
     * Get partner statement (list of transactions for a partner).
     *
     * @return Collection<int, \stdClass>
     */
    public function getPartnerStatement(
        string $companyId,
        string $partnerId,
        ?SystemAccountPurpose $purpose = null,
        ?string $fromDate = null,
        ?string $toDate = null
    ): Collection {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_lines.partner_id', $partnerId)
            ->where('journal_entries.status', 'posted')
            ->select([
                'journal_entries.id as entry_id',
                'journal_entries.entry_date',
                'journal_entries.entry_number as reference',
                'journal_entries.description',
                'accounts.code as account_code',
                'accounts.name as account_name',
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.description as line_description',
            ])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id');

        if ($purpose !== null) {
            $query->where('accounts.system_purpose', $purpose->value);
        }

        if ($fromDate !== null) {
            $query->where('journal_entries.entry_date', '>=', $fromDate);
        }

        if ($toDate !== null) {
            $query->where('journal_entries.entry_date', '<=', $toDate);
        }

        // Add running balance
        $transactions = $query->get();
        /** @var numeric-string $runningBalance */
        $runningBalance = '0';

        return $transactions->map(function (object $tx) use (&$runningBalance): object {
            /** @var numeric-string $credit */
            $credit = (string) ($tx->credit ?? '0');
            /** @var numeric-string $debit */
            $debit = (string) ($tx->debit ?? '0');
            $runningBalance = bcadd(
                bcsub($runningBalance, $credit, 4),
                $debit,
                4
            );
            $tx->running_balance = $runningBalance;

            return $tx;
        });
    }

    /**
     * Refresh cached balance for a specific partner.
     * Call this after any GL entry affecting the partner.
     */
    public function refreshPartnerBalance(string $companyId, string $partnerId): void
    {
        $partner = Partner::findOrFail($partnerId);

        // Calculate receivable balance (customer: what they owe us)
        $receivableResult = $this->getPartnerBalance(
            $companyId,
            $partnerId,
            SystemAccountPurpose::CustomerReceivable
        );

        // Calculate credit balance (customer advances: what we owe them)
        $creditResult = $this->getPartnerBalance(
            $companyId,
            $partnerId,
            SystemAccountPurpose::CustomerAdvance
        );

        // Calculate payable balance (supplier: what we owe them)
        $payableResult = $this->getPartnerBalance(
            $companyId,
            $partnerId,
            SystemAccountPurpose::SupplierPayable
        );

        $partner->update([
            'receivable_balance' => $receivableResult['balance'],
            'credit_balance' => $creditResult['balance'],
            'payable_balance' => $payableResult['balance'],
            'balance_updated_at' => now(),
        ]);
    }

    /**
     * Refresh cached balances for all partners in a company.
     * Useful for batch reconciliation or initial setup.
     */
    public function refreshAllPartnerBalances(string $companyId): int
    {
        $partners = Partner::where('company_id', $companyId)->get();
        $count = 0;

        foreach ($partners as $partner) {
            $this->refreshPartnerBalance($companyId, $partner->id);
            $count++;
        }

        return $count;
    }

    /**
     * Get cached balance from partner (fast, for display).
     * Falls back to GL calculation if cache is stale.
     *
     * @return array{receivable_balance: string, credit_balance: string, payable_balance: string, net_balance: string, balance_updated_at: \Illuminate\Support\Carbon|null, is_from_cache: bool}
     */
    public function getCachedOrCalculateBalance(
        string $companyId,
        string $partnerId,
        bool $refreshIfStale = true,
        int $staleMinutes = 60
    ): array {
        $partner = Partner::findOrFail($partnerId);

        if ($refreshIfStale && $partner->isBalanceStale($staleMinutes)) {
            $this->refreshPartnerBalance($companyId, $partnerId);
            $partner->refresh();
        }

        return [
            'receivable_balance' => $partner->receivable_balance ?? '0',
            'credit_balance' => $partner->credit_balance ?? '0',
            'payable_balance' => $partner->payable_balance ?? '0',
            'net_balance' => $partner->net_balance,
            'balance_updated_at' => $partner->balance_updated_at,
            'is_from_cache' => true,
        ];
    }
}
