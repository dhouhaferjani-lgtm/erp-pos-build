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
        <tr>
            <td>{{ $index + 1 }}</td>
            <td>
                @if($line->product)
                    <strong>{{ $line->product->name }}</strong>
                    @if($line->product->sku)
                        <span style="color: #888;">[{{ $line->product->sku }}]</span>
                    @endif
                @elseif($line->service)
                    <strong>{{ $line->service->name }}</strong>
                @else
                    <strong>{{ $line->product_name ?? 'Item' }}</strong>
                @endif
                @if($line->description)
                    <div class="item-description">{{ $line->description }}</div>
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
