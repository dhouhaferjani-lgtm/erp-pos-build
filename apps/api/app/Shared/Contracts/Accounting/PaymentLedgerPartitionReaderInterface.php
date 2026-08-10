<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Accounting;

/**
 * DPA `DPA-REV2-A` (A5) — read a payment's POSTED ledger footprint, split into
 * its receivable-backed and advance-backed halves.
 *
 * Module boundaries (rule 6): Treasury depends on THIS contract, never on
 * `PaymentLedgerPartitionReader` or on any Accounting model. It has three
 * consumers — the reversal cash branch, the instrument-cancellation ENTRY, and
 * the instrument-cancellation SHAPE selection — and they must not be allowed to
 * drift apart, which is why the predicate lives in exactly one class.
 */
interface PaymentLedgerPartitionReaderInterface
{
    /**
     * @param  string  $currency  the ENTITY currency — scale is resolved from it
     *                            explicitly (rule 19). Never a no-arg
     *                            `getScale()`: this runs in queued/console
     *                            contexts where no `CompanyContext` is bound.
     */
    public function read(string $companyId, string $paymentId, string $currency): PaymentLedgerPartition;
}
