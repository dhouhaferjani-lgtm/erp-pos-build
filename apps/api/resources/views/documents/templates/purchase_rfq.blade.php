@extends('documents.layouts.document')

@section('content')
    @include('documents.components.header')

    <div class="parties">
        <div class="party-box">
            <div class="party-title">{{ __('Requested By') }}</div>
            <div class="party-name">{{ $company->legal_name ?? $company->name }}</div>
        </div>

        <div style="display: table-cell; width: 4%;"></div>

        <div class="party-box">
            <div class="party-title">{{ __('Supplier') }}</div>
            <div class="party-name">{{ $partner->name }}</div>
        </div>
    </div>

    @include('documents.components.line_items', ['showTax' => false])

    @if($document->notes)
    <div class="notes-section">
        <div class="notes-title">{{ __('Notes') }}</div>
        <div class="notes-content">{{ $document->notes }}</div>
    </div>
    @endif
@endsection
