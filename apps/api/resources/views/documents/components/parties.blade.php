{{-- Parties Information Component --}}
<div class="parties">
    {{-- From (Company) --}}
    <div class="party-box">
        <div class="party-title">{{ __('From') }}</div>
        <div class="party-name">{{ $company->legal_name ?? $company->name }}</div>
        <div class="party-details">
            @if($company->address_street)
                {{ $company->address_street }}<br>
            @endif
            @if($company->address_city || $company->address_postal_code)
                {{ $company->address_postal_code }} {{ $company->address_city }}<br>
            @endif
            @if($company->tax_id)
                {{ __('Tax ID') }}: {{ $company->tax_id }}<br>
            @endif
            @if($company->vat_number)
                {{ __('VAT') }}: {{ $company->vat_number }}
            @endif
        </div>
    </div>

    {{-- Spacer --}}
    <div style="display: table-cell; width: 4%;"></div>

    {{-- To (Partner) --}}
    <div class="party-box">
        <div class="party-title">{{ __('Bill To') }}</div>
        <div class="party-name">{{ $partner->name }}</div>
        <div class="party-details">
            @if($partner->address_street)
                {{ $partner->address_street }}<br>
            @endif
            @if($partner->address_city || $partner->address_postal_code)
                {{ $partner->address_postal_code }} {{ $partner->address_city }}<br>
            @endif
            @if($partner->tax_id)
                {{ __('Tax ID') }}: {{ $partner->tax_id }}<br>
            @endif
            @if($partner->email)
                {{ $partner->email }}
            @endif
        </div>
    </div>
</div>
