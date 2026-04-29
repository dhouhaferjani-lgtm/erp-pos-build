<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\POS\Application\DTOs\VoucherLedgerPushPayload;
use App\Modules\Voucher\Application\DTOs\VoucherRedemptionRequest;
use App\Modules\Voucher\Application\Services\VoucherRedemptionService;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use App\Shared\Domain\CurrencyScale;
use Throwable;

/**
 * Server-side dispatch for voucher_ledger entries pushed by an offline POS.
 *
 * Phase 1 scope rule: the Tauri client only writes ledger rows during a
 * REDEMPTION (issuance is server-only via VoucherIssuanceService). The
 * push handler enforces this: only `Redeemed` and `PartiallyRedeemed`
 * events are accepted; every other kind returns
 * `event_kind_not_supported_in_offline_path`.
 *
 * Why we don't INSERT directly:
 *   - voucher_ledger rows must carry a non-null `gl_journal_entry_id`
 *     (spec §5.2). The client cannot fabricate a JournalEntry.
 *   - VoucherRedemptionService creates the GL entry via
 *     GeneralLedgerService::createVoucherLedgerEntry() AND inserts the
 *     ledger row in one transaction. We delegate to it so the GL leg is
 *     server-authored and the ledger row matches the server's
 *     accounting model exactly.
 *   - This means the client-supplied `id` is used as the idempotency
 *     key, but the actual ledger row UUID is whatever the redemption
 *     service chose. Idempotency is preserved by recording the client
 *     id on the row's `policy_trigger` channel? — no. We dedupe on the
 *     client-supplied id BEFORE invoking the redemption service: if a
 *     row with that exact id already exists in voucher_ledger, the push
 *     is a duplicate.
 *
 * For the dedup rule to work on the second push, we make the client id
 * the row id. That requires a small write path: after the service runs,
 * we re-key the inserted row's id from the server-generated value back
 * to the client-supplied UUID. Doing that on `voucher_ledger` is a
 * problem because it is append-only (UPDATE blocked by PG trigger).
 *
 * Resolution: instead of re-keying, we record a side-channel mapping in
 * `voucher_ledger.policy_trigger` (which is a free-text trigger key,
 * spec §6) — but that's also fragile. The simplest correct approach:
 * use `VoucherLedger::query()->where('id', $payload->id)->exists()` for
 * dedupe AND inject the client-supplied UUID into the redemption
 * service via a thin adapter so the inserted row's id is exactly
 * $payload->id. The adapter is implemented directly in this class.
 *
 * The adapter performs the redemption inside a DB transaction:
 *   1. Lookup voucher by id; verify terminal scope.
 *   2. Defer to VoucherRedemptionService::redeem() — but redirect the
 *      generated row id to $payload->id afterwards is impossible
 *      (append-only). So instead the adapter relies on the natural
 *      uniqueness of the redemption request (voucher + receipt) as the
 *      idempotency window: if a Redeemed row already exists for
 *      (voucher_id, receipt_id), we treat the push as a duplicate.
 *
 * Note on idempotency: the "client id" is not preserved into
 * voucher_ledger.id (that field is server-controlled). We dedupe on the
 * tuple (voucher_id, receipt_id, event=Redeemed) which the redemption
 * service ALREADY enforces via VoucherDuplicateInTransactionException.
 * On a duplicate push we catch that exception and report `duplicate`.
 *
 * This means the client-supplied id is used as a CLIENT-side mirror key
 * only; the server cares about the (voucher, receipt) idempotency.
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

        // 2. Load the voucher; voucher_not_found is a hard failure.
        /** @var Voucher|null $voucher */
        $voucher = Voucher::query()->find($payload->voucherId);

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

        // 4. Idempotency dedup: client id already inserted? Treat as duplicate.
        //    (No-op no matter how many times the same push is replayed.)
        if ($this->isDuplicateById($payload->id)) {
            return [
                'id' => $payload->id,
                'status' => 'duplicate',
                'error' => null,
            ];
        }

        // 5. receipt_id is required for redemption events — the GL leg ties
        //    back to the sale receipt. Reject without one.
        if ($payload->receiptId === null) {
            return $this->failed($payload->id, 'receipt_id_required_for_redemption');
        }

        // 6. Hand off to VoucherRedemptionService. The amount on the wire is
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
        } catch (Throwable $e) {
            // Map duplicate-in-transaction to 'duplicate' (Phase 1 retry
            // semantics: the same (voucher, receipt) replay is benign).
            $shortName = (new \ReflectionClass($e))->getShortName();
            if ($shortName === 'VoucherDuplicateInTransactionException') {
                return [
                    'id' => $payload->id,
                    'status' => 'duplicate',
                    'error' => null,
                ];
            }

            return $this->failed(
                $payload->id,
                $this->mapExceptionToReason($e),
            );
        }

        return [
            'id' => $payload->id,
            'status' => 'synced',
            'error' => null,
        ];
    }

    /**
     * Idempotency check: has a voucher_ledger row with this client-supplied
     * id already been recorded?
     *
     * The redemption service generates its own UUID for the inserted row,
     * so this lookup will only ever match if a previous push wrote a row
     * keyed to this id directly (which we don't currently do — see class
     * docblock for the dedup-via-receipt-tuple alternative).
     *
     * Kept as a defence-in-depth check; cheap (PK lookup) and matches the
     * spec wording about "dedupe by client-supplied id".
     */
    private function isDuplicateById(string $id): bool
    {
        return VoucherLedger::query()->where('id', $id)->exists();
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

    private function mapExceptionToReason(Throwable $e): string
    {
        $shortName = (new \ReflectionClass($e))->getShortName();

        return match ($shortName) {
            'VoucherInvalidStatusException' => 'voucher_invalid_status',
            'VoucherExpiredException' => 'voucher_expired',
            'VoucherNotForThisTerminalException' => 'voucher_not_for_this_terminal',
            'VoucherInsufficientBalanceException' => 'voucher_insufficient_balance',
            'VoucherNotForThisCustomerException' => 'voucher_not_for_this_customer',
            default => 'redemption_failed',
        };
    }
}
