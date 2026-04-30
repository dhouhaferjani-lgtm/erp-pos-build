<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Domain\ValueObjects\ReservationSettings;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Events\BroadCustomerSearchAlert;
use App\Modules\POS\Domain\Exceptions\InsufficientSearchSpecificityException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Infrastructure\RateLimit\CustomerHistorySearchRateLimiter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Privacy-hardened customer history search for POS refund flow.
 *
 * PRIVACY REQUIREMENTS (spec §2.6, §3.5, §4.7):
 *
 *   1. Minimum specificity: the search input must be a full email address,
 *      E.164 phone number, or partner UUID (from a loyalty QR scan). Partial
 *      names are rejected with InsufficientSearchSpecificityException.
 *
 *   2. Rate limiting: per-cashier daily cap from ReservationSettings.
 *      On cap exceeded, return empty collection (no error — cashier cannot
 *      use the limit error as a signal for enumeration).
 *
 *   3. Single-terminal scope (Phase 1): receipts for other terminals are
 *      never returned, even if the partner has history there.
 *
 *   4. Audit logging: every search (allowed or rejected) writes a row to
 *      customer_history_searches. The raw input is NOT stored; instead a
 *      SHA-256 hash of the input is written.
 *
 *   5. Alert events: BroadCustomerSearchAlert is dispatched when:
 *      - The same cashier searches the same partner more than
 *        ReservationSettings.customerHistorySearchAlertThresholds.same_partner_per_day times.
 *      - A cross-company access is detected (partner's company ≠ terminal's company)
 *        when cross_company_immediate threshold is enabled.
 *
 * PRESENTATION LAYER NOTE:
 *   This service returns Receipt models with all fields populated. The controller/
 *   resource layer MUST hide the `total` field until the cashier explicitly opens
 *   an individual receipt row.
 */
final class CustomerHistorySearchService
{
    public function __construct(
        private readonly CustomerHistorySearchRateLimiter $rateLimiter,
        private readonly Dispatcher $events,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Search a customer's receipt history by raw input string.
     *
     * Validates input specificity, resolves the partner, applies rate limiting,
     * runs the terminal-scoped DB query, writes an audit row, and emits alert
     * events when thresholds are crossed.
     *
     * @return Collection<int, Receipt>
     *
     * @throws InsufficientSearchSpecificityException when input is not specific enough
     */
    public function search(
        string $searchInput,
        User $cashier,
        Terminal $terminal,
        ReservationSettings $policy,
    ): Collection {
        $inputHash = hash('sha256', $searchInput);

        // Step 1 — Minimum specificity check.
        if (! $this->isSpecificEnough($searchInput)) {
            $this->writeAuditRow(
                cashierId: $cashier->id,
                terminalId: $terminal->id,
                tenantId: $terminal->tenant_id,
                companyId: $terminal->company_id,
                partnerId: null,
                inputHash: $inputHash,
                resultCount: 0,
                wasRejected: true,
                rejectionReason: 'input_not_specific',
            );

            throw new InsufficientSearchSpecificityException(
                'Provide a full email address, E.164 phone number, or loyalty card UUID.'
            );
        }

        // Step 2 — Rate limit check.
        $allowed = $this->rateLimiter->checkAndIncrement(
            $cashier->id,
            $policy->customerHistorySearchMaxPerCashierPerDay,
        );

        if (! $allowed) {
            // Return empty — do NOT expose the rate-limit reason to the cashier.
            $this->writeAuditRow(
                cashierId: $cashier->id,
                terminalId: $terminal->id,
                tenantId: $terminal->tenant_id,
                companyId: $terminal->company_id,
                partnerId: null,
                inputHash: $inputHash,
                resultCount: 0,
                wasRejected: true,
                rejectionReason: 'rate_limit_exceeded',
            );

            return new Collection;
        }

        // Step 3 — Resolve partner.
        $partner = $this->resolvePartner($searchInput, $terminal->tenant_id);

        if ($partner === null) {
            $this->writeAuditRow(
                cashierId: $cashier->id,
                terminalId: $terminal->id,
                tenantId: $terminal->tenant_id,
                companyId: $terminal->company_id,
                partnerId: null,
                inputHash: $inputHash,
                resultCount: 0,
                wasRejected: false,
                rejectionReason: null,
            );

            return new Collection;
        }

        // Step 4 — Query receipts (terminal-scoped + permission-bound window).
        //
        // Phase H Block 2.5c — defence-in-depth permission gate. Cashiers with
        // `pos.search_customer_full_history` see the full current fiscal year
        // (calendar year for Phase 1; custom fiscal-year start dates per tenant
        // are deferred to Phase 2). Cashiers with only the recent-purchases
        // permission stay on the existing `customerHistoryWindowDays` slice.
        //
        // This service isn't wired to an HTTP route today, so this is
        // defensive: when an HTTP entry-point is added, the permission flag
        // already gates the SQL window without further refactor.
        $hasFullHistory = $cashier->can('pos.search_customer_full_history');
        $windowStart = $hasFullHistory
            ? Carbon::now()->startOfYear()
            : Carbon::now()->subDays($policy->customerHistoryWindowDays)->startOfDay();

        $receipts = Receipt::query()
            ->where('partner_id', $partner->id)
            ->where('terminal_id', $terminal->id) // Phase 1: single-terminal scope (SQL)
            ->where('tenant_id', $terminal->tenant_id)
            ->where('posted_at', '>=', $windowStart)
            ->where('is_voided', false)
            ->orderByDesc('posted_at')
            ->get();

        // Step 5 — Audit log.
        $this->writeAuditRow(
            cashierId: $cashier->id,
            terminalId: $terminal->id,
            tenantId: $terminal->tenant_id,
            companyId: $terminal->company_id,
            partnerId: $partner->id,
            inputHash: $inputHash,
            resultCount: $receipts->count(),
            wasRejected: false,
            rejectionReason: null,
        );

        // Step 6 — Alert events.
        $this->maybeDispatchAlert(
            cashier: $cashier,
            partner: $partner,
            terminal: $terminal,
            policy: $policy,
        );

        // Cross-company alert: partner's company ≠ terminal's company.
        if (
            $policy->customerHistorySearchAlertThresholds['cross_company_immediate'] === true
            && $partner->company_id !== $terminal->company_id
        ) {
            $this->events->dispatch(new BroadCustomerSearchAlert(
                cashierId: $cashier->id,
                partnerId: $partner->id,
                terminalId: $terminal->id,
                tenantId: $terminal->tenant_id,
                searchCountToday: $this->rateLimiter->countToday($cashier->id),
                alertReason: 'cross_company_access',
                occurredAt: Carbon::now()->toIso8601String(),
            ));
        }

        return $receipts;
    }

    /**
     * Search by a previously resolved partner (used by ReceiptLookupService::findByCustomer).
     *
     * This entry point skips the specificity check (the partner is already resolved)
     * but still applies rate limiting, audit, and alert logic.
     *
     * @return Collection<int, Receipt>
     */
    public function searchByPartner(
        Partner $partner,
        User $cashier,
        Terminal $terminal,
    ): Collection {
        $policy = new ReservationSettings;
        $inputHash = hash('sha256', $partner->id);

        $allowed = $this->rateLimiter->checkAndIncrement(
            $cashier->id,
            $policy->customerHistorySearchMaxPerCashierPerDay,
        );

        if (! $allowed) {
            $this->writeAuditRow(
                cashierId: $cashier->id,
                terminalId: $terminal->id,
                tenantId: $terminal->tenant_id,
                companyId: $terminal->company_id,
                partnerId: $partner->id,
                inputHash: $inputHash,
                resultCount: 0,
                wasRejected: true,
                rejectionReason: 'rate_limit_exceeded',
            );

            return new Collection;
        }

        // Phase H Block 2.5c — same permission-bound window as `search()`.
        // Phase 1 uses calendar-year boundaries; Phase 2 will read custom
        // fiscal-year start dates per tenant.
        $hasFullHistory = $cashier->can('pos.search_customer_full_history');
        $windowStart = $hasFullHistory
            ? Carbon::now()->startOfYear()
            : Carbon::now()->subDays($policy->customerHistoryWindowDays)->startOfDay();

        $receipts = Receipt::query()
            ->where('partner_id', $partner->id)
            ->where('terminal_id', $terminal->id) // Phase 1 single-terminal scope
            ->where('tenant_id', $terminal->tenant_id)
            ->where('posted_at', '>=', $windowStart)
            ->where('is_voided', false)
            ->orderByDesc('posted_at')
            ->get();

        $this->writeAuditRow(
            cashierId: $cashier->id,
            terminalId: $terminal->id,
            tenantId: $terminal->tenant_id,
            companyId: $terminal->company_id,
            partnerId: $partner->id,
            inputHash: $inputHash,
            resultCount: $receipts->count(),
            wasRejected: false,
            rejectionReason: null,
        );

        $this->maybeDispatchAlert($cashier, $partner, $terminal, $policy);

        return $receipts;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Check if the search input meets the minimum specificity requirement.
     *
     * Accepted:
     *   - Full email address (RFC 5322 simplified pattern)
     *   - E.164 phone number (starts with +, 7-15 digits)
     *   - UUID (partner UUID from a loyalty QR scan)
     */
    private function isSpecificEnough(string $input): bool
    {
        $trimmed = trim($input);

        // UUID (partner-id from QR scan)
        if (Str::isUuid($trimmed)) {
            return true;
        }

        // E.164 phone: + followed by 7-15 digits
        if (preg_match('/^\+[1-9]\d{6,14}$/', $trimmed) === 1) {
            return true;
        }

        // Email address
        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }

        return false;
    }

    /**
     * Resolve a partner from the search input within the given tenant.
     *
     * Returns null if no matching partner is found.
     */
    private function resolvePartner(string $searchInput, string $tenantId): ?Partner
    {
        $trimmed = trim($searchInput);

        // UUID → direct partner lookup
        if (Str::isUuid($trimmed)) {
            return Partner::where('id', $trimmed)
                ->where('tenant_id', $tenantId)
                ->first();
        }

        // E.164 phone
        if (preg_match('/^\+[1-9]\d{6,14}$/', $trimmed) === 1) {
            return Partner::where('phone', $trimmed)
                ->where('tenant_id', $tenantId)
                ->first();
        }

        // Email
        return Partner::where('email', $trimmed)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * Write an audit row to customer_history_searches.
     */
    private function writeAuditRow(
        string $cashierId,
        string $terminalId,
        string $tenantId,
        string $companyId,
        ?string $partnerId,
        string $inputHash,
        int $resultCount,
        bool $wasRejected,
        ?string $rejectionReason,
    ): void {
        DB::table('customer_history_searches')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'cashier_id' => $cashierId,
            'terminal_id' => $terminalId,
            'partner_id' => $partnerId,
            'search_terms_hash' => $inputHash,
            'result_count' => $resultCount,
            'was_rejected' => $wasRejected,
            'rejection_reason' => $rejectionReason,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Check today's search count for a specific partner by this cashier,
     * and dispatch BroadCustomerSearchAlert if it exceeds the threshold.
     */
    private function maybeDispatchAlert(
        User $cashier,
        Partner $partner,
        Terminal $terminal,
        ReservationSettings $policy,
    ): void {
        $threshold = (int) $policy->customerHistorySearchAlertThresholds['same_partner_per_day'];

        $countSamePartnerToday = DB::table('customer_history_searches')
            ->where('cashier_id', $cashier->id)
            ->where('partner_id', $partner->id)
            ->where('was_rejected', false)
            ->whereDate('created_at', Carbon::today())
            ->count();

        if ($countSamePartnerToday >= $threshold) {
            $this->events->dispatch(new BroadCustomerSearchAlert(
                cashierId: $cashier->id,
                partnerId: $partner->id,
                terminalId: $terminal->id,
                tenantId: $terminal->tenant_id,
                searchCountToday: $countSamePartnerToday,
                alertReason: 'same_partner_repeated',
                occurredAt: Carbon::now()->toIso8601String(),
            ));
        }
    }
}
