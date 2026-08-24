<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\DTOs;

use App\Modules\Accounting\Domain\Exceptions\PosVatProjectionRefusedException;

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
    public static function vatFree(string $tenderAmount, int $currencyScale): self
    {
        $normalised = bcadd($tenderAmount, '0', $currencyScale);

        return new self(
            tenderAmount: $normalised,
            netRevenueAmount: $normalised,
            vatAllocations: [],
            currencyScale: $currencyScale,
            isVatFree: true,
        );
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

        $recomposed = bcadd($this->netRevenueAmount, $this->totalVat(), $this->currencyScale);
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
