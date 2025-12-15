{{-- Document Header Component --}}
<div class="header">
    <div class="header-left">
        @if($company->logo_path)
            <img src="{{ storage_path('app/public/' . $company->logo_path) }}" alt="{{ $company->name }}" class="company-logo">
        @endif
        <div class="company-name">{{ $company->legal_name ?? $company->name }}</div>
        <div class="company-details">
            @if($company->address_street)
                {{ $company->address_street }}<br>
            @endif
            @if($company->address_street_2)
                {{ $company->address_street_2 }}<br>
            @endif
            @if($company->address_city || $company->address_postal_code)
                {{ $company->address_postal_code }} {{ $company->address_city }}<br>
            @endif
            @if($company->address_state)
                {{ $company->address_state }}<br>
            @endif
            @if($company->phone)
                {{ __('Tel') }}: {{ $company->phone }}<br>
            @endif
            @if($company->email)
                {{ $company->email }}
            @endif
        </div>
    </div>
    <div class="header-right">
        <div class="document-title">{{ $documentTitle }}</div>
        <div class="document-number">{{ $document->document_number }}</div>
        <div class="document-date">{{ $formatDate($document->document_date) }}</div>
        @if($document->status)
            <div style="margin-top: 10px;">
                <span class="status-badge status-{{ $document->status->value }}">
                    {{ ucfirst(str_replace('_', ' ', $document->status->value)) }}
                </span>
            </div>
        @endif
    </div>
</div>
