<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Exporters;

use App\Modules\Taxation\Domain\Contracts\VatExporterInterface;
use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TEIF XML Exporter for Tunisia El Fatoora.
 *
 * Produces an XML document conforming to the Tunisian electronic invoicing format.
 * This is a VAT summary submission — individual e-invoices are handled separately.
 */
class TeifXmlExporter implements VatExporterInterface
{
    public function supports(VatExportFormat $format): bool
    {
        return $format === VatExportFormat::TeifXml;
    }

    public function export(VatSummary $summary, VatDeclarationData $declaration): StreamedResponse
    {
        $xml = $this->buildXml($summary, $declaration);

        $callback = static function () use ($xml): void {
            echo $xml;
        };

        return new StreamedResponse($callback, 200, [
            'Content-Type' => $this->getContentType(),
            'Content-Disposition' => 'attachment',
        ]);
    }

    public function getContentType(): string
    {
        return 'application/xml';
    }

    public function getFilename(VatPeriod $period): string
    {
        $start = $period->period_start->format('Ymd');
        $end = $period->period_end->format('Ymd');

        return "vat_declaration_TN_{$start}_{$end}.xml";
    }

    private function buildXml(VatSummary $summary, VatDeclarationData $declaration): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('DeclarationTVA');
        $root->setAttribute('xmlns', 'urn:tn:gov:dgfiscale:vat:declaration:v1');
        $root->setAttribute('version', '1.0');
        $dom->appendChild($root);

        // Form reference
        $formRef = $dom->createElement('FormReference', $declaration->formReference);
        $root->appendChild($formRef);

        // Generation timestamp
        $generated = $dom->createElement('GeneratedAt', date('Y-m-d\TH:i:s'));
        $root->appendChild($generated);

        // Output VAT section
        $outputSection = $dom->createElement('TVACollectee');
        foreach ($summary->outputBreakdowns as $breakdown) {
            $entry = $dom->createElement('Ligne');
            $entry->appendChild($dom->createElement('Taux', $breakdown->taxRate));
            $entry->appendChild($dom->createElement('BaseHT', $breakdown->baseAmount));
            $entry->appendChild($dom->createElement('MontantTVA', $breakdown->vatAmount));
            $entry->appendChild($dom->createElement('NombreDocuments', (string) $breakdown->documentCount));
            $outputSection->appendChild($entry);
        }
        $outputSection->appendChild($dom->createElement('TotalTVA', $summary->totalOutputVat));
        $root->appendChild($outputSection);

        // Input VAT section
        $inputSection = $dom->createElement('TVADeductible');
        foreach ($summary->inputBreakdowns as $breakdown) {
            $entry = $dom->createElement('Ligne');
            $entry->appendChild($dom->createElement('Taux', $breakdown->taxRate));
            $entry->appendChild($dom->createElement('BaseHT', $breakdown->baseAmount));
            $entry->appendChild($dom->createElement('MontantTVA', $breakdown->vatAmount));
            $entry->appendChild($dom->createElement('NombreDocuments', (string) $breakdown->documentCount));
            $entry->appendChild($dom->createElement('Recuperable', $breakdown->isRecoverable ? 'oui' : 'non'));
            $inputSection->appendChild($entry);
        }
        $inputSection->appendChild($dom->createElement('TotalTVA', $summary->totalInputVat));
        $root->appendChild($inputSection);

        // Declaration fields
        $fieldsSection = $dom->createElement('ChampsDeclaration');
        foreach ($declaration->fields as $key => $value) {
            $field = $dom->createElement('Champ');
            $field->setAttribute('code', (string) $key);
            $field->appendChild($dom->createTextNode((string) $value));
            $fieldsSection->appendChild($field);
        }
        $root->appendChild($fieldsSection);

        $xmlString = $dom->saveXML();

        return $xmlString !== false ? $xmlString : '';
    }
}
