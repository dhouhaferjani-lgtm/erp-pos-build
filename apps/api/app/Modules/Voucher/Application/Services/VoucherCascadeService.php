<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\POS\Domain\Receipt;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Events\VoucherVoided;
use App\Modules\Voucher\Domain\Exceptions\VoucherCascadeBlockedException;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * VoucherCascadeService — Phase 1 cascade logic when a credit note is voided (spec §3.1, §4.9).
 *
 * Rules:
 *   - No voucher issued from the credit note → no-op.
 *   - Vouchers exist, none redeemed → void all vouchers in one DB transaction.
 *   - Any voucher has been redeemed → throw VoucherCascadeBlockedException (hard block).
 *
 * Phase 1.1 will replace the hard-block with an automated corrective debit-note flow.
 * See docs/runbooks/voucher-redeemed-credit-note-correction.md for the manual accounting runbook.
 */
final class VoucherCascadeService
{
    public function __construct(
        private readonly GeneralLedgerService $generalLedger,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Execute the cascade check/void when a credit note is voided.
     *
     * @throws VoucherCascadeBlockedException if any voucher from this credit note has been redeemed
     */
    public function onCreditNoteVoided(Receipt $creditNote): void
    {
        /** @var Collection<int, Voucher> $vouchers */
        $vouchers = Voucher::where('source_receipt_id', $creditNote->id)->get();

        // No vouchers linked to this credit note → no-op
        if ($vouchers->isEmpty()) {
            return;
        }

        // Phase 1 hard-block: if any voucher has been redeemed, throw immediately
        $redeemedVoucherIds = $this->findRedeemedVoucherIds($vouchers);
        if ($redeemedVoucherIds !== []) {
            throw new VoucherCascadeBlockedException(
                creditNoteId: $creditNote->id,
                redeemedVoucherIds: $redeemedVoucherIds,
            );
        }

        // No redemptions found — void all vouchers in a single transaction
        DB::transaction(function () use ($vouchers, $creditNote): void {
            $now = Carbon::now();

            foreach ($vouchers as $voucher) {
                $this->voidSingleVoucher($voucher, $creditNote, $now);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Find the IDs of vouchers that have at least one Redeemed ledger event.
     *
     * @param  Collection<int, Voucher>  $vouchers
     * @return list<string>
     */
    private function findRedeemedVoucherIds(Collection $vouchers): array
    {
        $voucherIds = $vouchers->pluck('id')->all();

        /** @var list<string> $redeemed */
        $redeemed = VoucherLedger::whereIn('voucher_id', $voucherIds)
            ->where('event', VoucherEvent::Redeemed->value)
            ->distinct()
            ->pluck('voucher_id')
            ->all();

        return $redeemed;
    }

    /**
     * Void a single unredeemed voucher: write ledger row, reverse GL, dispatch event.
     *
     * Must be called within a DB transaction.
     */
    private function voidSingleVoucher(Voucher $voucher, Receipt $creditNote, Carbon $now): void
    {
        $voidedBalance = $voucher->current_balance;

        // Build unsaved Voided ledger row; create GL entry first; then INSERT with id set.
        $voidedId = (string) Str::uuid();
        $voidedAmount = bccomp($voidedBalance, '0', 5) > 0
            ? bcmul($voidedBalance, '-1', 5)
            : '0.00000';
        $userId = $creditNote->voided_by ?? $creditNote->cashier_id;

        $unsavedVoided = new VoucherLedger;
        $unsavedVoided->id = $voidedId;
        $unsavedVoided->tenant_id = $voucher->tenant_id;
        $unsavedVoided->company_id = $voucher->company_id;
        $unsavedVoided->voucher_id = $voucher->id;
        $unsavedVoided->event = VoucherEvent::Voided;
        $unsavedVoided->amount = $voidedAmount;
        $unsavedVoided->currency = $voucher->currency;
        $unsavedVoided->receipt_id = $creditNote->id;
        $unsavedVoided->terminal_id = $creditNote->terminal_id;
        $unsavedVoided->user_id = $userId;
        $unsavedVoided->gl_journal_entry_id = null;
        $unsavedVoided->authorized_by_user_id = null;
        $unsavedVoided->policy_trigger = 'cascade_credit_note_void';
        $unsavedVoided->reverses_voucher_ledger_id = null;
        $unsavedVoided->occurred_at = $now;

        // Write GL reversal entry (mirror of issuance)
        $glEntry = $this->generalLedger->createVoucherLedgerEntry($unsavedVoided, $voucher);

        // INSERT the VoucherLedger row with gl_journal_entry_id already populated (no UPDATE).
        /** @var VoucherLedger $ledgerRow */
        $ledgerRow = VoucherLedger::forceCreate([
            'id' => $voidedId,
            'tenant_id' => $voucher->tenant_id,
            'company_id' => $voucher->company_id,
            'voucher_id' => $voucher->id,
            'event' => VoucherEvent::Voided,
            'amount' => $voidedAmount,
            'currency' => $voucher->currency,
            'receipt_id' => $creditNote->id,
            'terminal_id' => $creditNote->terminal_id,
            'user_id' => $userId,
            'gl_journal_entry_id' => $glEntry->id,
            'authorized_by_user_id' => null,
            'policy_trigger' => 'cascade_credit_note_void',
            'reverses_voucher_ledger_id' => null,
            'occurred_at' => $now,
        ]);

        // Update voucher status and zero balance
        $voucher->status = VoucherStatus::Voided;
        $voucher->current_balance = '0.00000';
        $voucher->save();

        // Dispatch domain event
        $this->events->dispatch(new VoucherVoided(
            voucherId: $voucher->id,
            tenantId: $voucher->tenant_id,
            companyId: $voucher->company_id,
            code: $voucher->code,
            voidedBalance: $voidedBalance,
            voidReason: 'cascade_credit_note_void',
            glJournalEntryId: $glEntry->id,
            occurredAt: $now->toIso8601String(),
        ));
    }
}
