<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\POS\Application\DTOs\VoucherLedgerPushPayload;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Voucher\Application\DTOs\VoucherRedemptionRequest;
use App\Modules\Voucher\Application\Services\VoucherRedemptionService;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Exceptions\VoucherDuplicateInTransactionException;
use App\Modules\Voucher\Domain\Exceptions\VoucherExpiredException;
use App\Modules\Voucher\Domain\Exceptions\VoucherInsufficientBalanceException;
use App\Modules\Voucher\Domain\Exceptions\VoucherInvalidStatusException;
use App\Modules\Voucher\Domain\Exceptions\VoucherNotForThisCustomerException;
use App\Modules\Voucher\Domain\Exceptions\VoucherNotForThisTerminalException;
use App\Modules\Voucher\Domain\Voucher;
use App\Shared\Domain\CurrencyScale;

/**
 * Phase 1 redemption-only push handler for the offline-first POS sync pipeline.
 *
 * Accepts ledger entries written locally during a Tauri redemption flow and
 * routes them through VoucherRedemptionService::redeem() so the GL journal
 * entry is server-authored. Issuance, voiding, and other event kinds are
 * server-initiated and not accepted via this push path — they fail with
 * reason `event_kind_not_supported_in_offline_path`.
 *
 * B5-fix audit (Option B, 2026-05-01) — STATUS: receipt-tied redemptions
 * (the offline POS cashier path) NO LONGER flow through this push handler.
 * They are now ingested server-side directly by `ReceiptSyncService` during
 * `syncBatch()`, which invokes `VoucherRedemptionService::redeem` inside
 * the same DB::transaction as the receipt write — atomic, no partial state.
 * That sidesteps the `receipt_id_required_for_redemption` rejection below
 * because the canonical receipt exists by the time redemption fires.
 *
 * This handler is reserved for FUTURE offline-issued voucher operations
 * not tied to a synced receipt — e.g. goodwill issuance from a back-office
 * screen, or refund-issuance flows that complete asynchronously. Phase 1
 * has no such flow live yet.
 *
 * Idempotency is enforced by the redemption service via the natural
 * (voucher_id, receipt_id, event=Redeemed) tuple: a replay raises
 * VoucherDuplicateInTransactionException, which this handler maps to
 * status="duplicate" and a successful (one-row-only) outcome.
 *
 * Idempotency contract (B5-fix audit Minor 3, 2026-05-01): the
 * client-supplied `id` is NOT persisted to voucher_ledger.id on the
 * canonical projection. The redemption service generates a server-
 * controlled UUID for the new row; the client `id` round-trips back in
 * the response payload as a correlation key so the client can match the
 * per-entry result back to its local pending row, but it does NOT
 * dedupe replays — that's the (voucher, receipt) tuple's job.
 *
 * Cross-terminal pushes are rejected with reason `voucher_not_for_this_terminal`.
 * Unknown vouchers are rejected with reason `voucher_not_found`.
 * Rows with null receipt_id are rejected with `receipt_id_required_for_redemption`
 * (the server cannot anchor the GL leg without a receipt).
 */
final class VoucherLedgerPushService
{
    public function __construct(
        private readonly VoucherRedemptionService $redemptionService,
    ) {}

    /**
     * Process one pushed ledger entry. Returns a result dict with one of:
     *   - status: 'synced'    — newly inserted on the server
     *   - status: 'duplicate' — already present (idempotent replay)
     *   - status: 'failed'    — rejected with a machine-readable reason
     *
     * @return array{id: string, status: string, error: string|null}
     */
    public function push(VoucherLedgerPushPayload $payload, string $requestingTerminalId): array
    {
        // 1. Phase 1 event-kind scope: only redemption events are accepted.
        if (! in_array(
            $payload->event,
            [VoucherEvent::Redeemed, VoucherEvent::PartiallyRedeemed],
            true
        )) {
            return $this->failed($payload->id, 'event_kind_not_supported_in_offline_path');
        }

        // 2. Load the voucher scoped to the requesting terminal's tenant.
        // api.pos-stabilization.027 — defense-in-depth: derive tenant from
        // the requesting terminal and pin the SELECT predicate. The downstream
        // redeemable_at_terminal_id guard at step 3 already catches the
        // cross-tenant case (a tenant-A push of a tenant-B voucher whose
        // redeemable_at_terminal_id is a tenant-B terminal will fail there),
        // but pinning tenant_id on the find also surfaces the leak as
        // voucher_not_found instead of voucher_not_for_this_terminal — a
        // tighter signal that aligns with the Treasury invariant for any
        // service-tier Eloquent find reachable from an HTTP path.
        /** @var Terminal|null $terminal */
        $terminal = Terminal::query()
            ->where('id', $requestingTerminalId)
            ->first();
        if ($terminal === null) {
            return $this->failed($payload->id, 'voucher_not_found');
        }

        /** @var Voucher|null $voucher */
        $voucher = Voucher::query()
            ->where('tenant_id', $terminal->tenant_id)
            ->find($payload->voucherId);

        if ($voucher === null) {
            return $this->failed($payload->id, 'voucher_not_found');
        }

        // 3. Single-terminal guard at the boundary: the requesting terminal
        //    MUST be the voucher's redeemable_at_terminal_id. This protects
        //    against cross-terminal pushes where the voucher belongs to a
        //    sibling terminal under the same tenant.
        if ($voucher->redeemable_at_terminal_id !== $requestingTerminalId) {
            return $this->failed($payload->id, 'voucher_not_for_this_terminal');
        }

        // 4. receipt_id is required for redemption events — the GL leg ties
        //    back to the sale receipt. Reject without one.
        if ($payload->receiptId === null) {
            return $this->failed($payload->id, 'receipt_id_required_for_redemption');
        }

        // 5. Hand off to VoucherRedemptionService. The amount on the wire is
        //    a SIGNED value at internal precision (negative = debit voucher
        //    liability). The redemption service expects an UNSIGNED amount
        //    at currency scale, so we adapt.
        $unsignedInternalAmount = ltrim($payload->amount, '-');
        $currencyScale = CurrencyScale::for($payload->currency);
        $appliedAmountAtCurrencyScale = CurrencyScale::bcformat(
            $unsignedInternalAmount,
            $currencyScale,
        );

        $request = new VoucherRedemptionRequest(
            voucherCode: $voucher->code,
            appliedAmount: $appliedAmountAtCurrencyScale,
            currency: $payload->currency,
            receiptId: $payload->receiptId,
            cashierId: $payload->userId,
            terminalId: $requestingTerminalId,
            partnerId: null,
            authorizedByUserId: null,
            policyTrigger: null,
        );

        try {
            $this->redemptionService->redeem($request);
        } catch (VoucherDuplicateInTransactionException) {
            // Phase 1 retry semantics: a replay of (voucher, receipt) is benign.
            return [
                'id' => $payload->id,
                'status' => 'duplicate',
                'error' => null,
            ];
        } catch (VoucherInvalidStatusException) {
            return $this->failed($payload->id, 'voucher_invalid_status');
        } catch (VoucherExpiredException) {
            return $this->failed($payload->id, 'voucher_expired');
        } catch (VoucherNotForThisTerminalException) {
            return $this->failed($payload->id, 'voucher_not_for_this_terminal');
        } catch (VoucherInsufficientBalanceException) {
            return $this->failed($payload->id, 'voucher_insufficient_balance');
        } catch (VoucherNotForThisCustomerException) {
            return $this->failed($payload->id, 'voucher_not_for_this_customer');
        }

        return [
            'id' => $payload->id,
            'status' => 'synced',
            'error' => null,
        ];
    }

    /**
     * @return array{id: string, status: string, error: string|null}
     */
    private function failed(string $id, string $reason): array
    {
        return [
            'id' => $id,
            'status' => 'failed',
            'error' => $reason,
        ];
    }
}
