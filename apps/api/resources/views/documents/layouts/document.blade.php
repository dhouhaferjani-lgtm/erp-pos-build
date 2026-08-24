<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $documentTitle ?? 'Document' }} - {{ $document->document_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10pt;
            line-height: 1.4;
            color: #333;
        }

        .container {
            padding: 20px;
            max-width: 100%;
        }

        /* Header */
        .header {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }

        .header-left {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .header-right {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            text-align: right;
        }

        .company-logo {
            max-width: 150px;
            max-height: 60px;
            margin-bottom: 10px;
        }

        .company-name {
            font-size: 16pt;
            font-weight: bold;
            color: {{ $company->primary_color ?? '#1f2937' }};
        }

        .company-details {
            font-size: 9pt;
            color: #666;
            margin-top: 5px;
        }

        .document-title {
            font-size: 18pt;
            font-weight: bold;
            color: {{ $company->primary_color ?? '#1f2937' }};
            margin-bottom: 10px;
        }

        .document-number {
            font-size: 12pt;
            font-weight: bold;
        }

        .document-date {
            font-size: 10pt;
            color: #666;
        }

        /* Party Info */
        .parties {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }

        .party-box {
            display: table-cell;
            width: 48%;
            vertical-align: top;
            padding: 15px;
            background-color: #f9fafb;
            border-radius: 4px;
        }

        .party-box:first-child {
            margin-right: 4%;
        }

        .party-title {
            font-size: 9pt;
            font-weight: bold;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .party-name {
            font-size: 11pt;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .party-details {
            font-size: 9pt;
            color: #555;
        }

        /* Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }

        .items-table th {
            background-color: {{ $company->primary_color ?? '#1f2937' }};
            color: white;
            font-size: 9pt;
            font-weight: bold;
            text-transform: uppercase;
            padding: 10px 8px;
            text-align: left;
        }

        .items-table th.right {
            text-align: right;
        }

        .items-table th.center {
            text-align: center;
        }

        .items-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 9pt;
        }

        .items-table td.right {
            text-align: right;
        }

        .items-table td.center {
            text-align: center;
        }

        .items-table tbody tr:nth-child(even) {
            background-color: #f9fafb;
        }

        .item-description {
            color: #666;
            font-size: 8pt;
            margin-top: 3px;
        }

        /* Totals */
        .totals-section {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }

        .totals-notes {
            display: table-cell;
            width: 55%;
            vertical-align: top;
            padding-right: 20px;
        }

        .totals-box {
            display: table-cell;
            width: 45%;
            vertical-align: top;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }

        .totals-table tr td {
            padding: 6px 10px;
            font-size: 10pt;
        }

        .totals-table tr td:first-child {
            text-align: left;
            color: #666;
        }

        .totals-table tr td:last-child {
            text-align: right;
            font-weight: 500;
        }

        .totals-table tr.total-row {
            background-color: {{ $company->primary_color ?? '#1f2937' }};
            color: white;
        }

        .totals-table tr.total-row td {
            font-size: 12pt;
            font-weight: bold;
            padding: 10px;
        }

        .totals-table tr.balance-row {
            background-color: #fef3c7;
        }

        .totals-table tr.balance-row td {
            font-weight: bold;
            color: #92400e;
        }

        /* Notes */
        .notes-section {
            margin-top: 30px;
            padding: 15px;
            background-color: #f9fafb;
            border-radius: 4px;
        }

        .notes-title {
            font-size: 9pt;
            font-weight: bold;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .notes-content {
            font-size: 9pt;
            color: #555;
            white-space: pre-wrap;
        }

        /* Footer */
        .footer {
            position: fixed;
            bottom: 20px;
            left: 20px;
            right: 20px;
            text-align: center;
            font-size: 8pt;
            color: #999;
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
        }

        .footer-company {
            font-weight: bold;
        }

        /* Payment Info */
        .payment-info {
            margin-top: 20px;
            padding: 15px;
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 4px;
        }

        .payment-title {
            font-size: 9pt;
            font-weight: bold;
            color: #0369a1;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .payment-details {
            font-size: 9pt;
            color: #0c4a6e;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 9pt;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-draft { background-color: #f3f4f6; color: #6b7280; }
        .status-confirmed { background-color: #dbeafe; color: #1d4ed8; }
        .status-posted { background-color: #dcfce7; color: #16a34a; }
        .status-paid { background-color: #d1fae5; color: #059669; }
        .status-cancelled { background-color: #fee2e2; color: #dc2626; }

        /* N-6 — the not-yet-posted marker. Deliberately loud: this is the one
           line that distinguishes a printout the ledger has never seen from a
           sealed fiscal invoice. */
        .posting-marker {
            margin: 0 0 16px 0;
            padding: 8px 12px;
            border: 1.5px solid #b45309;
            border-radius: 4px;
            background-color: #fffbeb;
            color: #7c2d12;
            font-size: 9pt;
            line-height: 1.4;
        }

        .posting-marker strong {
            display: block;
            font-size: 10pt;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        /* Page Break */
        .page-break {
            page-break-after: always;
        }
    </style>
    @stack('styles')
</head>
<body>
    <div class="container">
        @yield('content')
    </div>

    <div class="footer">
        <span class="footer-company">{{ $company->legal_name ?? $company->name }}</span>
        @php($sellerTaxIdDisplay = ($sellerTaxId ?? null) ?? $company->tax_id)
        @if($sellerTaxIdDisplay)
            | {{ ($sellerTaxLabel ?? null) ?? __('Tax ID') }}: {{ $sellerTaxIdDisplay }}
        @endif
        @if($company->registration_number)
            | {{ __('Reg.') }}: {{ $company->registration_number }}
        @endif
        @if($company->phone)
            | {{ $company->phone }}
        @endif
        @if($company->email)
            | {{ $company->email }}
        @endif
    </div>

    @stack('scripts')
</body>
</html>
