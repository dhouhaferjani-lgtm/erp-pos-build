<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Voucher\Application\DTOs\GenericLookupResult;
use App\Modules\Voucher\Application\DTOs\InSessionLookupResult;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Events\VoucherFraudAlert;
use App\Modules\Voucher\Domain\Events\VoucherVoided;
use App\Modules\Voucher\Domain\Exceptions\VoucherRateLimitedException;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use App\Modules\Voucher\Infrastructure\RateLimit\VoucherLookupRateLimiter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Voucher lookup service — controls disclosure boundary between in-session and out-of-session
 * lookup paths (spec §4.7).
 *
 * Key security invariants:
 *   1. "Invalid" (not found) and "rate-limited" produce IDENTICAL GenericLookupResult::invalid()
 *      responses, preventing enumeration attacks.
 *   2. Full balance/expiry details are ONLY disclosed inside an active payment session
 *      (open receipt with FiscalStatus::PendingSeal).
 *   3. Five consecutive failed voucher-code attempts trigger auto-void + VoucherFraudAlert.
 */
final class VoucherLookupService
{
    /**
     * Tenant prefix length (Phase 1 hardcoded: "POSC" = 4 chars).
     * The "code prefix" for rate-limiting is the next 4 chars after this.
     */
    private const TENANT_PREFIX_LEN = 4;

    public function __construct(
        private readonly VoucherLookupRateLimiter $rateLimiter,
        private readonly GeneralLedgerService $generalLedger,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Outside-session lookup — generic disclosure only (exists + status).
     *
     * Returns GenericLookupResult::invalid() on any failure mode, including
     * rate-limit trips, so the caller cannot distinguish them from a missing code.
     */
    public function lookupGeneric(
        string $code,
        Terminal $terminal,
        User $cashier,
        ?string $ipAddress,
    ): GenericLookupResult {
        $codePrefix = $this->extractCodePrefix($code);

        try {
            $this->rateLimiter->checkAll($codePrefix, $terminal, $cashier, $ipAddress);
        } catch (VoucherRateLimitedException) {
            // Intentionally identical response: prevents enumeration of rate-limit state.
            return GenericLookupResult::invalid();
        }

        $voucher = Voucher::where('code', $code)->first();

        if ($voucher === null) {
            $this->rateLimiter->recordTenantFailedAttempt($terminal->tenant_id);

            return GenericLookupResult::invalid();
        }

        // Voided → generic invalid (do NOT tell the caller it's voided vs missing)
        if ($voucher->status === VoucherStatus::Voided) {
            $this->rateLimiter->recordTenantFailedAttempt($terminal->tenant_id);

            return GenericLookupResult::invalid();
        }

        // Expired or fully redeemed
        if (
            $voucher->status === VoucherStatus::Expired
            || $voucher->status === VoucherStatus::FullyRedeemed
            || ($voucher->expires_at !== null && $voucher->expires_at->isPast())
        ) {
            return GenericLookupResult::expired();
        }

        return GenericLookupResult::active();
    }

    /**
     * In-session lookup — full disclosure (balance, expiry, redemption mode, partner match).
     *
     * The $openReceipt must have FiscalStatus::PendingSeal; otherwise falls back to
     * generic disclosure (caller should treat the session as not active).
     *
     * Returns GenericLookupResult on any failure path; InSessionLookupResult only on success.
     */
    public function lookupForPayment(
        string $code,
        Receipt $openReceipt,
        Terminal $terminal,
        User $cashier,
        ?string $ipAddress,
    ): GenericLookupResult|InSessionLookupResult {
        // If the receipt is not in PendingSeal state, treat as outside-session.
        if ($openReceipt->fiscal_status !== FiscalStatus::PendingSeal) {
            return $this->lookupGeneric($code, $terminal, $cashier, $ipAddress);
        }

        $codePrefix = $this->extractCodePrefix($code);

        try {
            $this->rateLimiter->checkAll($codePrefix, $terminal, $cashier, $ipAddress);
        } catch (VoucherRateLimitedException) {
            return GenericLookupResult::invalid();
        }

        $voucher = Voucher::where('code', $code)->first();

        if ($voucher === null) {
            $this->rateLimiter->recordTenantFailedAttempt($terminal->tenant_id);
            $this->handleVoucherFailedAttemptByCode($code, $terminal->id, $cashier->id);

            return GenericLookupResult::invalid();
        }

        // Not currently active → treat as generic invalid (record failed attempt)
        if (! in_array($voucher->status, [VoucherStatus::Issued, VoucherStatus::PartiallyRedeemed], true)) {
            $this->rateLimiter->recordTenantFailedAttempt($terminal->tenant_id);
            $this->handleVoucherFailedAttempt($voucher, $terminal->id, $cashier->id);

            return GenericLookupResult::invalid();
        }

        // Expired
        if ($voucher->expires_at !== null && $voucher->expires_at->isPast()) {
            $this->rateLimiter->recordTenantFailedAttempt($terminal->tenant_id);

            return GenericLookupResult::invalid();
        }

        // Determine partner match
        $partnerIdMatch = $this->resolvePartnerMatch($voucher, $openReceipt->partner_id);

        return new InSessionLookupResult(
            voucherId: $voucher->id,
            balance: $voucher->current_balance,
            currency: $voucher->currency,
            redemptionMode: $voucher->redemption_mode,
            expiresAt: $voucher->expires_at,
            partnerIdMatch: $partnerIdMatch,
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Handle a failed attempt when we already have the voucher model.
     */
    private function handleVoucherFailedAttempt(Voucher $voucher, string $terminalId, string $cashierId): void
    {
        $attempts = $this->rateLimiter->recordFailedAttempt($voucher->id);

        if ($attempts >= VoucherLookupRateLimiter::PER_VOUCHER_FAILED_24H) {
            $this->autoVoidVoucher($voucher, $terminalId, $cashierId, $attempts);
        }
    }

    /**
     * Handle a failed attempt when the voucher does not exist in DB.
     * We cannot record per-voucher attempts without a known voucher-id.
     * Only tenant-level counters apply in this case.
     */
    private function handleVoucherFailedAttemptByCode(string $code, string $terminalId, string $cashierId): void
    {
        // No voucher ID available — cannot record per-voucher failed attempts.
        // Tenant-level recording already done by the caller before this method.
    }

    /**
     * Auto-void a voucher that has accumulated >= 5 failed lookup attempts.
     *
     * Pre-condition: the voucher must have NO prior Redeemed events. If it does,
     * we emit only the fraud alert (do not void) and log an error.
     */
    private function autoVoidVoucher(Voucher $voucher, string $terminalId, string $cashierId, int $attempts): void
    {
        // Safety check: if there are redemptions, only alert — do NOT void.
        $hasRedemptions = VoucherLedger::where('voucher_id', $voucher->id)
            ->where('event', VoucherEvent::Redeemed->value)
            ->exists();

        if ($hasRedemptions) {
            Log::error('Voucher fraud-auto-void skipped: voucher has redemptions despite 5 failed attempts.', [
                'voucher_id' => $voucher->id,
                'attempts' => $attempts,
            ]);

            $this->events->dispatch(new VoucherFraudAlert(
                voucherId: $voucher->id,
                tenantId: $voucher->tenant_id,
                companyId: $voucher->company_id,
                terminalId: $terminalId,
                cashierId: $cashierId,
                attemptsCount: $attempts,
                occurredAt: Carbon::now()->toIso8601String(),
            ));

            return;
        }

        DB::transaction(function () use ($voucher, $terminalId, $cashierId, $attempts): void {
            $now = Carbon::now();
            $voidedBalance = $voucher->current_balance;

            // Build unsaved Voided ledger row; create GL entry first if needed; then INSERT.
            $voidedAmount = bccomp($voidedBalance, '0', 5) > 0
                ? bcmul($voidedBalance, '-1', 5)
                : '0.00000';

            $voidedId = (string) Str::uuid();
            $glEntry = null;

            if (bccomp($voidedBalance, '0', 5) > 0) {
                $unsavedVoided = new VoucherLedger;
                $unsavedVoided->id = $voidedId;
                $unsavedVoided->tenant_id = $voucher->tenant_id;
                $unsavedVoided->company_id = $voucher->company_id;
                $unsavedVoided->voucher_id = $voucher->id;
                $unsavedVoided->event = VoucherEvent::Voided;
                $unsavedVoided->amount = $voidedAmount;
                $unsavedVoided->currency = $voucher->currency;
                $unsavedVoided->receipt_id = null;
                $unsavedVoided->terminal_id = $terminalId;
                $unsavedVoided->user_id = $cashierId;
                $unsavedVoided->gl_journal_entry_id = null;
                $unsavedVoided->authorized_by_user_id = null;
                $unsavedVoided->policy_trigger = 'auto_fraud_void';
                $unsavedVoided->reverses_voucher_ledger_id = null;
                $unsavedVoided->occurred_at = $now;

                // Write GL reversal (only if there's something to reverse)
                $glEntry = $this->generalLedger->createVoucherLedgerEntry($unsavedVoided, $voucher);
            }

            // INSERT the Voided ledger row with gl_journal_entry_id already set (no UPDATE).
            /** @var VoucherLedger $ledgerRow */
            $ledgerRow = VoucherLedger::forceCreate([
                'id' => $voidedId,
                'tenant_id' => $voucher->tenant_id,
                'company_id' => $voucher->company_id,
                'voucher_id' => $voucher->id,
                'event' => VoucherEvent::Voided,
                'amount' => $voidedAmount,
                'currency' => $voucher->currency,
                'receipt_id' => null,
                'terminal_id' => $terminalId,
                'user_id' => $cashierId,
                'gl_journal_entry_id' => $glEntry !== null ? $glEntry->id : null,
                'authorized_by_user_id' => null,
                'policy_trigger' => 'auto_fraud_void',
                'reverses_voucher_ledger_id' => null,
                'occurred_at' => $now,
            ]);

            // Update voucher status and balance
            $voucher->status = VoucherStatus::Voided;
            $voucher->current_balance = '0.00000';
            $voucher->save();

            $this->events->dispatch(new VoucherFraudAlert(
                voucherId: $voucher->id,
                tenantId: $voucher->tenant_id,
                companyId: $voucher->company_id,
                terminalId: $terminalId,
                cashierId: $cashierId,
                attemptsCount: $attempts,
                occurredAt: $now->toIso8601String(),
            ));

            $this->events->dispatch(new VoucherVoided(
                voucherId: $voucher->id,
                tenantId: $voucher->tenant_id,
                companyId: $voucher->company_id,
                code: $voucher->code,
                voidedBalance: $voidedBalance,
                voidReason: 'auto_fraud_void',
                glJournalEntryId: $glEntry !== null ? $glEntry->id : '',
                occurredAt: $now->toIso8601String(),
            ));
        });
    }

    /**
     * Resolve whether the voucher's partner matches the session's partner.
     *
     * - Bearer vouchers: always true (no partner restriction).
     * - CustomerBound vouchers: true when issued_to_partner_id matches the receipt's partner_id.
     */
    private function resolvePartnerMatch(Voucher $voucher, ?string $sessionPartnerId): bool
    {
        if ($voucher->redemption_mode !== RedemptionMode::CustomerBound) {
            return true;
        }

        if ($voucher->issued_to_partner_id === null) {
            return true;
        }

        return $voucher->issued_to_partner_id === $sessionPartnerId;
    }

    /**
     * Extract the code-prefix segment for rate-limiting.
     *
     * The code format is: <4-char-tenant-prefix>-<4-char-unique>-...
     * We take the next 4 characters after the tenant prefix (skipping any separator).
     * Falls back to the first min(8, strlen($code)) characters if the code is unusual.
     */
    private function extractCodePrefix(string $code): string
    {
        // Strip separators and grab the second "word" (chars 4-7 of the stripped code).
        $stripped = str_replace(['-', ' '], '', $code);

        if (strlen($stripped) >= self::TENANT_PREFIX_LEN * 2) {
            return substr($stripped, self::TENANT_PREFIX_LEN, self::TENANT_PREFIX_LEN);
        }

        return substr($stripped, 0, min(8, strlen($stripped)));
    }
}
