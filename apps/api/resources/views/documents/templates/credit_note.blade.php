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
                {{-- C-F0 / F-95 — see components/totals.blade.php: one estimated
                     gross row, no net line to subtract from. The credit note has
                     its own totals block rather than the shared component, which
                     is exactly how a gate gets missed; it is gated here. --}}
                <tr class="total-row" style="background-color: #dc2626;">
                    <td>{{ __('documents.proforma.estimated_total') }}</td>
                    <td>{{ $formatMoney($document->total) }}</td>
                </tr>
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

    <div style="margin-top: 30px; padding: 15px; background-color: #fee2e2; border: 1px solid #fecaca; border-radius: 4px; font-size: 9pt; color: #991b1b;">
        <strong>{{ __('Note') }}:</strong> {{ __('This credit note reduces your balance by the amount shown above.') }}
    </div>
@endsection
