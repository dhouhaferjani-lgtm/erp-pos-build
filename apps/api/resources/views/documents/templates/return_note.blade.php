@extends('documents.layouts.document')

@section('content')
    @include('documents.components.header')

    @include('documents.components.parties')

    @if($document->reference)
    <div style="margin-bottom: 20px; font-size: 9pt;">
        <strong>{{ __('Reference') }}:</strong> {{ $document->reference }}
    </div>
    @endif

    @if($document->source_invoice_id || $document->source_delivery_note_id)
    <div style="margin-bottom: 20px; font-size: 9pt;">
        @if($document->sourceInvoice)
            <strong>{{ __('Source Invoice') }}:</strong> {{ $document->sourceInvoice->document_number }}<br>
        @endif
        @if($document->sourceDeliveryNote)
            <strong>{{ __('Source Delivery Note') }}:</strong> {{ $document->sourceDeliveryNote->document_number }}
        @endif
    </div>
    @endif

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 50%;">{{ __('Description') }}</th>
                <th class="center" style="width: 15%;">{{ __('Quantity Returned') }}</th>
                <th class="center" style="width: 15%;">{{ __('Unit') }}</th>
                <th class="right" style="width: 15%;">{{ __('Reason') }}</th>
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
                    @else
                        <strong>{{ $line->product_name ?? 'Item' }}</strong>
                    @endif
                    @if($line->description)
                        <div class="item-description">{{ $line->description }}</div>
                    @endif
                </td>
                <td class="center">{{ $formatNumber($line->quantity, 2) }}</td>
                <td class="center">{{ $line->unit ?? 'pc' }}</td>
                <td class="right">{{ $line->notes ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @if($vehicle ?? null)
    <div style="margin-top: 20px; padding: 15px; background-color: #f0f9ff; border-radius: 4px;">
        <div class="notes-title" style="color: #0369a1;">{{ __('Vehicle Information') }}</div>
        <div style="font-size: 9pt;">
            @if($vehicle->make || $vehicle->model)
                <strong>{{ $vehicle->make }} {{ $vehicle->model }}</strong><br>
            @endif
            @if($vehicle->registration_number)
                {{ __('Registration') }}: {{ $vehicle->registration_number }}<br>
            @endif
            @if($vehicle->vin)
                {{ __('VIN') }}: {{ $vehicle->vin }}
            @endif
        </div>
    </div>
    @endif

    @if($document->notes)
    <div class="notes-section">
        <div class="notes-title">{{ __('Notes') }}</div>
        <div class="notes-content">{{ $document->notes }}</div>
    </div>
    @endif

    <div style="margin-top: 40px; display: table; width: 100%;">
        <div style="display: table-cell; width: 48%; padding-right: 4%;">
            <div style="font-size: 9pt; color: #666; margin-bottom: 10px;">{{ __('Returned By') }}:</div>
            <div style="border-bottom: 1px solid #333; height: 40px; margin-bottom: 5px;"></div>
            <div style="font-size: 8pt; color: #666;">{{ __('Signature & Date') }}</div>
        </div>
        <div style="display: table-cell; width: 48%;">
            <div style="font-size: 9pt; color: #666; margin-bottom: 10px;">{{ __('Received By') }}:</div>
            <div style="border-bottom: 1px solid #333; height: 40px; margin-bottom: 5px;"></div>
            <div style="font-size: 8pt; color: #666;">{{ __('Signature & Date') }}</div>
        </div>
    </div>
@endsection
