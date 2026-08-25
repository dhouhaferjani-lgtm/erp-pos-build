{{-- Line Items Table Component --}}
@php
    // C-F0 / F-95 — belt AND braces. Every caller of this component passes
    // `showTax`, but a rate column is exactly the kind of thing a new template
    // re-enables by copy-paste, so the component refuses it for a proforma on its
    // own authority as well. `$isProforma` reaches here through @include, which
    // hands the including view's `get_defined_vars()` down.
    $showTaxColumn = ($showTax ?? true) && ! ($isProforma ?? false);

    // FIX ROUND r1 / gate F-2 (SPEC §2.4 r11.2) — a proforma prints TAX-INCLUSIVE
    // line figures. Round 1 printed net amounts under a gross estimated total, and
    // the difference between the two was, to the millime, the VAT the lane had
    // removed: one subtraction undid the invariant. Gross lines sum to the
    // estimated total, and the net basis never appears, so the subtraction has
    // nothing to operate on. `ProformaGrossAmountResolver` owns the arithmetic
    // (bcmath, rounded once); the closures are absent only on a direct
    // `view('documents.components.line_items', …)` render, and the guard below
    // keeps that path on the net figures rather than fataling.
    $grossRow = ($isProforma ?? false)
        && isset($proformaUnitPrice, $proformaLineAmount);
@endphp
<table class="items-table">
    <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th style="width: 40%;">{{ __('Description') }}</th>
            <th class="center" style="width: 10%;">{{ __('Qty') }}</th>
            <th class="right" style="width: 15%;">{{ __('Unit Price') }}</th>
            @if($showTaxColumn)
            <th class="center" style="width: 10%;">{{ __('Tax') }}</th>
            @endif
            <th class="right" style="width: 20%;">{{ __('Amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($lines as $index => $line)
        @php
            $freeQuantity = (string) ($line->free_quantity ?? '0');
            $hasFreeQuantity = bccomp($freeQuantity, '0', 4) > 0;
            $physicalQuantity = $hasFreeQuantity
                ? bcadd((string) $line->quantity, $freeQuantity, 4)
                : (string) $line->quantity;
        @endphp
        <tr>
            <td>{{ $index + 1 }}</td>
            <td>
                <strong>{{ $line->description }}</strong>
                @if($line->product_code)
                    <span class="sku">[{{ $line->product_code }}]</span>
                @endif
                @if(($lineDesignationOverrideEnabled ?? false) && $line->notes)
                    <div class="item-description">{{ $line->notes }}</div>
                @endif
                @if($hasFreeQuantity)
                    <div class="item-description">
                        {{ __('documents.bonus_quantity.sub_row', ['quantity' => $formatNumber($freeQuantity, 2)]) }}
                    </div>
                    <div class="item-description">
                        {{ __('documents.bonus_quantity.line_total', ['quantity' => $formatNumber($physicalQuantity, 2)]) }}
                    </div>
                @endif
            </td>
            <td class="center">{{ $formatNumber($line->quantity, 2) }}</td>
            <td class="right">{{ $formatMoney($grossRow ? $proformaUnitPrice($line) : $line->unit_price) }}</td>
            @if($showTaxColumn)
            <td class="center">{{ $formatNumber($line->tax_rate, 0) }}%</td>
            @endif
            <td class="right">{{ $formatMoney($grossRow ? $proformaLineAmount($line) : $line->line_total) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
