<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withholding Tax Certificate - {{ $certificate->certificate_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            font-size: 12pt;
            line-height: 1.6;
            color: #333;
            padding: 40px;
        }

        .header {
            text-align: center;
            margin-bottom: 40px;
            border-bottom: 3px solid #333;
            padding-bottom: 20px;
        }

        .header h1 {
            font-size: 24pt;
            margin-bottom: 10px;
            color: #000;
        }

        .header .subtitle {
            font-size: 14pt;
            color: #666;
        }

        .certificate-info {
            margin-bottom: 30px;
            text-align: right;
        }

        .certificate-info strong {
            color: #000;
        }

        .section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 14pt;
            font-weight: bold;
            margin-bottom: 15px;
            color: #000;
            border-bottom: 2px solid #ddd;
            padding-bottom: 5px;
        }

        .info-grid {
            display: table;
            width: 100%;
            margin-bottom: 10px;
        }

        .info-row {
            display: table-row;
        }

        .info-label {
            display: table-cell;
            width: 40%;
            padding: 8px 0;
            font-weight: bold;
            color: #555;
        }

        .info-value {
            display: table-cell;
            padding: 8px 0;
            color: #000;
        }

        .amounts-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .amounts-table th,
        .amounts-table td {
            padding: 12px;
            text-align: right;
            border: 1px solid #ddd;
        }

        .amounts-table th {
            background-color: #f5f5f5;
            font-weight: bold;
            color: #000;
        }

        .amounts-table .total-row {
            background-color: #f9f9f9;
            font-weight: bold;
            font-size: 14pt;
        }

        .amounts-table .label-col {
            text-align: left;
        }

        .footer {
            margin-top: 60px;
            padding-top: 20px;
            border-top: 2px solid #333;
            text-align: center;
            color: #666;
            font-size: 10pt;
        }

        .signature-section {
            margin-top: 60px;
            display: table;
            width: 100%;
        }

        .signature-block {
            display: table-cell;
            width: 50%;
            padding: 20px;
            text-align: center;
        }

        .signature-line {
            border-top: 1px solid #333;
            margin-top: 60px;
            padding-top: 10px;
            font-weight: bold;
        }

        .hash-section {
            margin-top: 40px;
            padding: 15px;
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            font-size: 9pt;
            font-family: 'Courier New', monospace;
        }

        .hash-section strong {
            display: block;
            margin-bottom: 5px;
            font-family: 'Arial', sans-serif;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>ATTESTATION DE RETENUE À LA SOURCE</h1>
        <h1>WITHHOLDING TAX CERTIFICATE</h1>
        <div class="subtitle">{{ $certificate->direction->label() }}</div>
    </div>

    <div class="certificate-info">
        <strong>Certificate No:</strong> {{ $certificate->certificate_number }}<br>
        <strong>Year:</strong> {{ $certificate->year }}<br>
        <strong>Issued Date:</strong> {{ $certificate->issued_at?->format('d/m/Y') ?? 'N/A' }}
    </div>

    <div class="section">
        <div class="section-title">Company Information / Informations sur l'entreprise</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Company Name / Raison sociale:</div>
                <div class="info-value">{{ $company->name }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Tax ID / Matricule fiscale:</div>
                <div class="info-value">{{ $company->tax_id }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Address / Adresse:</div>
                <div class="info-value">{{ $company->address ?? 'N/A' }}</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Beneficiary Information / Informations sur le bénéficiaire</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Name / Nom:</div>
                <div class="info-value">{{ $partner->name }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Tax ID / Matricule fiscale:</div>
                <div class="info-value">{{ $partner->vat_number ?? $partner->code ?? 'N/A' }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Tax Status / Statut fiscal:</div>
                <div class="info-value">{{ $partner->tax_status->label() }}</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Withholding Details / Détails de la retenue</div>
        <div class="info-grid">
            @if($certificate->rule)
            <div class="info-row">
                <div class="info-label">Applied Rule / Règle appliquée:</div>
                <div class="info-value">{{ $certificate->rule->name }} ({{ $certificate->rule->code }})</div>
            </div>
            @endif
            @if($certificate->override_reason)
            <div class="info-row">
                <div class="info-label">Override Reason / Raison du remplacement:</div>
                <div class="info-value">{{ $certificate->override_reason }}</div>
            </div>
            @endif
            @if($payment)
            <div class="info-row">
                <div class="info-label">Payment Date / Date de paiement:</div>
                <div class="info-value">{{ $payment->payment_date?->format('d/m/Y') }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Payment Reference / Référence de paiement:</div>
                <div class="info-value">{{ $payment->reference ?? 'N/A' }}</div>
            </div>
            @endif
            <div class="info-row">
                <div class="info-label">GL Account / Compte comptable:</div>
                <div class="info-value">{{ $certificate->getGLAccountCode() }}</div>
            </div>
        </div>

        <table class="amounts-table">
            <thead>
                <tr>
                    <th class="label-col">Description</th>
                    <th>Amount ({{ $certificate->currency }})</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="label-col">Gross Amount / Montant brut</td>
                    <td>{{ number_format((float) $certificate->gross_amount, 3, '.', ',') }}</td>
                </tr>
                <tr>
                    <td class="label-col">Withholding Rate / Taux de retenue</td>
                    <td>{{ $certificate->getRateAsPercentage() }}%</td>
                </tr>
                <tr>
                    <td class="label-col">Withholding Amount / Montant retenu</td>
                    <td>({{ number_format((float) $certificate->withholding_amount, 3, '.', ',') }})</td>
                </tr>
                <tr class="total-row">
                    <td class="label-col">Net Amount / Montant net</td>
                    <td>{{ number_format((float) $certificate->net_amount, 3, '.', ',') }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    @if($certificate->hash)
    <div class="hash-section">
        <strong>Fiscal Hash Chain / Chaîne de hachage fiscal:</strong>
        Hash: {{ $certificate->hash }}<br>
        Sequence: {{ $certificate->chain_sequence }}
    </div>
    @endif

    @if(isset($qrCode))
    <div style="text-align: center; margin-top: 30px; padding: 20px; background-color: #f9f9f9; border: 1px solid #ddd;">
        <strong style="display: block; margin-bottom: 10px; font-size: 12pt;">
            Verification QR Code / QR Code de vérification
        </strong>
        <img src="data:image/png;base64,{{ $qrCode }}" alt="QR Code" style="width: 200px; height: 200px;" />
        <p style="margin-top: 10px; font-size: 9pt; color: #666;">
            Scan to verify certificate authenticity / Scanner pour vérifier l'authenticité
        </p>
    </div>
    @endif

    <div class="signature-section">
        <div class="signature-block">
            <div>Prepared By / Préparé par</div>
            <div class="signature-line">
                {{ $certificate->issuer?->name ?? 'AutoERP System' }}
            </div>
        </div>
        <div class="signature-block">
            <div>Company Stamp / Cachet de l'entreprise</div>
            <div class="signature-line">
                &nbsp;
            </div>
        </div>
    </div>

    <div class="footer">
        <p>This certificate is generated electronically by AutoERP</p>
        <p>Ce certificat est généré électroniquement par AutoERP</p>
        <p style="margin-top: 10px;">Certificate ID: {{ $certificate->id }}</p>
    </div>
</body>
</html>
