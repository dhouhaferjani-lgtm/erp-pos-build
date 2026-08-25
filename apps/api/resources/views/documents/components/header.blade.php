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
        {{--
            C-F0 fix round r1 / gate F-1 — a PROFORMA STATES NO LIFECYCLE STATUS.

            The predicate admits four statuses, and two of them put a word on the
            page that contradicts the document. `Posted`-never-sealed printed
            `POSTED` (the badge is upper-cased into the PDF by
            `.status-badge { text-transform: uppercase }`) on the one output this
            lane certifies as non-definitive. `Paid`-never-sealed — the tenant-#1
            population `documents:repair-paid-never-posted` exists to move back to
            Confirmed — printed `PAID` on a page from which the Paid and Balance
            Due rows had just been deleted.

            Suppressing the word, not relabelling it, is the point: a lifecycle
            badge has nothing true to say about a document whose own banner states
            it has not been entered in the accounts. The badge returns, unchanged,
            the moment the document is sealed.
        --}}
        @if($document->status && ! ($isProforma ?? false))
            <div style="margin-top: 10px;">
                <span class="status-badge status-{{ $document->status->value }}">
                    {{ ucfirst(str_replace('_', ' ', $document->status->value)) }}
                </span>
            </div>
        @endif
    </div>
</div>
