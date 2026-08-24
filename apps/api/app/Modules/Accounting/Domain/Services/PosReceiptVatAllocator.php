<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Services;

use App\Modules\Accounting\Domain\DTOs\PosRevenueVatSplit;
use App\Modules\Accounting\Domain\DTOs\PosVatRateAllocation;
use App\Modules\Accounting\Domain\Exceptions\PosVatProjectionRefusedException;
use App\Modules\POS\Domain\Receipt;
use Illuminate\Support\Facades\DB;

/**
 * Turn a receipt's SEALED VAT breakdown into a per-tender-leg revenue/VAT split
 * (W4-9).
 *
 * **The sealed rows are the only source.** `pos_receipt_vat_details` is written
 * by `PosCoreReceiptProjection` straight from the device-signed
 * `payload.vat_breakdown[]`, in the same transaction as the `pos_receipts` row,
 * and it is what the VAT declaration reads. Recomputing VAT here from rates and
 * line items would create a SECOND authority for the same number — exactly the
 * shape of defect W4-9 was (books and filing disagreeing with nothing raising a
 * hand). This class only ever ADDS UP and SUBTRACTS what the device sealed.
 *
 * **Why a per-leg allocation exists at all.** The POS books one journal entry
 * per tender leg (a `payments` row, its `journal_entry_id`, and the repository
 * movement that links the same entry are 1:1:1). VAT is a receipt-level fact.
 * So a receipt paid 400 cash + 290 card has to spread the sealed VAT across the
 * two entries. It is a DISTRIBUTION of sealed totals, never a recomputation:
 *
 *   - each rate is apportioned by leg amount, TRUNCATED at the currency scale;
 *   - the whole truncation residual lands on ONE leg — the LARGEST (first on a
 *     tie), which is both deterministic across replays and guaranteed to be a
 *     leg that can absorb it;
 *   - so Σ(per-leg share) == the sealed per-rate VAT EXACTLY, for every rate.
 *
 * The single-tender case (the overwhelming majority) short-circuits to the
 * sealed numbers verbatim.
 *
 * **Net is derived by subtraction** (`leg − Σ leg VAT`), never as a share of
 * `subtotal`. Two independently-rounded allocations would not add back to the
 * tender; one does.
 *
 * **Rounding and tolerance are deliberately NOT this class's business.** On a
 * v3 receipt `payload.total` is the ROUNDED amount collected and the sale value
 * is `total − cash_rounding_adjustment`; the sealed VAT is on the sale value.
 * Feeding this allocator the RETAINED leg amounts therefore leaves revenue at
 * `net + adjustment`, and the separate §4.6 rounding entry moves exactly that
 * adjustment between revenue and 6580/7580 — landing revenue on `net`. The VAT
 * legs are untouched by rounding, which is correct: the State is owed VAT on
 * the sale, not on the till.
 *
 * No `CompanyContext` is consulted anywhere (rule 20): the caller passes the
 * currency scale explicitly because this runs on a Horizon worker.
 */
final class PosReceiptVatAllocator
{
    /**
     * @param  list<string>  $legAmounts  RETAINED amount per tender leg, index-aligned with the canonical
     *                                    `payments[]` order (a fully-netted leg is '0')
     * @return list<PosRevenueVatSplit> index-aligned with $legAmounts
     *
     * @throws PosVatProjectionRefusedException
     */
    public function allocate(Receipt $receipt, array $legAmounts, int $currencyScale): array
    {
        $receiptId = (string) $receipt->id;
        $sealed = $this->loadSealedRows($receiptId);
        $declaredVat = $this->declaredVat($receipt, $receiptId, $currencyScale);

        /** @var numeric-string $tenderTotal */
        $tenderTotal = bcadd('0', '0', $currencyScale);
        /** @var list<numeric-string> $amounts */
        $amounts = [];
        foreach ($legAmounts as $index => $amount) {
            if (! is_numeric($amount)) {
                throw PosVatProjectionRefusedException::nonNumericAmount(
                    $receiptId,
                    'leg_amount['.$index.']',
                    $amount,
                );
            }
            $amounts[] = $amount;
            $tenderTotal = bcadd($tenderTotal, $amount, $currencyScale);
        }

        // No sealed rows is only survivable when the receipt itself declares no
        // VAT — then there is genuinely nothing to split and the whole tender IS
        // revenue. If the receipt says VAT was collected, the ledger cannot post
        // it and must NOT fall back to booking the gross: that fallback is
        // precisely the W4-9 defect.
        //
        // Placed AFTER the numeric narrowing above so a malformed leg amount is
        // still reported as a malformed leg amount on a VAT-free receipt.
        if ($sealed === []) {
            if (bccomp($declaredVat, '0', $currencyScale) > 0) {
                throw PosVatProjectionRefusedException::missingSealedVatDetails($receiptId);
            }

            return array_map(
                static fn (string $amount): PosRevenueVatSplit => PosRevenueVatSplit::vatFree(
                    $amount,
                    $currencyScale,
                ),
                $amounts,
            );
        }

        /** @var numeric-string $vatTotal */
        $vatTotal = bcadd('0', '0', $currencyScale);
        foreach ($sealed as $row) {
            $vatTotal = bcadd($vatTotal, $row['vat_amount'], $currencyScale);
        }

        // Two authorities for one number: the sealed rows and the receipt's own
        // `tax_amount` (both written by the same projector from the same signed
        // payload). If they ever disagree, refuse rather than pick — a silent
        // pick is how a wrong VAT figure gets sealed into the chain.
        if (bccomp($vatTotal, $declaredVat, $currencyScale) !== 0) {
            throw PosVatProjectionRefusedException::sealedVatDisagreesWithReceipt(
                $receiptId,
                $vatTotal,
                $declaredVat,
            );
        }

        if (bccomp($vatTotal, $tenderTotal, $currencyScale) > 0) {
            throw PosVatProjectionRefusedException::vatExceedsTender($receiptId, $vatTotal, $tenderTotal);
        }

        $residualLeg = $this->residualLegIndex($amounts, $currencyScale);

        /** @var array<int, list<PosVatRateAllocation>> $perLeg */
        $perLeg = [];
        foreach (array_keys($amounts) as $index) {
            $perLeg[$index] = [];
        }

        foreach ($sealed as $row) {
            $shares = $this->apportion($row['vat_amount'], $amounts, $tenderTotal, $residualLeg, $currencyScale);
            foreach ($shares as $index => $share) {
                $perLeg[$index][] = new PosVatRateAllocation(
                    taxRate: $row['tax_rate'],
                    vatAmount: $share,
                    taxCategory: $row['tax_category'],
                );
            }
        }

        $splits = [];
        foreach ($amounts as $index => $amount) {
            /** @var numeric-string $legVat */
            $legVat = bcadd('0', '0', $currencyScale);
            foreach ($perLeg[$index] as $allocation) {
                $legVat = bcadd($legVat, $allocation->vatAmount, $currencyScale);
            }

            /** @var numeric-string $net */
            $net = bcsub($amount, $legVat, $currencyScale);
            if (bccomp($net, '0', $currencyScale) < 0) {
                throw PosVatProjectionRefusedException::vatExceedsTender($receiptId, $legVat, $amount);
            }

            $split = new PosRevenueVatSplit(
                tenderAmount: bcadd($amount, '0', $currencyScale),
                netRevenueAmount: $net,
                vatAllocations: $perLeg[$index],
                currencyScale: $currencyScale,
            );
            $split->assertReconciles($receiptId, bcadd($amount, '0', $currencyScale));

            $splits[] = $split;
        }

        return $splits;
    }

    /**
     * The VAT the receipt row itself declares, normalised to the currency scale.
     *
     * @return numeric-string
     */
    private function declaredVat(Receipt $receipt, string $receiptId, int $currencyScale): string
    {
        // `$receiptId` is carried for symmetry with the other refusal sites and
        // for the message a future non-numeric arm would need; the column is
        // `decimal(N,3)` and the model casts it, so there is no parse to fail.
        unset($receiptId);

        /** @var numeric-string $declared */
        $declared = (string) ($receipt->tax_amount ?? '0');

        return bcadd($declared, '0', $currencyScale);
    }

    /**
     * Read the sealed rows through the query builder rather than the `vatDetails`
     * relation: the bridge calls this inside its own transaction, after another
     * projector committed the rows, and a previously-hydrated relation on the
     * same `$receipt` instance would serve a stale (empty) collection.
     *
     * Ordered by `(tax_rate, id)` so replays and split-tender allocations are
     * byte-identical run to run.
     *
     * @return list<array{tax_rate: numeric-string, vat_amount: numeric-string, tax_category: ?string}>
     */
    private function loadSealedRows(string $receiptId): array
    {
        $rows = DB::table('pos_receipt_vat_details')
            ->where('receipt_id', $receiptId)
            ->orderBy('tax_rate')
            ->orderBy('id')
            ->get(['tax_rate', 'vat_amount', 'tax_category']);

        $sealed = [];
        foreach ($rows as $row) {
            $rate = (string) $row->tax_rate;
            $vat = (string) $row->vat_amount;
            if (! is_numeric($rate)) {
                throw PosVatProjectionRefusedException::nonNumericAmount($receiptId, 'tax_rate', $rate);
            }
            if (! is_numeric($vat)) {
                throw PosVatProjectionRefusedException::nonNumericAmount($receiptId, 'vat_amount', $vat);
            }
            $category = $row->tax_category;

            $sealed[] = [
                // Normalise the rate to 2 dp HERE, not at the line description.
                // `pos_receipt_vat_details.tax_rate` is `decimal(5,2)` but the
                // query builder hands back whatever the driver formats —
                // PostgreSQL returns '19.00', SQLite returns '19'. The rate is
                // the LABEL an accountant groups the 4457 lines by, so it must
                // read the same on both.
                'tax_rate' => bcadd($rate, '0', 2), // precision-ok: a VAT RATE is a percentage, not money — `tax_rate` is decimal(5,2) and rule 19 keeps percents off the currency scale.
                'vat_amount' => $vat,
                'tax_category' => is_string($category) ? $category : null,
            ];
        }

        return $sealed;
    }

    /**
     * The leg that absorbs every truncation residual: the LARGEST amount, first
     * index on a tie. Deterministic (a pure function of the sealed payload's leg
     * order and amounts, so a replay allocates identically) and always able to
     * carry the residual without pushing its own net negative.
     *
     * @param  list<numeric-string>  $legAmounts
     */
    private function residualLegIndex(array $legAmounts, int $currencyScale): int
    {
        $best = 0;
        foreach ($legAmounts as $index => $amount) {
            if (bccomp($amount, $legAmounts[$best], $currencyScale) > 0) {
                $best = $index;
            }
        }

        return $best;
    }

    /**
     * Split ONE sealed rate across the legs so the shares sum to it EXACTLY.
     *
     * @param  numeric-string  $rateVat
     * @param  list<numeric-string>  $legAmounts
     * @param  numeric-string  $tenderTotal
     * @return array<int, numeric-string> index-aligned with $legAmounts
     */
    private function apportion(
        string $rateVat,
        array $legAmounts,
        string $tenderTotal,
        int $residualLeg,
        int $currencyScale,
    ): array {
        $shares = [];

        // A zero tender total can only pair with a zero VAT total (the caller
        // already refused vat > tender), so every share is zero and the
        // proportional division — which would divide by zero — is skipped.
        if (bccomp($tenderTotal, '0', $currencyScale) === 0 || count($legAmounts) === 1) {
            foreach (array_keys($legAmounts) as $index) {
                $shares[$index] = bcadd('0', '0', $currencyScale);
            }
            if (count($legAmounts) === 1) {
                $shares[$residualLeg] = bcadd($rateVat, '0', $currencyScale);
            }

            return $shares;
        }

        /** @var numeric-string $assigned */
        $assigned = bcadd('0', '0', $currencyScale);
        foreach ($legAmounts as $index => $amount) {
            if ($index === $residualLeg) {
                continue;
            }
            // Truncating division at the currency scale: intermediates at
            // scale+4 per the precision contract, never a float.
            /** @var numeric-string $share */
            $share = bcdiv(bcmul($rateVat, $amount, $currencyScale + 4), $tenderTotal, $currencyScale);
            $shares[$index] = $share;
            $assigned = bcadd($assigned, $share, $currencyScale);
        }

        $shares[$residualLeg] = bcsub($rateVat, $assigned, $currencyScale);

        ksort($shares);

        return $shares;
    }
}
