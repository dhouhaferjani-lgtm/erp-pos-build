<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
            padding: 40px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
        }
        .logo {
            max-width: 180px;
            max-height: 60px;
        }
        .company-info {
            text-align: right;
            font-size: 11px;
            color: #666;
        }
        .invoice-title {
            font-size: 28px;
            font-weight: bold;
            color: #2563eb;
            margin-bottom: 5px;
        }
        .invoice-number {
            font-size: 14px;
            color: #666;
        }
        .addresses {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .address-block {
            width: 45%;
        }
        .address-label {
            font-weight: bold;
            color: #2563eb;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .address-content {
            font-size: 12px;
            line-height: 1.6;
        }
        .invoice-details {
            margin-bottom: 30px;
        }
        .invoice-details table {
            width: 50%;
            margin-left: auto;
        }
        .invoice-details td {
            padding: 5px 10px;
            font-size: 11px;
        }
        .invoice-details td:first-child {
            color: #666;
        }
        .invoice-details td:last-child {
            text-align: right;
            font-weight: 500;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .items-table th {
            background-color: #2563eb;
            color: white;
            padding: 12px 10px;
            text-align: left;
            font-weight: 500;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .items-table th:last-child,
        .items-table th:nth-child(3),
        .items-table th:nth-child(4) {
            text-align: right;
        }
        .items-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 11px;
        }
        .items-table td:last-child,
        .items-table td:nth-child(3),
        .items-table td:nth-child(4) {
            text-align: right;
        }
        .items-table .item-description {
            font-weight: 500;
        }
        .items-table .item-period {
            font-size: 10px;
            color: #666;
            margin-top: 3px;
        }
        .totals {
            width: 300px;
            margin-left: auto;
            margin-bottom: 30px;
        }
        .totals table {
            width: 100%;
        }
        .totals td {
            padding: 8px 10px;
            font-size: 11px;
        }
        .totals td:first-child {
            color: #666;
        }
        .totals td:last-child {
            text-align: right;
        }
        .totals .total-row {
            border-top: 2px solid #2563eb;
            font-weight: bold;
            font-size: 14px;
        }
        .totals .total-row td {
            color: #2563eb;
            padding-top: 12px;
        }
        .totals .due-row {
            background-color: #fef3c7;
        }
        .totals .due-row td {
            font-weight: bold;
            color: #d97706;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-paid {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
        .status-overdue {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .notes {
            margin-top: 30px;
            padding: 15px;
            background-color: #f9fafb;
            border-radius: 6px;
        }
        .notes-label {
            font-weight: bold;
            color: #374151;
            margin-bottom: 5px;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 10px;
            color: #666;
            text-align: center;
        }
        .payment-info {
            margin-top: 30px;
            padding: 15px;
            background-color: #eff6ff;
            border-radius: 6px;
            border-left: 4px solid #2563eb;
        }
        .payment-info-title {
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <table style="width: 100%; margin-bottom: 40px;">
        <tr>
            <td style="width: 50%;">
                @if($company['logo_path'])
                    <img src="{{ $company['logo_path'] }}" alt="{{ $company['name'] }}" class="logo">
                @else
                    <div style="font-size: 24px; font-weight: bold; color: #2563eb;">{{ $company['name'] }}</div>
                @endif
            </td>
            <td style="width: 50%; text-align: right;">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number">#{{ $invoice->number }}</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge status-{{ strtolower($invoice->status->value) }}">
                        {{ $invoice->status->label() }}
                    </span>
                </div>
            </td>
        </tr>
    </table>

    <!-- Addresses -->
    <table style="width: 100%; margin-bottom: 30px;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                <div class="address-label">From</div>
                <div class="address-content">
                    <strong>{{ $company['name'] }}</strong><br>
                    @if($company['address']){{ $company['address'] }}<br>@endif
                    @if($company['city'] || $company['postal_code'])
                        {{ $company['postal_code'] }} {{ $company['city'] }}<br>
                    @endif
                    @if($company['country']){{ $company['country'] }}<br>@endif
                    @if($company['vat_number'])VAT: {{ $company['vat_number'] }}<br>@endif
                    @if($company['email']){{ $company['email'] }}@endif
                </div>
            </td>
            <td style="width: 50%; vertical-align: top;">
                <div class="address-label">Bill To</div>
                <div class="address-content">
                    <strong>{{ $invoice->billing_name }}</strong><br>
                    @if($invoice->billing_address['line1'] ?? null){{ $invoice->billing_address['line1'] }}<br>@endif
                    @if($invoice->billing_address['line2'] ?? null){{ $invoice->billing_address['line2'] }}<br>@endif
                    @if(($invoice->billing_address['postal_code'] ?? null) || ($invoice->billing_address['city'] ?? null))
                        {{ $invoice->billing_address['postal_code'] ?? '' }} {{ $invoice->billing_address['city'] ?? '' }}<br>
                    @endif
                    @if($invoice->billing_address['country'] ?? null){{ $invoice->billing_address['country'] }}<br>@endif
                    @if($invoice->billing_email){{ $invoice->billing_email }}@endif
                </div>
            </td>
        </tr>
    </table>

    <!-- Invoice Details -->
    <table style="width: 50%; margin-left: auto; margin-bottom: 30px;">
        <tr>
            <td style="color: #666; padding: 5px 10px;">Invoice Date:</td>
            <td style="text-align: right; padding: 5px 10px;">{{ $invoice->invoice_date->format('M d, Y') }}</td>
        </tr>
        <tr>
            <td style="color: #666; padding: 5px 10px;">Due Date:</td>
            <td style="text-align: right; padding: 5px 10px; {{ $invoice->isOverdue() ? 'color: #dc2626; font-weight: bold;' : '' }}">
                {{ $invoice->due_date->format('M d, Y') }}
            </td>
        </tr>
        @if($invoice->period_start && $invoice->period_end)
        <tr>
            <td style="color: #666; padding: 5px 10px;">Period:</td>
            <td style="text-align: right; padding: 5px 10px;">
                {{ $invoice->period_start->format('M d, Y') }} - {{ $invoice->period_end->format('M d, Y') }}
            </td>
        </tr>
        @endif
    </table>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 50%;">Description</th>
                <th style="width: 10%;">Qty</th>
                <th style="width: 15%;">Unit Price</th>
                <th style="width: 10%;">Tax</th>
                <th style="width: 15%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
            <tr>
                <td>
                    <div class="item-description">{{ $item->description }}</div>
                    @if($item->long_description)
                        <div style="font-size: 10px; color: #666; margin-top: 3px;">{{ $item->long_description }}</div>
                    @endif
                    @if($item->period_start && $item->period_end)
                        <div class="item-period">
                            {{ $item->period_start->format('M d, Y') }} - {{ $item->period_end->format('M d, Y') }}
                        </div>
                    @endif
                </td>
                <td>{{ number_format($item->quantity, $item->quantity == floor($item->quantity) ? 0 : 2) }}</td>
                <td>{{ number_format($item->unit_price, 2) }} {{ $invoice->currency }}</td>
                <td>{{ number_format($item->tax_rate, 1) }}%</td>
                <td>{{ number_format($item->amount + $item->tax_amount, 2) }} {{ $invoice->currency }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Totals -->
    <div class="totals">
        <table>
            <tr>
                <td>Subtotal:</td>
                <td>{{ number_format($invoice->subtotal, 2) }} {{ $invoice->currency }}</td>
            </tr>
            @if($invoice->tax_amount > 0)
            <tr>
                <td>Tax ({{ number_format($invoice->tax_rate, 1) }}%):</td>
                <td>{{ number_format($invoice->tax_amount, 2) }} {{ $invoice->currency }}</td>
            </tr>
            @endif
            @if($invoice->discount_amount > 0)
            <tr>
                <td>Discount:</td>
                <td>-{{ number_format($invoice->discount_amount, 2) }} {{ $invoice->currency }}</td>
            </tr>
            @endif
            <tr class="total-row">
                <td>Total:</td>
                <td>{{ number_format($invoice->total, 2) }} {{ $invoice->currency }}</td>
            </tr>
            @if($invoice->amount_paid > 0)
            <tr>
                <td>Paid:</td>
                <td>{{ number_format($invoice->amount_paid, 2) }} {{ $invoice->currency }}</td>
            </tr>
            @endif
            @if($invoice->amount_due > 0)
            <tr class="due-row">
                <td>Amount Due:</td>
                <td>{{ number_format($invoice->amount_due, 2) }} {{ $invoice->currency }}</td>
            </tr>
            @endif
        </table>
    </div>

    <!-- Payment Info -->
    @if($invoice->status->isPayable())
    <div class="payment-info">
        <div class="payment-info-title">Payment Information</div>
        <div>
            Please include invoice number <strong>{{ $invoice->number }}</strong> as payment reference.
        </div>
    </div>
    @endif

    <!-- Notes -->
    @if($invoice->notes)
    <div class="notes">
        <div class="notes-label">Notes</div>
        <div>{{ $invoice->notes }}</div>
    </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        @if($invoice->footer_text)
            <div style="margin-bottom: 10px;">{{ $invoice->footer_text }}</div>
        @endif
        <div>
            {{ $company['name'] }}
            @if($company['website']) | {{ $company['website'] }} @endif
            @if($company['phone']) | {{ $company['phone'] }} @endif
        </div>
    </div>
</body>
</html>
