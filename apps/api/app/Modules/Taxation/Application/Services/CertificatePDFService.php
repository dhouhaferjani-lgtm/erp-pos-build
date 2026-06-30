<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use App\Shared\Domain\CurrencyScale;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Response;

/**
 * Certificate PDF Service
 *
 * Generates PDF certificates for withholding tax.
 * Used for non-TEJ countries or as backup documentation.
 */
class CertificatePDFService
{
    /**
     * Generate PDF content for a certificate.
     *
     * Returns HTML that can be converted to PDF.
     */
    public function generateHTML(WithholdingCertificate $certificate): string
    {
        $company = $certificate->company;
        $partner = $certificate->partner;
        $payment = $certificate->payment;
        $qrCode = $this->generateQRCode($certificate);

        return view('taxation.withholding-certificate-pdf', [
            'certificate' => $certificate,
            'company' => $company,
            'partner' => $partner,
            'payment' => $payment,
            'qrCode' => $qrCode,
        ])->render();
    }

    /**
     * Generate filename for PDF.
     */
    public function generateFilename(WithholdingCertificate $certificate): string
    {
        return sprintf(
            'withholding-certificate-%s-%s.pdf',
            $certificate->certificate_number,
            $certificate->year
        );
    }

    /**
     * Get certificate data for PDF generation.
     *
     * @return array<string, mixed>
     */
    public function getCertificateData(WithholdingCertificate $certificate): array
    {
        $company = $certificate->company;
        $partner = $certificate->partner;

        return [
            // Certificate info
            'certificate_number' => $certificate->certificate_number,
            'year' => $certificate->year,
            'reference' => $certificate->getReference(),
            'issued_at' => $certificate->issued_at?->format('d/m/Y'),
            'direction' => $certificate->direction->label(),

            // Company (withholder)
            'company_name' => $company->name,
            'company_tax_id' => $company->tax_id,
            'company_address' => $company->getFullAddressAttribute(),
            'company_country' => $company->country_code,

            // Partner (beneficiary)
            'partner_name' => $partner->name,
            'partner_tax_id' => $partner->vat_number ?? $partner->code,
            'partner_address' => $partner->address ?? 'N/A',

            // Amounts — use bcmath to normalise source decimals before any display
            // formatting.  The (float) cast below operates on the already-bcmath-rounded
            // string (3 dp, well within float64 precision) for thousands grouping only.
            // The SOURCE decimal column is NEVER cast directly to float.
            'currency' => $certificate->currency,
            'gross_amount' => number_format(
                (float) CurrencyScale::bcformat((string) $certificate->gross_amount, 3),
                3, '.', ','
            ),
            'withholding_rate' => $certificate->getRateAsPercentage().'%',
            'withholding_amount' => number_format(
                (float) CurrencyScale::bcformat((string) $certificate->withholding_amount, 3),
                3, '.', ','
            ),
            'net_amount' => number_format(
                (float) CurrencyScale::bcformat((string) $certificate->net_amount, 3),
                3, '.', ','
            ),

            // Rule info
            'rule_code' => $certificate->rule?->code,
            'rule_name' => $certificate->rule?->name,
            'is_manual_override' => $certificate->isManualOverride(),
            'override_reason' => $certificate->override_reason,

            // Payment info
            'payment_date' => $certificate->payment?->payment_date?->format('d/m/Y'),
            'payment_reference' => $certificate->payment?->reference,

            // GL account
            'gl_account' => $certificate->getGLAccountCode(),

            // Hash (for verification)
            'hash' => $certificate->hash,
            'chain_sequence' => $certificate->chain_sequence,
        ];
    }

    /**
     * Generate QR code for certificate verification.
     *
     * QR code contains certificate ID, number, hash, and verification URL.
     */
    public function generateQRCode(WithholdingCertificate $certificate): string
    {
        $verificationData = [
            'certificate_number' => $certificate->certificate_number,
            'year' => $certificate->year,
            'hash' => $certificate->hash,
            'chain_sequence' => $certificate->chain_sequence,
            'issued_at' => $certificate->issued_at?->format('Y-m-d'),
        ];

        $data = json_encode($verificationData) ?: '{}';

        $qrCode = new QrCode($data);
        $writer = new PngWriter;
        $result = $writer->write($qrCode);

        return base64_encode($result->getString());
    }

    /**
     * Generate PDF and return the PDF object.
     */
    public function generatePDF(WithholdingCertificate $certificate): \Barryvdh\DomPDF\PDF
    {
        $html = $this->generateHTML($certificate);

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'Arial')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isHtml5ParserEnabled', true);
    }

    /**
     * Save PDF to storage and return path.
     */
    public function savePDF(WithholdingCertificate $certificate): string
    {
        $directory = storage_path('app/withholding-certificates');

        if (! file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = $this->generateFilename($certificate);
        $filepath = $directory.'/'.$filename;

        $pdf = $this->generatePDF($certificate);
        $pdf->save($filepath);

        return $filepath;
    }

    /**
     * Stream PDF to browser for download.
     */
    public function streamPDF(WithholdingCertificate $certificate): Response
    {
        $filename = $this->generateFilename($certificate);
        $pdf = $this->generatePDF($certificate);

        return $pdf->download($filename);
    }

    /**
     * Get PDF content as string.
     */
    public function getPDFContent(WithholdingCertificate $certificate): string
    {
        $pdf = $this->generatePDF($certificate);

        return $pdf->output();
    }
}
