<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $documentTitle }} {{ $documentNumber }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #1f2937;
            margin: 0;
        }
        .document-info {
            background-color: #f9fafb;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .document-info h2 {
            margin: 0 0 10px 0;
            font-size: 18px;
            color: #374151;
        }
        .document-info p {
            margin: 5px 0;
            color: #6b7280;
        }
        .document-total {
            font-size: 24px;
            font-weight: bold;
            color: #1f2937;
        }
        .message {
            padding: 15px;
            background-color: #fef3c7;
            border-left: 4px solid #f59e0b;
            margin: 20px 0;
            border-radius: 0 8px 8px 0;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 14px;
            color: #6b7280;
        }
        .attachment-note {
            padding: 15px;
            background-color: #dbeafe;
            border-radius: 8px;
            margin: 20px 0;
        }
        .attachment-note strong {
            color: #1d4ed8;
        }
    </style>
</head>
<body>
    <div class="header">
        <p class="company-name">{{ $company->name }}</p>
    </div>

    <p>Dear {{ $partner?->name ?? 'Valued Customer' }},</p>

    <p>Please find attached your {{ strtolower($documentTitle) }} from {{ $company->name }}.</p>

    <div class="document-info">
        <h2>{{ $documentTitle }}</h2>
        <p><strong>Document Number:</strong> {{ $documentNumber }}</p>
        <p><strong>Date:</strong> {{ $document->document_date?->format('F j, Y') ?? now()->format('F j, Y') }}</p>
        <p class="document-total">Total: {{ number_format((float) $total, 2) }} {{ $currency }}</p>
    </div>

    @if($customMessage)
    <div class="message">
        <p>{{ $customMessage }}</p>
    </div>
    @endif

    <div class="attachment-note">
        <strong>Attachment:</strong> The document is attached as a PDF file for your records.
    </div>

    <p>If you have any questions about this document, please don't hesitate to contact us.</p>

    <div class="footer">
        <p>Best regards,<br>{{ $company->name }}</p>
        @if($company->email)
        <p>Email: {{ $company->email }}</p>
        @endif
        @if($company->phone)
        <p>Phone: {{ $company->phone }}</p>
        @endif
        @if($company->address)
        <p>Address: {{ $company->address }}</p>
        @endif
    </div>
</body>
</html>
