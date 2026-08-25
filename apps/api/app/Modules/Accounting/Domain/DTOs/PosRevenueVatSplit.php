<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\DTOs;

use App\Modules\Accounting\Domain\Exceptions\PosVatProjectionRefusedException;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;

/**
 * How ONE POS tender leg decomposes into net revenue + output VAT per rate
 * (W4-9).
 *
 * The POS books one journal entry per tender leg (`payments.journal_entry_id`
 * is 1:1 and the repository movement links the same entry), so the receipt-level
 * sealed VAT breakdown has to be expressed per leg. This DTO is that expression:
 *
 *     Dr  cash/card        tenderAmount
 *       Cr  70x            netRevenueAmount
 *       Cr  4457 @ rate    vatAllocations[i]->vatAmount   (one line per rate)
 *
 * INVARIANT (checked by {@see assertReconciles}): `netRevenueAmount + Σ vat`
 * equals `tenderAmount` EXACTLY at `currencyScale`. `netRevenueAmount` is
 * derived by subtraction, never by recomputing a net from a rate — that is what
 * makes the entry balance by construction instead of by luck.
 */
final readonly class PosRevenueVatSplit
{
    /**
     * @param  numeric-string  $tenderAmount
     * @param  numeric-string  $netRevenueAmount
     * @param  list<PosVatRateAllocation>  $vatAllocations  one entry per SEALED rate row, in sealed order,
     *                                                      including rates whose share is zero
     */
    public function __construct(
        public string $tenderAmount,
        public string $netRevenueAmount,
        public array $vatAllocations,
        public int $currencyScale,
        /**
         * This leg's share of `pos_receipts.discount_amount`, the TRANSACTION-level
         * discount (W4-9 gate r1 / F-4).
         *
         * The device seals `subtotal + vat_total == total + transaction_discount_amount`
         * — the VAT is computed on the PRE-discount base. So the taxable base the
         * DGI declaration reports (`vat_breakdown[].net_amount`) is the pre-discount
         * `subtotal`, and the ledger's revenue credit has to be that same figure or
         * the two disagree about the base while agreeing about the VAT. Booking the
         * discount as an explicit contra-revenue debit (`SalesDiscount`, 709) is
         * what keeps them equal — and it is exactly what the POS's own
         * ACCOUNT_CHARGE arm already does
         * ({@see GeneralLedgerService::createPOSChargeEntry}),
         * so the two POS arms book the same sale the same way.
         *
         * @var numeric-string
         */
        public string $discountAmount = '0',
        /**
         * True ONLY for a receipt the allocator has positively established
         * carries no VAT at all (no sealed rows AND `tax_amount` zero).
         *
         * It exists so `assertReconciles()` can keep treating an EMPTY
         * `$vatAllocations` as "someone handed me a split with the VAT missing"
         * — the W4-9 failure mode — while still letting a genuinely VAT-free
         * sale post. Silence and zero must not look alike here.
         */
        public bool $isVatFree = false,
    ) {}

    /**
     * The whole tender is revenue because the receipt carries no VAT — asserted
     * by the allocator, not assumed by a caller.
     *
     * @param  numeric-string  $tenderAmount
     */
    /**
     * @param  numeric-string  $tenderAmount
     * @param  numeric-string  $discountAmount
     */
    public static function vatFree(string $tenderAmount, int $currencyScale, string $discountAmount = '0'): self
    {
        $normalised = bcadd($tenderAmount, '0', $currencyScale);
        $discount = bcadd($discountAmount, '0', $currencyScale);

        return new self(
            tenderAmount: $normalised,
            netRevenueAmount: bcadd($normalised, $discount, $currencyScale),
            vatAllocations: [],
            currencyScale: $currencyScale,
            discountAmount: $discount,
            isVatFree: true,
        );
    }

    public function hasDiscount(): bool
    {
        return bccomp($this->discountAmount, '0', $this->currencyScale) > 0;
    }

    /** @return numeric-string */
    public function totalVat(): string
    {
        /** @var numeric-string $total */
        $total = bcadd('0', '0', $this->currencyScale);
        foreach ($this->vatAllocations as $allocation) {
            $total = bcadd($total, $allocation->vatAmount, $this->currencyScale);
        }

        return $total;
    }

    /**
     * @return list<PosVatRateAllocation> only the rates that actually carry money on this leg
     */
    public function nonZeroVatAllocations(): array
    {
        return array_values(array_filter(
            $this->vatAllocations,
            fn (PosVatRateAllocation $allocation): bool => bccomp($allocation->vatAmount, '0', $this->currencyScale) > 0,
        ));
    }

    public function hasNetRevenue(): bool
    {
        return bccomp($this->netRevenueAmount, '0', $this->currencyScale) > 0;
    }

    /**
     * Fail-closed preflight. Called by every GL writer BEFORE it creates a
     * single `journal_lines` row, so a split that cannot express the sale
     * refuses instead of writing a plausible-looking wrong entry.
     *
     * @param  numeric-string  $expectedTender  the amount the debit leg will carry
     *
     * @throws PosVatProjectionRefusedException
     */
    public function assertReconciles(string $receiptId, string $expectedTender): void
    {
        if ($this->vatAllocations === [] && ! $this->isVatFree) {
            throw PosVatProjectionRefusedException::missingSealedVatDetails($receiptId);
        }

        if (bccomp($expectedTender, $this->tenderAmount, $this->currencyScale) !== 0) {
            throw PosVatProjectionRefusedException::splitDoesNotReconcile(
                $receiptId,
                $this->netRevenueAmount,
                $this->totalVat(),
                $expectedTender,
            );
        }

        $recomposed = bcsub(
            bcadd($this->netRevenueAmount, $this->totalVat(), $this->currencyScale),
            $this->discountAmount,
            $this->currencyScale,
        );
        if (bccomp($recomposed, $this->tenderAmount, $this->currencyScale) !== 0) {
            throw PosVatProjectionRefusedException::splitDoesNotReconcile(
                $receiptId,
                $this->netRevenueAmount,
                $this->totalVat(),
                $this->tenderAmount,
            );
        }
    }
}
