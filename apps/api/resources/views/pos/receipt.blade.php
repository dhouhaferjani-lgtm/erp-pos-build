<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('pos.receipt') }} - {{ $receipt->receipt_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans Mono', 'Courier New', monospace;
            font-size: 9pt;
            line-height: 1.3;
            color: #000;
            padding: 10px;
            max-width: 100%;
        }

        .receipt {
            max-width: 100%;
        }

        /* Header Section */
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 2px dashed #000;
            padding-bottom: 10px;
        }

        .company-logo {
            max-width: 80px;
            max-height: 40px;
            margin-bottom: 8px;
        }

        .company-name {
            font-size: 12pt;
            font-weight: bold;
            margin-bottom: 3px;
        }

        .company-details {
            font-size: 8pt;
            line-height: 1.2;
        }

        /* Receipt Info Section */
        .receipt-info {
            margin-bottom: 10px;
            font-size: 8pt;
            border-bottom: 1px dashed #000;
            padding-bottom: 8px;
        }

        .receipt-info div {
            margin-bottom: 2px;
        }

        .label {
            display: inline-block;
            width: 45%;
            font-weight: bold;
        }

        .value {
            display: inline-block;
            width: 53%;
            text-align: right;
        }

        /* Items Section */
        .items {
            margin-bottom: 10px;
            border-bottom: 1px dashed #000;
            padding-bottom: 8px;
        }

        .item {
            margin-bottom: 6px;
        }

        .item-header {
            font-size: 9pt;
            margin-bottom: 2px;
        }

        .item-details {
            font-size: 8pt;
            color: #333;
            display: flex;
            justify-content: space-between;
        }

        .item-qty-price {
            flex: 1;
        }

        .item-total {
            text-align: right;
            font-weight: bold;
        }

        /* Totals Section */
        .totals {
            margin-bottom: 10px;
            border-bottom: 2px dashed #000;
            padding-bottom: 8px;
        }

        .total-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
            font-size: 9pt;
        }

        .total-line.grand-total {
            font-size: 11pt;
            font-weight: bold;
            margin-top: 5px;
            padding-top: 5px;
            border-top: 1px solid #000;
        }

        /* VAT Breakdown */
        .vat-breakdown {
            margin-bottom: 10px;
            font-size: 8pt;
            border-bottom: 1px dashed #000;
            padding-bottom: 8px;
        }

        .vat-breakdown h4 {
            font-size: 9pt;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .vat-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }

        /* Payment Methods */
        .payments {
            margin-bottom: 10px;
            font-size: 9pt;
            border-bottom: 1px dashed #000;
            padding-bottom: 8px;
        }

        .payments h4 {
            font-size: 9pt;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .payment-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }

        .payment-detail {
            font-size: 8pt;
            color: #333;
            margin-left: 10px;
        }

        /* Fiscal Section */
        .fiscal {
            margin-bottom: 10px;
            font-size: 7pt;
            background-color: #f5f5f5;
            padding: 6px;
            border: 1px solid #ccc;
        }

        .fiscal h4 {
            font-size: 8pt;
            font-weight: bold;
            margin-bottom: 3px;
        }

        .fiscal-line {
            margin-bottom: 2px;
            word-break: break-all;
        }

        /* Footer */
        .footer {
            text-align: center;
            font-size: 8pt;
            margin-top: 10px;
        }

        .thank-you {
            font-size: 10pt;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .custom-footer {
            margin-top: 8px;
            white-space: pre-wrap;
        }

        /* Utility Classes */
        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .bold {
            font-weight: bold;
        }

        .divider {
            border-top: 1px dashed #000;
            margin: 10px 0;
        }

        /* Return Banner */
        .return-banner {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            padding: 8px 0;
            margin-bottom: 10px;
            border: 2px dashed #000;
            letter-spacing: 2px;
        }

        .return-details {
            text-align: center;
            font-size: 8pt;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px dashed #000;
        }

        .return-details div {
            margin-bottom: 2px;
        }

        /* Duplicate Stamp */
        .duplicate-stamp {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            padding: 8px 0;
            margin-bottom: 10px;
            border: 3px double #000;
            letter-spacing: 3px;
            text-transform: uppercase;
        }

        .duplicate-notice {
            text-align: center;
            font-size: 8pt;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px dashed #000;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="receipt">
        {{-- Duplicate Stamp (for reprinted copies) --}}
        @if($isDuplicate ?? false)
            <div class="duplicate-stamp">{{ $duplicateLabel }}</div>
            <div class="duplicate-notice">{{ __('pos.duplicate_notice') }}</div>
        @endif

        {{-- Header Section --}}
        <div class="header">
            @if($company->logo_path && $company->receipt_logo ?? false)
                <img src="{{ Storage::url($company->logo_path) }}" alt="{{ $company->name }}" class="company-logo">
            @endif
            <div class="company-name">{{ $company->name }}</div>
            <div class="company-details">
                @if($company->address_street)
                    {{ $company->address_street }}<br>
                @endif
                @if($company->address_city)
                    {{ $company->address_postal_code }} {{ $company->address_city }}<br>
                @endif
                @if($company->tax_id)
                    {{ __('pos.tax_id') }}: {{ $company->tax_id }}<br>
                @endif
                @if($company->phone)
                    {{ __('pos.tel') }}: {{ $company->phone }}<br>
                @endif
            </div>
            @if(!empty($location->receipt_header))
                <div class="custom-footer" style="margin-top: 8px;">{{ $location->receipt_header }}</div>
            @elseif(!empty($company->receipt_header))
                <div class="custom-footer" style="margin-top: 8px;">{{ $company->receipt_header }}</div>
            @endif
        </div>

        {{-- Return Banner (for return receipts only) --}}
        @if($isReturn ?? false)
            <div class="return-banner">{{ __('pos.return_receipt') }}</div>
            <div class="return-details">
                @if($originalReceiptNumber)
                    <div><strong>{{ __('pos.original_receipt') }}:</strong> {{ $originalReceiptNumber }}</div>
                @endif
                @if($returnReason)
                    <div><strong>{{ __('pos.return_reason') }}:</strong> {{ $returnReason }}</div>
                @endif
            </div>
        @endif

        {{-- Receipt Info Section --}}
        <div class="receipt-info">
            <div>
                <span class="label">{{ __('pos.receipt_no') }}:</span>
                <span class="value">{{ $receipt->receipt_number }}</span>
            </div>
            <div>
                <span class="label">{{ __('pos.date_time') }}:</span>
                <span class="value">{{ $formatDateTime($receipt->posted_at) }}</span>
            </div>
            <div>
                <span class="label">{{ __('pos.terminal') }}:</span>
                <span class="value">{{ $terminal->code }}</span>
            </div>
            <div>
                <span class="label">{{ __('pos.cashier') }}:</span>
                <span class="value">{{ $receipt->cashier_name }}</span>
            </div>
            @if($receipt->customer_name)
                <div>
                    <span class="label">{{ __('pos.customer') }}:</span>
                    <span class="value">{{ $receipt->customer_name }}</span>
                </div>
            @endif
        </div>

        {{-- Items Section --}}
        <div class="items">
            @foreach($lines as $line)
                <div class="item">
                    <div class="item-header">{{ $line->product_name }}</div>
                    <div class="item-details">
                        <span class="item-qty-price">
                            {{ $formatNumber($line->quantity, 0) }} x {{ $formatMoney($line->unit_price) }}
                            @if($line->hasDiscount())
                                <br>{{ __('pos.discount') }}: -{{ $formatMoney($line->discount_amount) }}
                            @endif
                        </span>
                        <span class="item-total">{{ $formatMoney($line->line_total) }}</span>
                    </div>
                    @if(!empty($line->combo_components))
                        <div style="font-size: 7pt; color: #555; margin-top: 2px; padding-left: 10px;">
                            @foreach($line->combo_components as $componentName)
                                &bull; {{ $componentName }}<br>
                            @endforeach
                        </div>
                    @endif
                    @if($line->notes)
                        <div style="font-size: 7pt; color: #666; margin-top: 2px;">{{ $line->notes }}</div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Totals Section --}}
        <div class="totals">
            <div class="total-line">
                <span>{{ __('pos.subtotal') }}:</span>
                <span>{{ $formatMoney($receipt->subtotal) }}</span>
            </div>
            <div class="total-line">
                <span>{{ __('pos.tax') }}:</span>
                <span>{{ $formatMoney($receipt->tax_amount) }}</span>
            </div>
            <div class="total-line grand-total">
                <span>{{ __('pos.total') }}:</span>
                <span>{{ $formatMoney($receipt->total) }}</span>
            </div>
        </div>

        {{-- VAT Breakdown --}}
        @if($vatDetails->isNotEmpty())
            <div class="vat-breakdown">
                <h4>{{ __('pos.vat_breakdown') }}</h4>
                @foreach($vatDetails as $vat)
                    <div class="vat-line">
                        <span>{{ __('pos.vat') }} {{ $formatNumber($vat->tax_rate, 2) }}%:</span>
                        <span>{{ $formatMoney($vat->vat_amount) }} ({{ __('pos.base') }}: {{ $formatMoney($vat->net_amount) }})</span>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Payment Methods --}}
        <div class="payments">
            <h4>{{ __('pos.payment_methods') }}</h4>
            @foreach($payments as $payment)
                <div>
                    <div class="payment-line">
                        <span>{{ $payment->payment_type }}:</span>
                        <span>{{ $formatMoney($payment->amount) }}</span>
                    </div>
                    @if($payment->isCard())
                        <div class="payment-detail">{{ __('pos.card') }}: {{ $payment->getMaskedCardNumber() }}</div>
                    @endif
                    @if($payment->isVoucher())
                        <div class="payment-detail">{{ __('pos.voucher') }}: {{ $payment->voucher_serial }}</div>
                    @endif
                </div>
            @endforeach
            @if((float)$changeGiven > 0)
                <div class="divider"></div>
                <div class="payment-line bold">
                    <span>{{ __('pos.change_given') }}:</span>
                    <span>{{ $formatMoney($changeGiven) }}</span>
                </div>
            @endif
        </div>

        {{-- Fiscal Section (CRITICAL for compliance) --}}
        <div class="fiscal">
            <h4>{{ __('pos.fiscal_information') }}</h4>
            <div class="fiscal-line">
                <strong>{{ __('pos.chain_sequence') }}:</strong> #{{ $receipt->chain_sequence }}
            </div>
            <div class="fiscal-line">
                <strong>{{ __('pos.fiscal_hash') }}:</strong><br>
                {{ substr($receipt->fiscal_hash, 0, 32) }}<br>
                {{ substr($receipt->fiscal_hash, 32) }}
            </div>
            @if(!$receipt->isFirstInChain())
                <div class="fiscal-line">
                    <strong>{{ __('pos.previous_hash') }}:</strong><br>
                    {{ substr($receipt->previous_hash, 0, 32) }}<br>
                    {{ substr($receipt->previous_hash, 32) }}
                </div>
            @endif
        </div>

        {{-- Footer Section --}}
        <div class="footer">
            <div class="thank-you">{{ __('pos.thank_you') }}</div>
            @if($company->receipt_footer ?? null)
                <div class="custom-footer">{{ $company->receipt_footer }}</div>
            @endif
            <div style="margin-top: 8px; font-size: 7pt;">
                {{ __('pos.powered_by') }}
            </div>
        </div>
    </div>
</body>
</html>
