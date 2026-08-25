{{--
    N-6 (owner ruling) — a CONFIRMED invoice may be printed, and it shows VAT
    normally, but the printout must SAY that it is not yet booked and carries no
    fiscal seal. Printing a numbered, VAT-bearing document that the ledger has
    never seen is exactly what Code TVA Art. 18 makes expensive (VAT mentioned on
    an issued invoice is owed by issuance), and the recipient has no other way to
    tell the two apart.

    The seal/hash/QR block is NOT rendered for such a document — and, today,
    `resources/views/documents/**` emits no seal block at all for ANY status
    (verified: no hash/QR/seal markup exists outside the POS receipt and Z-report
    views). This guard is written as an @if on posted-ness anyway, so the block a
    later lane adds inherits the gate instead of having to remember it.

    Posted reprints are unchanged: this renders nothing for them.

    COVERAGE CAVEAT (fiscal gate r1 F-5): `DocumentPdfService::resolveTemplate()`
    prefers `documents.country.{code}.{type}` over `documents.templates.{type}`.
    No country template exists today, so coverage is complete — but the FIRST
    country template added for an invoice or credit note must include this
    component, or it silently loses the marker.
--}}
@php
    /** @var \App\Modules\Document\Domain\Document $document */
    $isFiscalType = in_array(
        $document->type,
        \App\Modules\Document\Domain\Services\DocumentPostingService::getFiscalDocumentTypes(),
        true,
    );

    // FISCAL STATE, NOT LIFECYCLE (fix round r1, fiscal gate F-4).
    //
    // The first version gated on `status NOT IN (posted, paid)`. A CANCELLED
    // invoice keeps its `fiscal_hash` and carries `fiscal_status = VOIDED`, so it
    // fell into the "not yet posted" arm and its printout stated, in writing,
    // that a document which WAS posted, sealed and hash-chained "carries no
    // fiscal seal and is not a definitive fiscal invoice". A false fiscal
    // statement on the one output an auditor or a customer actually reads.
    //
    // The seal is the fact being reported, so the seal is what is asked. This is
    // also correct for every future status without another edit here.
    $isSealed = $document->fiscal_hash !== null;
    $isVoided = $document->fiscal_status === \App\Modules\Document\Domain\Enums\FiscalStatus::Voided
        || $document->status === \App\Modules\Document\Domain\Enums\DocumentStatus::Cancelled;

    // R2-F1 — CANCELLED IS TWO DIFFERENT FACTS, and only one of them involves a
    // seal. `RefundService::cancelInvoiceWithoutDecision()` writes
    // `status = Cancelled` straight onto a DRAFT or CONFIRMED invoice that was
    // never sealed (its `Posted` arm delegates to `DocumentPostingService::cancel()`
    // instead, and its `Paid` arm throws). A seal-agnostic branch therefore
    // printed "was posted and sealed … its fiscal seal remains in the hash
    // chain" about a document with `fiscal_hash = NULL` — F-4 with the sign
    // flipped, claiming a chain entry that does not exist. The FE sibling
    // (`CreditNoteDetail.tsx`) already words this without any seal claim; the
    // blade now matches it.
    //
    // R2-F2 — HISTORICAL OPENING BALANCES. `ArApOpeningService` creates opening
    // AR/AP invoices `status = Posted`, `fiscal_category = NON_FISCAL`,
    // `fiscal_status = DRAFT`, no hash: real migrated production data, and the
    // same rows `DocumentStatusService::wasNeverSealed()` exempts. "No fiscal
    // seal" is true of them, but "has not been posted to the accounts" is false
    // — they were posted, in the customer's PREVIOUS system, which is what
    // `is_historical` records. They get their own line rather than a lie or a
    // silence. `fiscal_category === NonFiscal` is carried alongside
    // `isHistorical()` so the arm also holds for the next non-fiscal row of a
    // fiscal TYPE, whatever writes it.
    $isHistorical = $document->isHistorical()
        || $document->fiscal_category === \App\Modules\Document\Domain\Enums\FiscalCategory::NonFiscal;
@endphp

@if($isFiscalType && $isVoided && $isSealed)
<div class="posting-marker posting-marker--void">
    <strong>{{ __('documents.posting_marker.cancelled_title') }}</strong>
    <span>{{ __('documents.posting_marker.cancelled_detail') }}</span>
</div>
@elseif($isFiscalType && $isVoided)
<div class="posting-marker posting-marker--void">
    <strong>{{ __('documents.posting_marker.cancelled_title') }}</strong>
    <span>{{ __('documents.posting_marker.cancelled_unsealed_detail') }}</span>
</div>
@elseif($isFiscalType && $isHistorical)
<div class="posting-marker">
    <strong>{{ __('documents.posting_marker.historical_title') }}</strong>
    <span>{{ __('documents.posting_marker.historical_detail') }}</span>
</div>
@elseif($isFiscalType && ! $isSealed)
{{--
    C-F0 / SPEC §2.4 (F-95) — THIS ARM IS NOW THE PROFORMA BANNER, and the rest of
    the page follows it: `$isProforma` (resolved by `ProformaOutputPolicy`, passed
    in by `DocumentPdfService::prepareData()`) is TRUE for exactly this arm, and it
    strips the VAT column, the tax rows and the fiscal identifiers from
    `line_items`, `totals`, `parties` and the layout footer.

    WHY THE MARKER WAS NOT ENOUGH. N-6 shipped a warning line printed NEXT TO a
    full VAT breakdown. Code TVA Art. 18 makes the VAT mentioned on an issued
    invoice payable by the act of issuing it, and the recipient can deduct from
    the paper regardless of what a sentence beside it says. The banner had to
    become the document.

    The three arms above are unchanged: a sealed-then-cancelled document, an
    unsealed cancelled one and a historical opening balance are all statements
    about a document that was issued, not estimates — see `ProformaOutputPolicy`
    for why each one is excluded there too.
--}}
<div class="posting-marker">
    <strong>{{ __('documents.proforma.title') }}</strong>
    <span>{{ __('documents.proforma.detail') }}</span>
</div>
@endif
