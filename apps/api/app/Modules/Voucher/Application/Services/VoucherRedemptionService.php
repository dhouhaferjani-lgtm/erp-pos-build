<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\POS\Domain\Exceptions\GiftCardNotYetSupportedException;
use App\Modules\POS\Domain\Exceptions\RestaurantVoucherNotYetSupportedException;
use App\Modules\Voucher\Application\DTOs\VoucherRedemptionRequest;
use App\Modules\Voucher\Application\DTOs\VoucherRedemptionResult;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Events\VoucherFullyRedeemed;
use App\Modules\Voucher\Domain\Events\VoucherPartiallyRedeemed;
use App\Modules\Voucher\Domain\Exceptions\VoucherDuplicateInTransactionException;
use App\Modules\Voucher\Domain\Exceptions\VoucherExpiredException;
use App\Modules\Voucher\Domain\Exceptions\VoucherInsufficientBalanceException;
use App\Modules\Voucher\Domain\Exceptions\VoucherInvalidStatusException;
use App\Modules\Voucher\Domain\Exceptions\VoucherNotForThisCustomerException;
use App\Modules\Voucher\Domain\Exceptions\VoucherNotForThisTerminalException;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Voucher redemption — applies a voucher as a tender at POS sale time (Phase 1).
 *
 * Phase 1 scope:
 *   - Single-terminal: voucher.redeemable_at_terminal_id === request.terminalId
 *   - Partial redemption supported (Issued → PartiallyRedeemed or FullyRedeemed)
 *   - Sub-minor-unit residual triggers RoundingAdjustment + FullyRedeemed
 *   - CustomerBound mode: issued_to_partner_id must match request.partnerId
 *   - Duplicate-in-transaction guard: same voucher + same receipt → rejected
 *   - FOR UPDATE lock on the voucher row to serialize concurrent redemptions
 *
 * GL accounting (spec §5.2):
 *   Redeemed          → Dr VoucherLiability / Cr PosTenderClearing  (NO VAT lines)
 *   RoundingAdjustment → Dr VoucherLiability / Cr RoundingLossExpense (NO VAT lines)
 *
 * IMPORTANT: Voucher tender rows MUST bypass GeneralLedgerService::createPOSPaymentEntry()
 * in the sale receipt finalization path. That method credits ProductRevenue and would
 * double-count it. The bypass is wired in Tasks 22-25 (Phase E) / Task 53 (POS frontend).
 *
 * Currency precision (spec §5.5):
 *   Internal precision = currency_scale + 2 (e.g. 4 for EUR, 5 for TND).
 *   Display / redeem at currency scale (e.g. 2 for EUR, 3 for TND).
 *   Residual below min_currency_unit triggers a RoundingAdjustment event.
 */
final class VoucherRedemptionService
{
    public function __construct(
        private readonly GeneralLedgerService $generalLedger,
        private readonly Dispatcher $events,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Redeem (part of) a voucher against a sale receipt.
     *
     * @throws RestaurantVoucherNotYetSupportedException if request specifies restaurant_voucher instrument
     * @throws GiftCardNotYetSupportedException if request specifies gift_card instrument
     * @throws VoucherInvalidStatusException Voucher not in redeemable status, or currency mismatch
     * @throws VoucherExpiredException Voucher has passed its expires_at
     * @throws VoucherNotForThisTerminalException Single-terminal Phase 1 guard
     * @throws VoucherInsufficientBalanceException Requested amount exceeds current balance
     * @throws VoucherNotForThisCustomerException CustomerBound voucher, wrong partner
     * @throws VoucherDuplicateInTransactionException Same voucher applied twice on same receipt
     */
    public function redeem(VoucherRedemptionRequest $request): VoucherRedemptionResult
    {
        $this->guardInstrumentKind($request);

        return DB::transaction(function () use ($request): VoucherRedemptionResult {
            // 1. Normalise code to uppercase and look up the voucher with a FOR UPDATE lock.
            $code = strtoupper(trim($request->voucherCode));

            /** @var Voucher|null $voucher */
            $voucher = Voucher::query()
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if ($voucher === null) {
                throw new VoucherInvalidStatusException(
                    "Voucher '{$code}' does not exist."
                );
            }

            // 2. Validations — order matches the spec validation matrix.

            // 2a. Status: must be Issued or PartiallyRedeemed.
            if (! in_array($voucher->status, [VoucherStatus::Issued, VoucherStatus::PartiallyRedeemed], true)) {
                throw VoucherInvalidStatusException::invalidStatus($voucher->code, $voucher->status);
            }

            // 2b. Expiry.
            if ($voucher->expires_at !== null && $voucher->expires_at->isPast()) {
                throw new VoucherExpiredException($voucher->code, $voucher->expires_at->toIso8601String());
            }

            // 2c. Terminal (Phase 1 single-terminal guard).
            if ($voucher->redeemable_at_terminal_id !== $request->terminalId) {
                throw new VoucherNotForThisTerminalException(
                    $voucher->code,
                    $request->terminalId,
                    (string) $voucher->redeemable_at_terminal_id
                );
            }

            // 2d. Currency must match.
            if ($voucher->currency !== $request->currency) {
                throw VoucherInvalidStatusException::currencyMismatch(
                    $voucher->code,
                    $voucher->currency,
                    $request->currency
                );
            }

            // 2e. CustomerBound mode: partner must match.
            if ($voucher->redemption_mode === RedemptionMode::CustomerBound) {
                if ($request->partnerId === null || $request->partnerId !== $voucher->issued_to_partner_id) {
                    throw new VoucherNotForThisCustomerException(
                        $voucher->code,
                        (string) $request->partnerId,
                        (string) $voucher->issued_to_partner_id
                    );
                }
            }

            // 2f. Duplicate-in-transaction guard.
            $alreadyRedeemed = VoucherLedger::query()
                ->where('voucher_id', $voucher->id)
                ->where('event', VoucherEvent::Redeemed)
                ->where('receipt_id', $request->receiptId)
                ->exists();

            if ($alreadyRedeemed) {
                throw new VoucherDuplicateInTransactionException($voucher->code, $request->receiptId);
            }

            // 3. Compute scale values.
            $currencyScale = $this->scaleResolver->getScale($request->currency);
            $internalScale = $currencyScale + 2;
            $minCurrencyUnit = bcdiv('1', bcpow('10', (string) $currencyScale, $internalScale), $internalScale);

            // 4. Check sufficient balance at currency scale.
            /** @var numeric-string $currentBalance */
            $currentBalance = $voucher->current_balance;

            if (bccomp($request->appliedAmount, $currentBalance, $currencyScale) > 0) {
                throw new VoucherInsufficientBalanceException(
                    $voucher->code,
                    $request->appliedAmount,
                    $currentBalance
                );
            }

            // 5. Compute new balance at internal precision.
            /** @var numeric-string $appliedAtInternal */
            $appliedAtInternal = CurrencyScale::bcformat($request->appliedAmount, $internalScale);
            /** @var numeric-string $currentAtInternal */
            $currentAtInternal = CurrencyScale::bcformat($currentBalance, $internalScale);
            /** @var numeric-string $newBalance */
            $newBalance = bcsub($currentAtInternal, $appliedAtInternal, $internalScale);

            // 6. Determine whether a RoundingAdjustment is needed.
            $needsRounding = bccomp($newBalance, '0', $internalScale) > 0
                && bccomp($newBalance, $minCurrencyUnit, $internalScale) < 0;

            $now = Carbon::now();

            // 7. Append the redemption ledger row (event = Redeemed, amount = -appliedAmount).
            /** @var numeric-string $signedAmount */
            $signedAmount = bcmul($appliedAtInternal, '-1', $internalScale);

            // 8. Build unsaved Redeemed ledger row; create GL entry first; then INSERT with id set.
            $redemptionId = (string) Str::uuid();
            $unsavedRedemption = new VoucherLedger;
            $unsavedRedemption->id = $redemptionId;
            $unsavedRedemption->tenant_id = $voucher->tenant_id;
            $unsavedRedemption->company_id = $voucher->company_id;
            $unsavedRedemption->voucher_id = $voucher->id;
            $unsavedRedemption->event = VoucherEvent::Redeemed;
            $unsavedRedemption->amount = $signedAmount;
            $unsavedRedemption->currency = $request->currency;
            $unsavedRedemption->receipt_id = $request->receiptId;
            $unsavedRedemption->terminal_id = $request->terminalId;
            $unsavedRedemption->user_id = $request->cashierId;
            $unsavedRedemption->gl_journal_entry_id = null;
            $unsavedRedemption->authorized_by_user_id = $request->authorizedByUserId;
            $unsavedRedemption->policy_trigger = $request->policyTrigger;
            $unsavedRedemption->reverses_voucher_ledger_id = null;
            $unsavedRedemption->occurred_at = $now;

            // Create the GL JournalEntry: Dr VoucherLiability / Cr PosTenderClearing.
            $glEntry = $this->generalLedger->createVoucherLedgerEntry($unsavedRedemption, $voucher);

            // INSERT the VoucherLedger row with gl_journal_entry_id already populated (no UPDATE).
            /** @var VoucherLedger $redemptionEntry */
            $redemptionEntry = VoucherLedger::forceCreate([
                'id' => $redemptionId,
                'tenant_id' => $voucher->tenant_id,
                'company_id' => $voucher->company_id,
                'voucher_id' => $voucher->id,
                'event' => VoucherEvent::Redeemed,
                'amount' => $signedAmount,
                'currency' => $request->currency,
                'receipt_id' => $request->receiptId,
                'terminal_id' => $request->terminalId,
                'user_id' => $request->cashierId,
                'gl_journal_entry_id' => $glEntry->id,
                'authorized_by_user_id' => $request->authorizedByUserId,
                'policy_trigger' => $request->policyTrigger,
                'reverses_voucher_ledger_id' => null,
                'occurred_at' => $now,
            ]);

            // 9. Handle RoundingAdjustment when residual is below the minimum currency unit.
            $roundingEntry = null;
            if ($needsRounding) {
                /** @var numeric-string $residual */
                $residual = $newBalance;
                /** @var numeric-string $signedResidual */
                $signedResidual = bcmul($residual, '-1', $internalScale);

                // Build unsaved RoundingAdjustment row; create GL entry first; then INSERT.
                $roundingId = (string) Str::uuid();
                $unsavedRounding = new VoucherLedger;
                $unsavedRounding->id = $roundingId;
                $unsavedRounding->tenant_id = $voucher->tenant_id;
                $unsavedRounding->company_id = $voucher->company_id;
                $unsavedRounding->voucher_id = $voucher->id;
                $unsavedRounding->event = VoucherEvent::RoundingAdjustment;
                $unsavedRounding->amount = $signedResidual;
                $unsavedRounding->currency = $request->currency;
                $unsavedRounding->receipt_id = $request->receiptId;
                $unsavedRounding->terminal_id = $request->terminalId;
                $unsavedRounding->user_id = $request->cashierId;
                $unsavedRounding->gl_journal_entry_id = null;
                $unsavedRounding->authorized_by_user_id = null;
                $unsavedRounding->policy_trigger = null;
                $unsavedRounding->reverses_voucher_ledger_id = null;
                $unsavedRounding->occurred_at = $now;

                $roundingGlEntry = $this->generalLedger->createVoucherLedgerEntry($unsavedRounding, $voucher);

                /** @var VoucherLedger $roundingEntry */
                $roundingEntry = VoucherLedger::forceCreate([
                    'id' => $roundingId,
                    'tenant_id' => $voucher->tenant_id,
                    'company_id' => $voucher->company_id,
                    'voucher_id' => $voucher->id,
                    'event' => VoucherEvent::RoundingAdjustment,
                    'amount' => $signedResidual,
                    'currency' => $request->currency,
                    'receipt_id' => $request->receiptId,
                    'terminal_id' => $request->terminalId,
                    'user_id' => $request->cashierId,
                    'gl_journal_entry_id' => $roundingGlEntry->id,
                    'authorized_by_user_id' => null,
                    'policy_trigger' => null,
                    'reverses_voucher_ledger_id' => null,
                    'occurred_at' => $now,
                ]);

                // The residual is written off: balance becomes zero.
                $newBalance = CurrencyScale::bcformat('0', $internalScale);
            }

            // 11. Determine the new voucher status.
            $isFullyRedeemed = bccomp($newBalance, '0', $internalScale) === 0;
            $newStatus = $isFullyRedeemed ? VoucherStatus::FullyRedeemed : VoucherStatus::PartiallyRedeemed;

            // 12. Update the Voucher projection row.
            $voucher->update([
                'current_balance' => $newBalance,
                'status' => $newStatus,
            ]);

            $voucher->refresh();

            // 13. Dispatch domain event.
            if ($isFullyRedeemed) {
                $this->events->dispatch(new VoucherFullyRedeemed(
                    voucherId: $voucher->id,
                    tenantId: $voucher->tenant_id,
                    companyId: $voucher->company_id,
                    code: $voucher->code,
                    appliedAmount: $request->appliedAmount,
                    currency: $request->currency,
                    receiptId: $request->receiptId,
                    cashierId: $request->cashierId,
                    terminalId: $request->terminalId,
                    glJournalEntryId: $glEntry->id,
                    hadRoundingAdjustment: $needsRounding,
                    occurredAt: $now->toIso8601String(),
                ));
            } else {
                $this->events->dispatch(new VoucherPartiallyRedeemed(
                    voucherId: $voucher->id,
                    tenantId: $voucher->tenant_id,
                    companyId: $voucher->company_id,
                    code: $voucher->code,
                    appliedAmount: $request->appliedAmount,
                    newBalance: $newBalance,
                    currency: $request->currency,
                    receiptId: $request->receiptId,
                    cashierId: $request->cashierId,
                    terminalId: $request->terminalId,
                    glJournalEntryId: $glEntry->id,
                    occurredAt: $now->toIso8601String(),
                ));
            }

            return new VoucherRedemptionResult(
                voucher: $voucher,
                redemptionEntry: $redemptionEntry,
                roundingEntry: $roundingEntry,
                appliedAmount: $request->appliedAmount,
                newBalance: $newBalance,
                fullyRedeemed: $isFullyRedeemed,
            );
        });
    }

    // -------------------------------------------------------------------------
    // Private: guards
    // -------------------------------------------------------------------------

    /**
     * Reject Phase 1 unsupported instrument kinds.
     *
     * Only StoreVoucher and None instruments are wired in Phase 1. Restaurant-voucher
     * tender (ticket-restaurant) ships in Phase 2 via a dedicated RestaurantTicketTenderService
     * (spec §3.2.1). Gift-card support also ships in Phase 2.
     *
     * @throws RestaurantVoucherNotYetSupportedException
     * @throws GiftCardNotYetSupportedException
     */
    private function guardInstrumentKind(VoucherRedemptionRequest $request): void
    {
        match ($request->instrumentKind) {
            PaymentInstrumentKind::RestaurantVoucher => throw new RestaurantVoucherNotYetSupportedException,
            PaymentInstrumentKind::GiftCard => throw new GiftCardNotYetSupportedException,
            default => null,
        };
    }
}
