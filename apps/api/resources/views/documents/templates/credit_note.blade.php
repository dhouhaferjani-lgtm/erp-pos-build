@extends('documents.layouts.document')

@section('content')
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
        'showTax' => true,
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
            </table>
        </div>
    </div>

    <div style="margin-top: 30px; padding: 15px; background-color: #fee2e2; border: 1px solid #fecaca; border-radius: 4px; font-size: 9pt; color: #991b1b;">
        <strong>{{ __('Note') }}:</strong> {{ __('This credit note reduces your balance by the amount shown above.') }}
    </div>
@endsection
