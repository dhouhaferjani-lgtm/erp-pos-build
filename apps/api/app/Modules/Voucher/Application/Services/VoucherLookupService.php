<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Services;

use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Voucher\Application\DTOs\GenericLookupResult;
use App\Modules\Voucher\Application\DTOs\InSessionLookupResult;
use App\Modules\Voucher\Application\DTOs\VoucherVoidRequest;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Events\VoucherFraudAlert;
use App\Modules\Voucher\Domain\Exceptions\VoucherInvalidStatusException;
use App\Modules\Voucher\Domain\Exceptions\VoucherRateLimitedException;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use App\Modules\Voucher\Infrastructure\RateLimit\VoucherLookupRateLimiter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Voucher lookup service — controls disclosure boundary between in-session and out-of-session
 * lookup paths (spec §4.7).
 *
 * Key security invariants:
 *   1. "Invalid" (not found) and "rate-limited" produce IDENTICAL GenericLookupResult::invalid()
 *      responses, preventing enumeration attacks.
 *   2. Full balance/expiry details are ONLY disclosed inside an active payment session
 *      (open receipt with FiscalStatus::PendingSeal).
 *   3. Five consecutive failed voucher-code attempts trigger VoucherFraudAlert. In
 *      PRACTICE that alert is the ENTIRE remediation: the per-voucher failure counter is
 *      only ever incremented from lookupInSession() :156-158, i.e. for a voucher whose
 *      status is already NOT Issued/PartiallyRedeemed, and every such status
 *      (FullyRedeemed, Expired, Voided) is terminal for the void edge. The
 *      VoucherVoidService call in autoVoidVoucher() therefore refuses or idempotently
 *      short-circuits on every reachable input — this arm is ALERT-ONLY. It is retained
 *      as a fail-closed backstop, not as live protection.
 *
 *      Session B lane Q-5 verified this disjointness rather than assuming it: the
 *      pre-Q-5 code took the same unreachable branch WITHOUT a status guard, so all it
 *      could do was append a second `voided` ledger row to an already-voided voucher
 *      (sweep findings #22/#24). No protection was lost by closing it, because none
 *      existed. REGISTERED PROGRAM GAP (treasury lens r1, ruling R1): a brute-force
 *      campaign against a LIVE voucher increments no per-voucher counter at all and is
 *      invisible here; remediation for a live voucher under attack is a freeze/hold
 *      edge, which does not exist yet, and is NOT a void.
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
        private readonly VoucherVoidService $voidService,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Retrieve the voucher that was freshly issued for a refund receipt.
     *
     * Used by the POS Presentation layer (ReceiptController::processReturn) to
     * surface the issued voucher in the API response so the printer can render
     * the dedicated voucher ticket without an extra round trip.
     *
     * @param  string  $receiptId  UUID of the return receipt whose issuance is being looked up.
     * @return Voucher|null The voucher issued for this receipt, or null if none was issued.
     */
    public function findBySourceReceiptId(string $receiptId): ?Voucher
    {
        return Voucher::where('source_receipt_id', $receiptId)->first();
    }

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
     * Fraud response for a voucher that has accumulated >= 5 failed lookup
     * attempts. Despite the name, NO REACHABLE INPUT REACHES THE VOID.
     *
     * The only caller is handleVoucherFailedAttempt(), reached only from
     * lookupInSession() :156-158, which fires precisely when the voucher's
     * status is NOT Issued/PartiallyRedeemed. Every remaining status
     * (FullyRedeemed, Expired, Voided) is terminal for the void edge, so
     * VoucherVoidService refuses (FullyRedeemed/Expired) or idempotently
     * short-circuits (Voided) on 100% of live traffic. Both branches below are
     * therefore alert-only in practice:
     *   - redemptions present            → log + alert, no void attempted;
     *   - void edge closed for the status → log + alert, void refused.
     *
     * The call is kept as a fail-closed backstop against a future caller that
     * does feed active vouchers in; it must NOT be read as live protection.
     * Before lane Q-5 this same unreachable branch ran with no status guard and
     * appended a FRESH `voided` ledger row on every fraud trigger against an
     * already-terminal voucher — the double-void this lane closes (sweep
     * findings #22 / #24). Nothing that ever protected a voucher was removed.
     *
     * The fraud alert is dispatched in every branch: it is the security signal,
     * and it does not depend on whether a void was available as remediation.
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
        } else {
            try {
                $this->voidService->void(new VoucherVoidRequest(
                    voucherId: $voucher->id,
                    userId: $cashierId,
                    policyTrigger: 'auto_fraud_void',
                    reason: null,
                    receiptId: null,
                    terminalId: $terminalId,
                    tenantId: $voucher->tenant_id,
                    companyId: $voucher->company_id,
                ));
            } catch (VoucherInvalidStatusException $e) {
                Log::error('Voucher fraud-auto-void refused: voucher is terminal for the void edge.', [
                    'voucher_id' => $voucher->id,
                    'status' => $voucher->status->value,
                    'attempts' => $attempts,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        $this->events->dispatch(new VoucherFraudAlert(
            voucherId: $voucher->id,
            tenantId: $voucher->tenant_id,
            companyId: $voucher->company_id,
            terminalId: $terminalId,
            cashierId: $cashierId,
            attemptsCount: $attempts,
            occurredAt: Carbon::now()->toIso8601String(),
        ));
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
