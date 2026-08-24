<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Services;

use App\Modules\POS\Domain\Receipt;
use App\Modules\Voucher\Application\DTOs\VoucherVoidRequest;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Exceptions\VoucherCascadeBlockedException;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        private readonly VoucherVoidService $voidService,
    ) {}

    /**
     * Execute the cascade check/void when a credit note is voided.
     *
     * @throws VoucherCascadeBlockedException if any voucher from this credit note has been redeemed
     */
    public function onCreditNoteVoided(Receipt $creditNote): void
    {
        // orderBy('id') is a LOCK-ORDER guard, not cosmetics (Session B lane Q-5
        // micro-round, fiscal lens F-6): VoucherVoidService takes a FOR UPDATE
        // row lock per voucher inside the loop below, so two cascades touching
        // an overlapping voucher set must acquire those locks in the same
        // sequence or they can deadlock. Unordered, the sequence is whatever the
        // planner returns.
        /** @var Collection<int, Voucher> $vouchers */
        $vouchers = Voucher::where('source_receipt_id', $creditNote->id)
            ->orderBy('id')
            ->get();

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
     * Void a single unredeemed voucher through the canonical void write path.
     *
     * Must be called within a DB transaction. VoucherVoidService takes its own
     * row lock, re-checks the status + redemption guards under it, posts the GL
     * reversal and dispatches VoucherVoided (lane Q-5) — this method only
     * supplies the credit note's provenance.
     */
    private function voidSingleVoucher(Voucher $voucher, Receipt $creditNote, Carbon $now): void
    {
        $this->voidService->void(new VoucherVoidRequest(
            voucherId: $voucher->id,
            userId: $creditNote->voided_by ?? $creditNote->cashier_id,
            policyTrigger: 'cascade_credit_note_void',
            reason: null,
            receiptId: $creditNote->id,
            terminalId: $creditNote->terminal_id,
            tenantId: $voucher->tenant_id,
            companyId: $voucher->company_id,
            occurredAt: $now,
        ));
    }
}
