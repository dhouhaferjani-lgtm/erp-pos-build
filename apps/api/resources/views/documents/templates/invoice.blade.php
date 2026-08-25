@extends('documents.layouts.document')

@section('content')
@php
    /*
     | C-F0 / SPEC §2.4 (F-95). `DocumentPdfService::prepareData()` always supplies
     | `isProforma` (pinned by ProformaTemplateCensusTest); the `?? true` is the
     | FAIL-SAFE default owner ruling OQ-14 asks for, and it applies to a direct
     | `view('documents.templates.…', …)` render that supplies no flag: an unknown
     | fiscal state prints as non-definitive rather than as an issued, VAT-bearing
     | document. @include hands this resolved value down to every component below.
     */
    $isProforma = $isProforma ?? true;
@endphp

    @include('documents.components.header')

    @include('documents.components.posting_marker')

    @include('documents.components.parties')

    @include('documents.components.line_items', [
        'showTax' => ! $isProforma,
        'lineDesignationOverrideEnabled' => (bool) ($company->line_designation_override_enabled ?? false),
    ])

    @include('documents.components.totals')

    @include('documents.components.payment_info')

    @if($document->notes)
    <div class="notes-section">
        <div class="notes-title">{{ __('Terms & Conditions') }}</div>
        <div class="notes-content">{{ $document->notes }}</div>
    </div>
    @endif
@endsection
