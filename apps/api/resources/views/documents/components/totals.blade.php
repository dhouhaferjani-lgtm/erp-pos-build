{{-- Totals Section Component --}}
<div class="totals-section">
    <div class="totals-notes">
        @if($document->notes)
        <div class="notes-section" style="background: transparent; padding: 0;">
            <div class="notes-title">{{ __('Notes') }}</div>
            <div class="notes-content">{{ $document->notes }}</div>
        </div>
        @endif

        @if($vehicle ?? null)
        <div style="margin-top: 15px; padding: 10px; background-color: #f0f9ff; border-radius: 4px;">
            <div class="notes-title" style="color: #0369a1;">{{ __('Vehicle') }}</div>
            <div style="font-size: 9pt;">
                @if($vehicle->make || $vehicle->model)
                    <strong>{{ $vehicle->make }} {{ $vehicle->model }}</strong><br>
                @endif
                @if($vehicle->registration_number)
                    {{ __('Registration') }}: {{ $vehicle->registration_number }}<br>
                @endif
                @if($vehicle->vin)
                    {{ __('VIN') }}: {{ $vehicle->vin }}
                @endif
            </div>
        </div>
        @endif
    </div>

    <div class="totals-box">
        <table class="totals-table">
            @if($isProforma ?? false)
            {{--
                C-F0 / F-95, amended by fix round r1 / gate F-2 (SPEC §2.4 r11.2).

                ONE row, and it is the tax-inclusive figure, labelled as an estimate
                and never with a rate-basis word. The net subtotal is dropped along
                with the tax row: printing a net line and a gross line together is a
                VAT breakdown written as a subtraction, and a reader who can subtract
                has the tax amount back. Round 1 dropped it here and then let
                `line_items` supply the same net basis one table higher up — which is
                why the LINE figures are now tax-inclusive too, and why they sum to
                this row. Settlement rows (Paid / Balance Due) go as well: a proforma
                is not a statement of account.

                This row is the STORED `documents.total` and is never recomputed. A
                document carrying a document-level charge (the TN timbre in
                `stamp_duty_amount`) or a document-level discount therefore does not
                sum exactly from its lines — the residual is that charge or discount,
                never the VAT, so nothing about the invariant depends on it. Lane
                handback residual R-8.
            --}}
            <tr class="total-row">
                <td>{{ __('documents.proforma.estimated_total') }}</td>
                <td>{{ $formatMoney($document->total) }}</td>
            </tr>
            @else
            <tr>
                <td>{{ __('Subtotal') }}</td>
                <td>{{ $formatMoney($document->subtotal) }}</td>
            </tr>
            @if($document->discount_amount && floatval($document->discount_amount) > 0)
            <tr>
                <td>{{ __('Discount') }}</td>
                <td>-{{ $formatMoney($document->discount_amount) }}</td>
            </tr>
            @endif
            <tr>
                <td>{{ __('Tax') }}</td>
                <td>{{ $formatMoney($document->tax_amount) }}</td>
            </tr>
            <tr class="total-row">
                <td>{{ __('Total') }}</td>
                <td>{{ $formatMoney($document->total) }}</td>
            </tr>
            @if(isset($document->balance_due) && floatval($document->balance_due) < floatval($document->total))
            <tr>
                <td>{{ __('Paid') }}</td>
                <td>{{ $formatMoney(floatval($document->total) - floatval($document->balance_due)) }}</td>
            </tr>
            <tr class="balance-row">
                <td>{{ __('Balance Due') }}</td>
                <td>{{ $formatMoney($document->balance_due) }}</td>
            </tr>
            @endif
            @endif
        </table>
    </div>
</div>
