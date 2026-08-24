<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Voucher\Application\DTOs\VoucherVoidRequest;
use App\Modules\Voucher\Application\DTOs\VoucherVoidResult;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Events\VoucherVoided;
use App\Modules\Voucher\Domain\Exceptions\VoucherInvalidStatusException;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * VoucherVoidService — the SINGLE write path for the voucher void edge.
 *
 * Session B lane Q-5, sweep findings #22 (HIGH) and #24 (MEDIUM). Before this
 * service there were three void writers with three different contracts:
 *
 *   - VoucherCascadeService     — posted a GL reversal unconditionally
 *   - VoucherLookupService      — posted a GL reversal when balance > 0
 *   - VoucherController::void() — posted NOTHING ('gl_journal_entry_id' => null,
 *                                hardcoded), extinguishing a real liability with
 *                                no journal entry, no status precondition, no row
 *                                lock and no idempotency
 *
 * The contract is now stated once, here:
 *
 * 1. DOCUMENT-PER-ACTION. A void extinguishes an outstanding voucher liability.
 *    Whenever `current_balance > 0` (bccomp at scale 5) the void posts the
 *    mirror-reversal of the issuance entry through `GeneralLedgerService::
 *    createVoucherLedgerEntry()`, and the appended `voucher_ledger` row carries
 *    its `gl_journal_entry_id`. A zero balance has nothing to reverse, so it
 *    appends the audit row and posts no GL entry.
 *
 * 2. STATE MACHINE. Two guards run in series, and the SECOND one dominates:
 *    the status allow-list (`VOIDABLE_STATUSES` = Issued + PartiallyRedeemed)
 *    followed by the redemption guard (no `Redeemed` ledger row). Because every
 *    redemption — partial included — writes `VoucherEvent::Redeemed`, a
 *    `PartiallyRedeemed` voucher can never clear the redemption guard. The
 *    EFFECTIVE voidable set is therefore `{Issued}`; see the VOIDABLE_STATUSES
 *    docblock for why the constant still names two states. `FullyRedeemed` and
 *    `Expired` refuse with a typed `VoucherInvalidStatusException`
 *    (`VOUCHER_NOT_VOIDABLE`); `PartiallyRedeemed` refuses with the same
 *    exception type carrying `VOUCHER_HAS_REDEMPTIONS`; an already-`Voided`
 *    voucher is an idempotent no-op (see 4).
 *
 * 3. CONCURRENCY. The voucher is (re-)loaded INSIDE the transaction with
 *    `lockForUpdate()`, and both the status and the redemption guard are
 *    evaluated under that lock against fresh column values — mirroring
 *    `VoucherRedemptionService::redeem()`. A redemption that committed before
 *    the lock was granted is therefore seen, and the void refuses instead of
 *    overwriting it from a stale snapshot.
 *
 * 4. IDEMPOTENCY. The idempotency key of a void is structural — a voucher has at
 *    most one `Voided` ledger row. A re-entered void (double-clicked button,
 *    redelivered job, repeated fraud trigger) short-circuits under the lock and
 *    returns `alreadyVoided`. The DB backstop is the partial unique index
 *    `uniq_voucher_ledger_voided_per_voucher` (migration
 *    2026_08_23_150000_unique_voucher_ledger_voided_per_voucher).
 *
 * Provenance (`policy_trigger`, `receipt_id`, `terminal_id`, `user_id`) is passed
 * through verbatim, so `manual_void`, `cascade_credit_note_void` and
 * `auto_fraud_void` rows stay distinguishable in the ledger.
 */
final class VoucherVoidService
{
    /**
     * The void edge's STATUS allow-list — not, on its own, the set of vouchers
     * this service will void.
     *
     * Read together with REDEMPTION_EVENTS below: `PartiallyRedeemed` passes this
     * allow-list and is then ALWAYS refused by the redemption guard, because
     * `VoucherRedemptionService` writes `VoucherEvent::Redeemed` for every
     * redemption, partial ones included (VoucherRedemptionService.php:191, :213),
     * and sets the `PartiallyRedeemed` STATUS in the same transaction (:280).
     * The like-named ledger event `VoucherEvent::PartiallyRedeemed` is persisted
     * by NO path: the offline device push accepts it as a payload kind but hands
     * the row to `VoucherRedemptionService::redeem()`, which writes `Redeemed`
     * (VoucherLedgerPushService.php:82, :161). A voucher therefore cannot hold
     * the `PartiallyRedeemed` status without a `Redeemed` ledger row.
     *
     * The EFFECTIVE voidable set is therefore `{Issued}`, and a
     * `PartiallyRedeemed` void refuses with `VOUCHER_HAS_REDEMPTIONS`, never with
     * `VOUCHER_NOT_VOIDABLE`. Pinned by
     * VoucherVoidServiceTest::test_void_of_partially_redeemed_voucher_is_refused_by_the_redemption_guard.
     *
     * The constant deliberately keeps both entries rather than narrowing to
     * `[Issued]` (Session B lane Q-5 micro-round, treasury F-1 / fiscal F-5):
     * narrowing would move the refusal from the redemption guard to the status
     * guard and silently change the operator-facing error code, while the guard
     * that actually protects the liability — the redemption guard — is the one
     * that must not be loosened. The honest statement of the effective set lives
     * here in prose; the code path stays as reviewed.
     *
     * Kept on the single write path rather than on VoucherStatus: the
     * state-machine slice that gives VoucherStatus a full adjacency map plus the
     * `vouchers_status_check` DB constraint owns that hoist, and lands separately.
     *
     * @var list<VoucherStatus>
     */
    private const VOIDABLE_STATUSES = [
        VoucherStatus::Issued,
        VoucherStatus::PartiallyRedeemed,
    ];

    /**
     * Events whose presence means value has already left the voucher, so the
     * liability must not be reversed a second time by a void.
     *
     * @var list<VoucherEvent>
     */
    private const REDEMPTION_EVENTS = [
        VoucherEvent::Redeemed,
        VoucherEvent::PartiallyRedeemed,
    ];

    public function __construct(
        private readonly GeneralLedgerService $generalLedger,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Void a voucher: append the Voided ledger row, post the GL reversal when
     * there is a balance to reverse, zero the projection, dispatch VoucherVoided.
     *
     * @throws VoucherInvalidStatusException when the voucher is out of scope, is
     *                                       terminal for the void edge, or has redemptions
     */
    public function void(VoucherVoidRequest $request): VoucherVoidResult
    {
        return DB::transaction(function () use ($request): VoucherVoidResult {
            $voucher = $this->lockVoucher($request);

            // Idempotent re-entry: the void already happened. No second ledger
            // row, no second GL entry, no second domain event.
            if ($voucher->status === VoucherStatus::Voided) {
                return new VoucherVoidResult(
                    voucher: $voucher,
                    ledgerRow: null,
                    glJournalEntryId: null,
                    alreadyVoided: true,
                );
            }

            if (! in_array($voucher->status, self::VOIDABLE_STATUSES, true)) {
                throw VoucherInvalidStatusException::notVoidable($voucher->code, $voucher->status);
            }

            // Re-checked UNDER the lock: a redemption that committed while this
            // caller was deciding is visible here. This guard — not the status
            // allow-list above — is what closes the void edge for every
            // PartiallyRedeemed voucher (see VOIDABLE_STATUSES).
            $hasRedemptions = VoucherLedger::query()
                ->where('voucher_id', $voucher->id)
                ->whereIn('event', array_map(
                    static fn (VoucherEvent $event): string => $event->value,
                    self::REDEMPTION_EVENTS
                ))
                ->exists();

            if ($hasRedemptions) {
                throw VoucherInvalidStatusException::hasRedemptions($voucher->code);
            }

            return $this->writeVoid($voucher, $request);
        });
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Load the voucher FOR UPDATE inside the caller's transaction.
     *
     * @throws VoucherInvalidStatusException when the id is not in the caller's scope
     */
    private function lockVoucher(VoucherVoidRequest $request): Voucher
    {
        $query = Voucher::query()->where('id', $request->voucherId);

        if ($request->tenantId !== null) {
            $query->where('tenant_id', $request->tenantId);
        }

        if ($request->companyId !== null) {
            $query->where('company_id', $request->companyId);
        }

        /** @var Voucher|null $voucher */
        $voucher = $query->lockForUpdate()->first();

        if ($voucher === null) {
            throw VoucherInvalidStatusException::notFound($request->voucherId);
        }

        return $voucher;
    }

    /**
     * Append the Voided ledger row (GL entry first so gl_journal_entry_id is
     * never written null and never UPDATEd — voucher_ledger is append-only and
     * PostgreSQL rejects UPDATE on it), then collapse the voucher projection.
     */
    private function writeVoid(Voucher $voucher, VoucherVoidRequest $request): VoucherVoidResult
    {
        $now = $request->occurredAt ?? Carbon::now();

        /** @var numeric-string $voidedBalance */
        $voidedBalance = $voucher->current_balance;

        // precision-ok: 5 is the storage scale of vouchers.current_balance and
        // voucher_ledger.amount (both decimal(20,5)) — the voucher domain's fixed
        // internal scale, not a currency presentation scale. Comparing and negating
        // the stored balance at its own column scale is exact for every currency,
        // and is the scale the two pre-existing void paths already used.
        $reversesBalance = bccomp($voidedBalance, '0', 5) > 0; // precision-ok: see note above.

        /** @var numeric-string $voidedAmount */
        $voidedAmount = $reversesBalance
            ? bcmul($voidedBalance, '-1', 5) // precision-ok: see note above.
            : '0.00000';

        $voidedId = (string) Str::uuid();
        $glEntryId = null;

        if ($reversesBalance) {
            $unsavedVoided = new VoucherLedger;
            $unsavedVoided->id = $voidedId;
            $unsavedVoided->tenant_id = $voucher->tenant_id;
            $unsavedVoided->company_id = $voucher->company_id;
            $unsavedVoided->voucher_id = $voucher->id;
            $unsavedVoided->event = VoucherEvent::Voided;
            $unsavedVoided->amount = $voidedAmount;
            $unsavedVoided->currency = $voucher->currency;
            $unsavedVoided->receipt_id = $request->receiptId;
            $unsavedVoided->terminal_id = $request->terminalId;
            $unsavedVoided->user_id = $request->userId;
            $unsavedVoided->gl_journal_entry_id = null;
            $unsavedVoided->authorized_by_user_id = null;
            $unsavedVoided->policy_trigger = $request->policyTrigger;
            $unsavedVoided->reverses_voucher_ledger_id = null;
            $unsavedVoided->occurred_at = $now;

            $glEntryId = $this->generalLedger->createVoucherLedgerEntry($unsavedVoided, $voucher)->id;
        }

        /** @var VoucherLedger $ledgerRow */
        $ledgerRow = VoucherLedger::forceCreate([
            'id' => $voidedId,
            'tenant_id' => $voucher->tenant_id,
            'company_id' => $voucher->company_id,
            'voucher_id' => $voucher->id,
            'event' => VoucherEvent::Voided,
            'amount' => $voidedAmount,
            'currency' => $voucher->currency,
            'receipt_id' => $request->receiptId,
            'terminal_id' => $request->terminalId,
            'user_id' => $request->userId,
            'gl_journal_entry_id' => $glEntryId,
            'authorized_by_user_id' => null,
            'policy_trigger' => $request->policyTrigger,
            'reverses_voucher_ledger_id' => null,
            'occurred_at' => $now,
        ]);

        if ($request->reason !== null) {
            $noteEntry = '[VOID '.$now->toDateString().'] '.$request->reason;
            $voucher->notes = $voucher->notes !== null
                ? $voucher->notes."\n".$noteEntry
                : $noteEntry;
            $voucher->override_reason = $request->reason;
        }

        $voucher->status = VoucherStatus::Voided;
        $voucher->current_balance = '0.00000';
        $voucher->save();

        $this->events->dispatch(new VoucherVoided(
            voucherId: $voucher->id,
            tenantId: $voucher->tenant_id,
            companyId: $voucher->company_id,
            code: $voucher->code,
            voidedBalance: $voidedBalance,
            voidReason: $request->policyTrigger,
            glJournalEntryId: $glEntryId ?? '',
            occurredAt: $now->toIso8601String(),
        ));

        return new VoucherVoidResult(
            voucher: $voucher,
            ledgerRow: $ledgerRow,
            glJournalEntryId: $glEntryId,
            alreadyVoided: false,
        );
    }
}
