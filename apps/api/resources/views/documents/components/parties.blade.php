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
            {{--
                C-F0 / F-95 — the fiscal identifiers come OFF a proforma. They are
                not decoration: a VAT registration number on a numbered document
                addressed to a customer is precisely what makes it look like — and
                function as — an issued invoice, whatever the total is labelled.
                They return the moment the document is sealed.
            --}}
            @php($sellerTaxIdDisplay = ($isProforma ?? false) ? null : (($sellerTaxId ?? null) ?? $company->tax_id))
            @php($sellerVatDisplay = ($isProforma ?? false) ? null : (($sellerVat ?? null) ?? $company->vat_number))
            @if($sellerTaxIdDisplay)
                {{ ($sellerTaxLabel ?? null) ?? __('Tax ID') }}: {{ $sellerTaxIdDisplay }}<br>
            @endif
            @if($sellerVatDisplay)
                {{ __('VAT') }}: {{ $sellerVatDisplay }}
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
            @if($partner->tax_id && ! ($isProforma ?? false))
                {{ __('Tax ID') }}: {{ $partner->tax_id }}<br>
            @endif
            @if($partner->email)
                {{ $partner->email }}
            @endif
        </div>
    </div>
</div>
