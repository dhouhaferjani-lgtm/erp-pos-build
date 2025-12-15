{{-- Payment Information Component --}}
@if($document->due_date || ($paymentTerms ?? null))
<div class="payment-info">
    <div class="payment-title">{{ __('Payment Information') }}</div>
    <div class="payment-details">
        @if($document->due_date)
            <strong>{{ __('Due Date') }}:</strong> {{ $formatDate($document->due_date) }}<br>
        @endif
        @if($paymentTerms ?? null)
            <strong>{{ __('Payment Terms') }}:</strong> {{ $paymentTerms }}<br>
        @endif
        @if($company->bank_name ?? null)
            <br>
            <strong>{{ __('Bank Details') }}:</strong><br>
            {{ $company->bank_name }}<br>
            {{ __('IBAN') }}: {{ $company->bank_iban ?? 'N/A' }}<br>
            {{ __('BIC') }}: {{ $company->bank_bic ?? 'N/A' }}
        @endif
    </div>
</div>
@endif
