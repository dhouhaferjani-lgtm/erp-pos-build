{{-- Line Items Table Component --}}
<table class="items-table">
    <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th style="width: 40%;">{{ __('Description') }}</th>
            <th class="center" style="width: 10%;">{{ __('Qty') }}</th>
            <th class="right" style="width: 15%;">{{ __('Unit Price') }}</th>
            @if($showTax ?? true)
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
                @if(config('features.documents.line_designation_override.enabled') && $line->notes)
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
            <td class="right">{{ $formatMoney($line->unit_price) }}</td>
            @if($showTax ?? true)
            <td class="center">{{ $formatNumber($line->tax_rate, 0) }}%</td>
            @endif
            <td class="right">{{ $formatMoney($line->line_total) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
