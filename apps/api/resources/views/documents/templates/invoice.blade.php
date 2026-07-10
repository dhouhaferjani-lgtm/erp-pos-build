@extends('documents.layouts.document')

@section('content')
    @include('documents.components.header')

    @include('documents.components.parties')

    @include('documents.components.line_items', [
        'showTax' => true,
        'lineDesignationOverrideEnabled' => (bool) ($company->line_designation_override_enabled ?? false),
    ])

    @include('documents.components.totals')

    @include('documents.components.payment_info')

    @if($document->notes)
    <div class="notes-section">
        <div class="notes-title">{{ __('Terms & Conditions') }}</div>
        <div class="notes-content">{{ $document->notes }}</div>
    </div>
    @endif
@endsection
