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
--}}
@php
    /** @var \App\Modules\Document\Domain\Document $document */
    $isFiscalType = in_array(
        $document->type,
        \App\Modules\Document\Domain\Services\DocumentPostingService::getFiscalDocumentTypes(),
        true,
    );
    $isPosted = in_array(
        $document->status,
        [
            \App\Modules\Document\Domain\Enums\DocumentStatus::Posted,
            \App\Modules\Document\Domain\Enums\DocumentStatus::Paid,
        ],
        true,
    );
@endphp

@if($isFiscalType && ! $isPosted)
<div class="posting-marker">
    <strong>{{ __('documents.posting_marker.title') }}</strong>
    <span>{{ __('documents.posting_marker.detail') }}</span>
</div>
@endif
