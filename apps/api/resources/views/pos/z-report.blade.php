<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('pos.z_report') }} - {{ $zReport->getFormattedZNumber() }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
            font-size: 10pt;
            line-height: 1.4;
            color: #000;
            padding: 20px 30px;
            max-width: 100%;
        }

        .report {
            max-width: 100%;
        }

        /* Header Section */
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
        }

        .company-logo {
            max-width: 120px;
            max-height: 60px;
            margin-bottom: 10px;
        }

        .company-name {
            font-size: 16pt;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .company-details {
            font-size: 9pt;
            line-height: 1.3;
        }

        /* Report Title */
        .report-title {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            margin: 15px 0;
            padding: 10px 0;
            border: 2px solid #000;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        /* Report Info */
        .report-info {
            margin-bottom: 15px;
            font-size: 9pt;
            border-bottom: 1px solid #ccc;
            padding-bottom: 10px;
        }

        .report-info div {
            margin-bottom: 3px;
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

        /* Section */
        .section {
            margin-bottom: 15px;
        }

        .section-title {
            font-size: 11pt;
            font-weight: bold;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #000;
        }

        /* Summary rows */
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
            font-size: 9pt;
        }

        .summary-row.total {
            font-size: 11pt;
            font-weight: bold;
            margin-top: 5px;
            padding-top: 5px;
            border-top: 1px solid #000;
        }

        .summary-row .row-label {
            flex: 1;
        }

        .summary-row .row-value {
            text-align: right;
            font-family: 'DejaVu Sans Mono', monospace;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
            margin-bottom: 10px;
        }

        table th {
            text-align: left;
            font-weight: bold;
            padding: 4px 6px;
            border-bottom: 1px solid #000;
            font-size: 8pt;
        }

        table th.text-right {
            text-align: right;
        }

        table td {
            padding: 3px 6px;
            border-bottom: 1px solid #eee;
        }

        table td.text-right {
            text-align: right;
            font-family: 'DejaVu Sans Mono', monospace;
        }

        table tfoot td {
            font-weight: bold;
            border-top: 1px solid #000;
            padding-top: 5px;
        }

        /* Variance highlight */
        .variance-ok {
            color: #006600;
        }

        .variance-alert {
            color: #cc0000;
            font-weight: bold;
        }

        /* Fiscal Section */
        .fiscal {
            margin-top: 15px;
            margin-bottom: 15px;
            font-size: 8pt;
            background-color: #f5f5f5;
            padding: 8px;
            border: 1px solid #ccc;
        }

        .fiscal h4 {
            font-size: 9pt;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .fiscal-line {
            margin-bottom: 3px;
            word-break: break-all;
        }

        /* Footer */
        .footer {
            text-align: center;
            font-size: 8pt;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #ccc;
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
    </style>
</head>
<body>
    <div class="report">
        {{-- Header Section --}}
        <div class="header">
            @if($company->logo_path)
                <img src="{{ Storage::url($company->logo_path) }}" alt="{{ $company->name }}" class="company-logo">
            @endif
            <div class="company-name">{{ $company->name }}</div>
            <div class="company-details">
                @if($company->legal_name && $company->legal_name !== $company->name)
                    {{ $company->legal_name }}<br>
                @endif
                @if($company->address_street)
                    {{ $company->address_street }}<br>
                @endif
                @if($company->address_city)
                    {{ $company->address_postal_code }} {{ $company->address_city }}<br>
                @endif
                @php($sellerTaxIdDisplay = ($sellerTaxId ?? null) ?? $company->tax_id)
                @if($sellerTaxIdDisplay)
                    {{ ($sellerTaxLabel ?? null) ?? __('pos.tax_id') }}: {{ $sellerTaxIdDisplay }}<br>
                @endif
                @if($company->phone)
                    {{ __('pos.tel') }}: {{ $company->phone }}<br>
                @endif
            </div>
        </div>

        {{-- Report Title --}}
        <div class="report-title">{{ __('pos.z_report') }} {{ $zReport->getFormattedZNumber() }}</div>

        {{-- Report Info --}}
        <div class="report-info">
            <div>
                <span class="label">{{ __('pos.z_report_number') }}:</span>
                <span class="value">{{ $zReport->getFormattedZNumber() }}</span>
            </div>
            <div>
                <span class="label">{{ __('pos.date_time') }}:</span>
                <span class="value">{{ $formatDateTime($zReport->generated_at) }}</span>
            </div>
            @if($terminal)
                <div>
                    <span class="label">{{ __('pos.terminal') }}:</span>
                    <span class="value">{{ $terminal->name }} ({{ $terminal->code }})</span>
                </div>
            @endif
            @if($generatedByName)
                <div>
                    <span class="label">{{ __('pos.generated_by') }}:</span>
                    <span class="value">{{ $generatedByName }}</span>
                </div>
            @endif
        </div>

        {{-- Sales Summary --}}
        <div class="section">
            <div class="section-title">{{ __('pos.z_report_sales_summary') }}</div>
            <div class="summary-row">
                <span class="row-label">{{ __('pos.z_report_receipt_count') }}:</span>
                <span class="row-value">{{ $reportData['sales_count'] ?? 0 }}</span>
            </div>
            <div class="summary-row">
                <span class="row-label">{{ __('pos.z_report_gross_sales') }}:</span>
                <span class="row-value">{{ $formatMoney($reportData['gross_sales'] ?? '0.00') }}</span>
            </div>
            <div class="summary-row">
                <span class="row-label">{{ __('pos.z_report_net_sales') }}:</span>
                <span class="row-value">{{ $formatMoney($reportData['net_sales'] ?? '0.00') }}</span>
            </div>
            <div class="summary-row">
                <span class="row-label">{{ __('pos.z_report_tax_amount') }}:</span>
                <span class="row-value">{{ $formatMoney($reportData['tax_amount'] ?? '0.00') }}</span>
            </div>
            <div class="summary-row">
                <span class="row-label">{{ __('pos.z_report_refunds') }}:</span>
                <span class="row-value">{{ $reportData['refunds_count'] ?? 0 }} ({{ $formatMoney($reportData['refunds_amount'] ?? '0.00') }})</span>
            </div>
            <div class="summary-row">
                <span class="row-label">{{ __('pos.z_report_voided') }}:</span>
                <span class="row-value">{{ $reportData['voided_count'] ?? 0 }}</span>
            </div>
            @if(($reportData['sales_count'] ?? 0) > 0)
                <div class="summary-row total">
                    <span class="row-label">{{ __('pos.z_report_average_ticket') }}:</span>
                    <span class="row-value">{{ $formatMoney($averageTicket) }}</span>
                </div>
            @endif
        </div>

        {{-- Cash Summary --}}
        <div class="section">
            <div class="section-title">{{ __('pos.z_report_cash_summary') }}</div>
            <div class="summary-row">
                <span class="row-label">{{ __('pos.z_report_opening_cash') }}:</span>
                <span class="row-value">{{ $formatMoney($reportData['opening_cash'] ?? '0.00') }}</span>
            </div>
            <div class="summary-row">
                <span class="row-label">{{ __('pos.z_report_expected_cash') }}:</span>
                <span class="row-value">{{ $formatMoney($reportData['expected_cash'] ?? '0.00') }}</span>
            </div>
            <div class="summary-row">
                <span class="row-label">{{ __('pos.z_report_actual_cash') }}:</span>
                <span class="row-value">{{ $formatMoney($reportData['actual_cash'] ?? '0.00') }}</span>
            </div>
            <div class="summary-row total">
                <span class="row-label">{{ __('pos.z_report_variance') }}:</span>
                <span class="row-value {{ $hasVariance ? 'variance-alert' : 'variance-ok' }}">
                    {{ $formatMoney($reportData['variance'] ?? '0.00') }}
                </span>
            </div>
        </div>

        {{-- VAT Breakdown --}}
        @if(!empty($vatBreakdown))
            <div class="section">
                <div class="section-title">{{ __('pos.vat_breakdown') }}</div>
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('pos.z_report_vat_rate') }}</th>
                            <th class="text-right">{{ __('pos.z_report_vat_net') }}</th>
                            <th class="text-right">{{ __('pos.z_report_vat_amount') }}</th>
                            <th class="text-right">{{ __('pos.z_report_vat_gross') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($vatBreakdown as $vat)
                            <tr>
                                <td>{{ $formatNumber($vat['rate'] ?? $vat['tax_rate'] ?? '0', 2) }}%</td>
                                <td class="text-right">{{ $formatMoney($vat['net'] ?? $vat['net_amount'] ?? '0.00') }}</td>
                                <td class="text-right">{{ $formatMoney($vat['vat'] ?? $vat['vat_amount'] ?? '0.00') }}</td>
                                <td class="text-right">{{ $formatMoney($vat['gross'] ?? $vat['gross_amount'] ?? '0.00') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Payment Methods --}}
        @if(!empty($paymentMethods))
            <div class="section">
                <div class="section-title">{{ __('pos.payment_methods') }}</div>
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('pos.z_report_payment_method') }}</th>
                            <th class="text-right">{{ __('pos.z_report_payment_count') }}</th>
                            <th class="text-right">{{ __('pos.z_report_payment_amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($paymentMethods as $payment)
                            <tr>
                                <td>{{ $payment['type'] ?? $payment['payment_type'] ?? '' }}</td>
                                <td class="text-right">{{ $payment['count'] ?? $payment['transaction_count'] ?? 0 }}</td>
                                <td class="text-right">{{ $formatMoney($payment['amount'] ?? $payment['total_amount'] ?? '0.00') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Fiscal Hash Chain Info --}}
        <div class="fiscal">
            <h4>{{ __('pos.fiscal_information') }}</h4>
            <div class="fiscal-line">
                <strong>{{ __('pos.z_report_z_number') }}:</strong> {{ $zReport->getFormattedZNumber() }}
            </div>
            <div class="fiscal-line">
                <strong>{{ __('pos.fiscal_hash') }}:</strong><br>
                {{ substr($zReport->fiscal_hash, 0, 32) }}<br>
                {{ substr($zReport->fiscal_hash, 32) }}
            </div>
            @if(!$zReport->isFirstZReport())
                <div class="fiscal-line">
                    <strong>{{ __('pos.previous_hash') }}:</strong><br>
                    {{ substr($zReport->previous_z_hash ?? '', 0, 32) }}<br>
                    {{ substr($zReport->previous_z_hash ?? '', 32) }}
                </div>
            @else
                <div class="fiscal-line">
                    <strong>{{ __('pos.z_report_genesis') }}</strong>
                </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="footer">
            <div>{{ __('pos.powered_by') }}</div>
        </div>
    </div>
</body>
</html>
