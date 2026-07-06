<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale ?? 'en') }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: "DejaVu Sans", sans-serif; color: #111827; font-size: 12px; }
        .header { display: table; width: 100%; margin-bottom: 24px; }
        .company, .title { display: table-cell; vertical-align: top; width: 50%; }
        .title { text-align: right; }
        h1 { margin: 0 0 8px; font-size: 24px; }
        h2 { margin: 18px 0 8px; font-size: 14px; }
        .muted { color: #6b7280; }
        .grid { display: table; width: 100%; margin-bottom: 14px; }
        .col { display: table-cell; width: 50%; vertical-align: top; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { background: #f3f4f6; text-align: left; font-weight: 700; }
        th, td { border: 1px solid #d1d5db; padding: 8px; }
        .num { text-align: right; }
        .signatures { display: table; width: 100%; margin-top: 42px; }
        .signature { display: table-cell; width: 33%; padding-right: 20px; }
        .line { border-top: 1px solid #111827; height: 36px; margin-top: 34px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="company">
            <strong>{{ $company->legal_name ?? $company->name }}</strong><br>
            @if($company->tax_id)
                <span class="muted">{{ $company->tax_id }}</span><br>
            @endif
            <span class="muted">{{ $company->country_code }}</span>
        </div>
        <div class="title">
            <h1>Bon de réception</h1>
            <div>{{ $receipt->receipt_number }}</div>
            <div class="muted">{{ $formatDate($receipt->received_at) }}</div>
        </div>
    </div>

    <div class="grid">
        <div class="col">
            <h2>Fournisseur</h2>
            <div>{{ $supplier?->name ?? '-' }}</div>
            @if($supplier?->vat_number)
                <div class="muted">{{ $supplier->vat_number }}</div>
            @endif
        </div>
        <div class="col">
            <h2>Références</h2>
            <div>Commande: {{ $po_number ?? '-' }}</div>
            <div>BL: {{ $external_reference ?? '-' }}</div>
            <div>Date BL: {{ $formatDate($external_date) }}</div>
            <div>Emplacement: {{ $location?->name ?? '-' }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Produit</th>
                <th class="num">Qté reçue</th>
                <th class="num">Gratuit</th>
                <th class="num">Coût unitaire</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lines as $line)
                <tr>
                    <td>
                        {{ $line->product?->name ?? $line->product_id }}
                        @if($line->product?->sku)
                            <br><span class="muted">{{ $line->product->sku }}</span>
                        @endif
                    </td>
                    <td class="num">{{ $formatNumber($line->received_qty, 4) }}</td>
                    <td class="num">{{ $formatNumber($line->free_qty, 4) }}</td>
                    <td class="num">{{ $formatNumber($line->effective_unit_cost, 3) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="signatures">
        <div class="signature">
            <strong>Réception</strong>
            <div class="line"></div>
        </div>
        <div class="signature">
            <strong>Contrôle</strong>
            <div class="line"></div>
        </div>
        <div class="signature">
            <strong>Fournisseur</strong>
            <div class="line"></div>
        </div>
    </div>
</body>
</html>
