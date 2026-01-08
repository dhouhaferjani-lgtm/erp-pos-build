<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use Illuminate\Support\Collection;

/**
 * TEJ Export Service
 *
 * Generates XML files for Tunisia's TEJ (Transfert et Echange des données fiscales) platform.
 * TEJ is mandatory for withholding tax certificate submission in Tunisia.
 *
 * @see https://tej.finances.gov.tn/tax-file
 */
class TEJExportService
{
    /**
     * Generate TEJ XML for a single certificate.
     */
    public function generateXML(WithholdingCertificate $certificate): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><DeclarationRetenue></DeclarationRetenue>');

        // Declarant (company withholding the tax)
        $this->addDeclarant($xml, $certificate);

        // Period
        $this->addPeriod($xml, $certificate);

        // Retenue (withholding details)
        $this->addRetenue($xml, $certificate);

        return $this->formatXML($xml);
    }

    /**
     * Generate batch TEJ XML for multiple certificates.
     *
     * Used for monthly bulk submissions.
     *
     * @param  Collection<int, WithholdingCertificate>  $certificates
     */
    public function generateBatchXML(Collection $certificates): string
    {
        if ($certificates->isEmpty()) {
            throw new \InvalidArgumentException('Cannot generate XML for empty certificate collection');
        }

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><DeclarationRetenue></DeclarationRetenue>');

        $firstCertificate = $certificates->first();

        // Declarant (same for all certificates in batch)
        $this->addDeclarant($xml, $firstCertificate);

        // Period (month/year from first certificate)
        $this->addPeriod($xml, $firstCertificate);

        // Add multiple retenue entries
        foreach ($certificates as $certificate) {
            $this->addRetenue($xml, $certificate);
        }

        return $this->formatXML($xml);
    }

    /**
     * Add declarant (payer) information to XML.
     */
    private function addDeclarant(\SimpleXMLElement $xml, WithholdingCertificate $certificate): void
    {
        $company = $certificate->company;

        $declarant = $xml->addChild('Declarant');
        $declarant->addChild('MatriculeFiscal', htmlspecialchars((string) $company->tax_id));
        $declarant->addChild('RaisonSociale', htmlspecialchars($company->name));

        if ($company->address) {
            $declarant->addChild('Adresse', htmlspecialchars($company->address));
        }
    }

    /**
     * Add period (month/year) to XML.
     */
    private function addPeriod(\SimpleXMLElement $xml, WithholdingCertificate $certificate): void
    {
        $periode = $xml->addChild('Periode');

        // Use issued_at or created_at for period
        $date = $certificate->issued_at ?? $certificate->created_at;

        $periode->addChild('Mois', $date->format('m'));
        $periode->addChild('Annee', $date->format('Y'));
    }

    /**
     * Add retenue (withholding) details to XML.
     */
    private function addRetenue(\SimpleXMLElement $xml, WithholdingCertificate $certificate): void
    {
        $partner = $certificate->partner;
        $payment = $certificate->payment;

        $retenue = $xml->addChild('Retenue');

        // Beneficiaire (recipient/partner)
        $beneficiaire = $retenue->addChild('Beneficiaire');
        $beneficiaire->addChild(
            'MatriculeFiscal',
            htmlspecialchars((string) ($partner->vat_number ?? $partner->code ?? ''))
        );
        $beneficiaire->addChild('Nom', htmlspecialchars($partner->name));

        // Amounts
        $retenue->addChild('MontantBrut', number_format((float) $certificate->gross_amount, 3, '.', ''));
        $retenue->addChild(
            'TauxRetenue',
            number_format($certificate->getRateAsPercentage(), 2, '.', '')
        );
        $retenue->addChild('MontantRetenu', number_format((float) $certificate->withholding_amount, 3, '.', ''));

        // Payment date
        $paymentDate = $payment?->payment_date ?? $certificate->created_at;
        $retenue->addChild('DatePaiement', $paymentDate->format('Y-m-d'));

        // Certificate number
        $retenue->addChild('NumeroCertificat', htmlspecialchars($certificate->certificate_number));

        // Transaction type if available
        if ($certificate->rule?->transaction_type) {
            $retenue->addChild('TypeOperation', htmlspecialchars($certificate->rule->transaction_type->value));
        }
    }

    /**
     * Format XML with proper indentation.
     */
    private function formatXML(\SimpleXMLElement $xml): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());

        return $dom->saveXML();
    }

    /**
     * Generate filename for TEJ XML export.
     */
    public function generateFilename(WithholdingCertificate $certificate, bool $isBatch = false): string
    {
        $company = $certificate->company;
        $date = $certificate->issued_at ?? $certificate->created_at;

        $prefix = $isBatch ? 'TEJ_BATCH' : 'TEJ';
        $companyCode = str_replace(' ', '_', $company->code ?? $company->id);
        $timestamp = $date->format('Ymd_His');

        return sprintf('%s_%s_%s.xml', $prefix, $companyCode, $timestamp);
    }

    /**
     * Save XML to storage and return path.
     */
    public function saveToStorage(string $xmlContent, string $filename): string
    {
        $directory = storage_path('app/tej-exports');

        if (! file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        $filepath = $directory.'/'.$filename;
        file_put_contents($filepath, $xmlContent);

        return $filepath;
    }
}
