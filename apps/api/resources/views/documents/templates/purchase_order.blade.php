@extends('documents.layouts.document')

@section('content')
    @include('documents.components.header')

    <div class="parties">
        {{-- From (Company) --}}
        <div class="party-box">
            <div class="party-title">{{ __('Ordered By') }}</div>
            <div class="party-name">{{ $company->legal_name ?? $company->name }}</div>
            <div class="party-details">
                @if($company->address_street)
                    {{ $company->address_street }}<br>
                @endif
                @if($company->address_city || $company->address_postal_code)
                    {{ $company->address_postal_code }} {{ $company->address_city }}<br>
                @endif
                @if($company->tax_id)
                    {{ __('Tax ID') }}: {{ $company->tax_id }}
                @endif
            </div>
        </div>

        <div style="display: table-cell; width: 4%;"></div>

        {{-- To (Supplier) --}}
        <div class="party-box">
            <div class="party-title">{{ __('Supplier') }}</div>
            <div class="party-name">{{ $partner->name }}</div>
            <div class="party-details">
                @if($partner->address_street)
                    {{ $partner->address_street }}<br>
                @endif
                @if($partner->address_city || $partner->address_postal_code)
                    {{ $partner->address_postal_code }} {{ $partner->address_city }}<br>
                @endif
                @if($partner->tax_id)
                    {{ __('Tax ID') }}: {{ $partner->tax_id }}
                @endif
            </div>
        </div>
    </div>

    @if($document->external_document_number)
    <div style="margin-bottom: 20px; padding: 10px; background-color: #f0f9ff; border-radius: 4px; font-size: 9pt;">
        <strong>{{ __('Supplier Invoice') }}:</strong> {{ $document->external_document_number }}
        @if($document->external_document_date)
            ({{ $formatDate($document->external_document_date) }})
        @endif
    </div>
    @endif

    @include('documents.components.line_items', ['showTax' => true])

    @include('documents.components.totals')

    @if($document->notes)
    <div class="notes-section">
        <div class="notes-title">{{ __('Delivery Instructions') }}</div>
        <div class="notes-content">{{ $document->notes }}</div>
    </div>
    @endif

    <div style="margin-top: 30px; font-size: 9pt; color: #666;">
        <strong>{{ __('Delivery Address') }}:</strong><br>
        {{ $company->legal_name ?? $company->name }}<br>
        @if($company->address_street)
            {{ $company->address_street }}<br>
        @endif
        {{ $company->address_postal_code }} {{ $company->address_city }}
    </div>
@endsection
