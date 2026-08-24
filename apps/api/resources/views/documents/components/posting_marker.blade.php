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
@endphp

@if($isFiscalType && $isVoided)
<div class="posting-marker posting-marker--void">
    <strong>{{ __('documents.posting_marker.cancelled_title') }}</strong>
    <span>{{ __('documents.posting_marker.cancelled_detail') }}</span>
</div>
@elseif($isFiscalType && ! $isSealed)
<div class="posting-marker">
    <strong>{{ __('documents.posting_marker.title') }}</strong>
    <span>{{ __('documents.posting_marker.detail') }}</span>
</div>
@endif
