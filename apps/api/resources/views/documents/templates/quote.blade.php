@extends('documents.layouts.document')

@section('content')
    @include('documents.components.header')

    @include('documents.components.parties')

    @if($document->valid_until)
    <div style="margin-bottom: 20px; padding: 10px; background-color: #fef3c7; border-radius: 4px; font-size: 9pt;">
        <strong>{{ __('Valid Until') }}:</strong> {{ $formatDate($document->valid_until) }}
    </div>
    @endif

    @include('documents.components.line_items', [
        'showTax' => true,
        'lineDesignationOverrideEnabled' => (bool) ($company->line_designation_override_enabled ?? false),
    ])

    @include('documents.components.totals')

    @if($document->notes)
    <div class="notes-section">
        <div class="notes-title">{{ __('Terms & Conditions') }}</div>
        <div class="notes-content">{{ $document->notes }}</div>
    </div>
    @endif

    <div style="margin-top: 40px; padding: 15px; background-color: #f9fafb; border-radius: 4px;">
        <div style="font-size: 9pt; color: #666; margin-bottom: 10px;">{{ __('To accept this quotation, please sign below:') }}</div>
        <div style="display: table; width: 100%;">
            <div style="display: table-cell; width: 48%; padding-right: 4%;">
                <div style="border-bottom: 1px solid #333; height: 40px; margin-bottom: 5px;"></div>
                <div style="font-size: 8pt; color: #666;">{{ __('Customer Signature') }}</div>
            </div>
            <div style="display: table-cell; width: 48%;">
                <div style="border-bottom: 1px solid #333; height: 40px; margin-bottom: 5px;"></div>
                <div style="font-size: 8pt; color: #666;">{{ __('Date') }}</div>
            </div>
        </div>
    </div>
@endsection
