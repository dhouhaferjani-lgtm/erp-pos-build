<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FacturXProfile;
use App\Services\CompanyConfigService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use NumberFormatter;

final class DocumentPdfService
{
    public function __construct(
        private readonly CompanyConfigService $configService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly FacturXService $facturXService,
        private readonly FacturXPdfGenerator $facturXPdfGenerator,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Generate PDF for a document.
     *
     * @param  Document  $document  The document to generate PDF for
     * @param  bool  $stream  Whether to return stream or download response
     */
    public function generate(Document $document, bool $stream = false): DomPdf
    {
        $document->load(['company.tenant', 'partner', 'lines']);

        $company = $document->company;

        // Only load vehicle context if Vehicle module is enabled
        $hasVehicleModule = $this->configService->getConfigForTenant($company->tenant)->hasModule('Vehicle');
        if ($hasVehicleModule) {
            $document->load(['vehicleContext']);
        }
        $templateView = $this->resolveTemplate($document);

        $data = $this->prepareData($document, $company);

        $pdf = Pdf::loadView($templateView, $data);

        // Set paper size and orientation
        $pdf->setPaper('a4', 'portrait');

        // Set PDF options
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('defaultFont', 'DejaVu Sans');

        return $pdf;
    }

    /**
     * Generate and return PDF as binary string.
     *
     * If the document is eligible for Factur-X, the XML is generated,
     * stored on the document, and embedded into the PDF as PDF/A-3.
     */
    public function generateContent(Document $document): string
    {
        $pdfContent = $this->generate($document)->output();

        // Generate and embed Factur-X XML for eligible B2B invoices
        if ($this->facturXService->isEligible($document)) {
            $xml = $this->facturXService->generateXml($document);

            $document->update([
                'facturx_xml' => $xml,
                'facturx_profile' => FacturXProfile::BasicWL,
                'facturx_generated_at' => now(),
            ]);

            try {
                $pdfContent = $this->facturXPdfGenerator->embedXmlInPdf($pdfContent, $xml);
            } catch (\Throwable $e) {
                // PDF embedding is non-critical; the XML is stored on the document
                // and can be submitted to PDP independently. Log and continue.
                report($e);
            }
        }

        return $pdfContent;
    }

    /**
     * Generate and save PDF to storage.
     */
    public function generateAndSave(Document $document, string $disk = 'local'): string
    {
        $content = $this->generateContent($document);
        $filename = $this->getFilename($document);
        $path = "documents/pdf/{$document->company_id}/{$filename}";

        Storage::disk($disk)->put($path, $content);

        return $path;
    }

    /**
     * Get filename for the document PDF.
     */
    public function getFilename(Document $document): string
    {
        $prefix = $document->type->getPrefix();
        $number = str_replace(['/', '\\', ' '], '-', $document->document_number);

        return "{$prefix}-{$number}.pdf";
    }

    /**
     * Resolve the blade template to use based on document type and country.
     */
    private function resolveTemplate(Document $document): string
    {
        $type = $document->type->value;
        $countryCode = $document->company->country_code;

        // Check for country-specific template first
        $countryTemplate = "documents.country.{$countryCode}.{$type}";
        if (view()->exists($countryTemplate)) {
            return $countryTemplate;
        }

        // Fall back to default template
        return "documents.templates.{$type}";
    }

    /**
     * Prepare data for the PDF template.
     *
     * @return array<string, mixed>
     */
    private function prepareData(Document $document, Company $company): array
    {
        $locale = $company->locale ?? 'en';
        $currency = $document->currency ?? $company->currency;

        return [
            'document' => $document,
            'company' => $company,
            'partner' => $document->partner,
            'lines' => $document->lines,
            'vehicle' => $document->vehicleContext ? (object) $document->vehicleContext->getVehicleSnapshot() : null,
            'locale' => $locale,
            'currency' => $currency,
            'documentTitle' => $this->getDocumentTitle($document->type, $locale),
            'formatMoney' => fn (string|float|null $amount) => $this->formatMoney($amount, $currency, $locale),
            'formatDate' => fn (Carbon|string|null $date) => $this->formatDate($date, $company->date_format, $locale),
            'formatNumber' => fn (string|float|null $number, int $decimals = 2) => $this->formatNumber($number, $decimals, $locale),
        ];
    }

    /**
     * Get localized document title.
     */
    private function getDocumentTitle(DocumentType $type, string $locale): string
    {
        $titles = [
            'en' => [
                DocumentType::Quote->value => 'Quotation',
                DocumentType::SalesOrder->value => 'Sales Order',
                DocumentType::PurchaseOrder->value => 'Purchase Order',
                DocumentType::Invoice->value => 'Invoice',
                DocumentType::CreditNote->value => 'Credit Note',
                DocumentType::DeliveryNote->value => 'Delivery Note',
                DocumentType::ReturnNote->value => 'Return Note',
            ],
            'fr' => [
                DocumentType::Quote->value => 'Devis',
                DocumentType::SalesOrder->value => 'Bon de Commande',
                DocumentType::PurchaseOrder->value => 'Bon de Commande Fournisseur',
                DocumentType::Invoice->value => 'Facture',
                DocumentType::CreditNote->value => 'Avoir',
                DocumentType::DeliveryNote->value => 'Bon de Livraison',
                DocumentType::ReturnNote->value => 'Bon de Retour',
            ],
        ];

        return $titles[$locale][$type->value] ?? $titles['en'][$type->value] ?? $type->label();
    }

    /**
     * Format money amount with currency.
     */
    private function formatMoney(string|float|null $amount, string $currency, string $locale): string
    {
        if ($amount === null) {
            return number_format(0, $this->scale(), '.', '');
        }

        $amount = is_string($amount) ? (float) $amount : $amount;

        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $result = $formatter->formatCurrency($amount, $currency);

        return $result !== false ? $result : number_format($amount, $this->scale()).' '.$currency;
    }

    /**
     * Format date according to company settings.
     */
    private function formatDate(Carbon|string|null $date, string $format, string $locale): string
    {
        if ($date === null) {
            return '';
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        // Use locale-aware formatting
        /** @var Carbon $localizedCarbon */
        $localizedCarbon = $carbon->locale($locale);

        return $localizedCarbon->isoFormat($format);
    }

    /**
     * Format number with locale-specific formatting.
     */
    private function formatNumber(string|float|null $number, int $decimals, string $locale): string
    {
        if ($number === null) {
            return '0';
        }

        $number = is_string($number) ? (float) $number : $number;

        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $decimals);

        $result = $formatter->format($number);

        return $result !== false ? $result : number_format($number, $decimals);
    }
}
