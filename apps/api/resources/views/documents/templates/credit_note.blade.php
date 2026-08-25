@extends('documents.layouts.document')

@section('content')
@php
    /*
     | C-F0 / SPEC §2.4 (F-95). `DocumentPdfService::prepareData()` always supplies
     | `isProforma` (pinned by ProformaTemplateCensusTest); the `?? true` is the
     | FAIL-SAFE default owner ruling OQ-14 asks for, and it applies to a direct
     | `view('documents.templates.…', …)` render that supplies no flag: an unknown
     | fiscal state prints as non-definitive rather than as an issued, VAT-bearing
     | document. @include hands this resolved value down to every component below.
     */
    $isProforma = $isProforma ?? true;
@endphp

    @include('documents.components.header')

    @include('documents.components.posting_marker')

    @include('documents.components.parties')

    @if($document->source_document_id && $document->sourceDocument)
    <div style="margin-bottom: 20px; padding: 10px; background-color: #fef3c7; border-radius: 4px; font-size: 9pt;">
        <strong>{{ __('Reference Invoice') }}:</strong> {{ $document->sourceDocument->document_number }}
        ({{ $formatDate($document->sourceDocument->document_date) }})
    </div>
    @endif

    @php
        $reason = $document->payload['credit_note_reason'] ?? null;
        $reasonLabels = [
            'return' => __('Goods Returned'),
            'price_adjustment' => __('Price Adjustment'),
            'discount' => __('Discount'),
            'cancellation' => __('Cancellation'),
            'other' => __('Other'),
        ];
    @endphp

    @if($reason)
    <div style="margin-bottom: 20px; font-size: 9pt;">
        <strong>{{ __('Reason') }}:</strong> {{ $reasonLabels[$reason] ?? $reason }}
    </div>
    @endif

    @include('documents.components.line_items', [
        'showTax' => ! $isProforma,
        'lineDesignationOverrideEnabled' => (bool) ($company->line_designation_override_enabled ?? false),
    ])

    <div class="totals-section">
        <div class="totals-notes">
            @if($document->notes)
            <div class="notes-section" style="background: transparent; padding: 0;">
                <div class="notes-title">{{ __('Notes') }}</div>
                <div class="notes-content">{{ $document->notes }}</div>
            </div>
            @endif
        </div>

        <div class="totals-box">
            <table class="totals-table">
                @if($isProforma)
                {{-- C-F0 / F-95 — gross line figures, no net line to subtract from,
                     and (gate r2 §3 / R-8) the duty and derived-discount rows that
                     make the box close over them. r2 hand-copied those rows from
                     `components/totals.blade.php` and said they "must stay in step"
                     while nothing made them and no test rendered them; fix round r3
                     (conventions F-C2 / fiscal F-12) made it one partial, included
                     here and there, and the R-8 tests now run over BOTH document
                     types. The credit note keeps its own totals BLOCK — its posted
                     arm says `Credit Total`, not `Total` — but not its own copy of
                     these rows. --}}
                @include('documents.components.proforma_totals_rows', ['totalRowStyle' => 'background-color: #dc2626;'])
                @else
                <tr>
                    <td>{{ __('Subtotal') }}</td>
                    <td>{{ $formatMoney($document->subtotal) }}</td>
                </tr>
                <tr>
                    <td>{{ __('Tax') }}</td>
                    <td>{{ $formatMoney($document->tax_amount) }}</td>
                </tr>
                <tr class="total-row" style="background-color: #dc2626;">
                    <td>{{ __('Credit Total') }}</td>
                    <td>{{ $formatMoney($document->total) }}</td>
                </tr>
                @endif
            </table>
        </div>
    </div>

    {{--
        Fix round r3 / conventions gate F-C3 — A PROFORMA DOES NOT REDUCE ANYTHING.

        This sentence was ungated and non-dotted. On a proforma it printed under a
        banner reading "Proforma — non-fiscal document … it confers no right of
        deduction", on a page from which this lane had deliberately deleted the Paid
        and Balance Due rows because a proforma is not a statement of account — and
        it asserted a balance effect the document does not have, using the
        definitive noun in the one place the lane took care not to. A French or
        Tunisian customer also read it in English, since it was a natural-English
        key and no `lang/*.json` file exists.

        The English value of `documents.credit_note.balance_note` is byte-identical
        to the old literal, so the posted credit-note snapshots do not move.
    --}}
    @if(! $isProforma)
    <div style="margin-top: 30px; padding: 15px; background-color: #fee2e2; border: 1px solid #fecaca; border-radius: 4px; font-size: 9pt; color: #991b1b;">
        <strong>{{ __('Note') }}:</strong> {{ __('documents.credit_note.balance_note') }}
    </div>
    @endif
@endsection
