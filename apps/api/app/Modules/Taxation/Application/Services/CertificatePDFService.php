<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;

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

        return view('taxation.withholding-certificate-pdf', [
            'certificate' => $certificate,
            'company' => $company,
            'partner' => $partner,
            'payment' => $payment,
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
            'company_address' => $company->address,
            'company_country' => $company->country_code,

            // Partner (beneficiary)
            'partner_name' => $partner->name,
            'partner_tax_id' => $partner->vat_number ?? $partner->code,
            'partner_address' => $partner->address ?? 'N/A',

            // Amounts
            'currency' => $certificate->currency,
            'gross_amount' => number_format((float) $certificate->gross_amount, 3, '.', ','),
            'withholding_rate' => $certificate->getRateAsPercentage().'%',
            'withholding_amount' => number_format((float) $certificate->withholding_amount, 3, '.', ','),
            'net_amount' => number_format((float) $certificate->net_amount, 3, '.', ','),

            // Rule info
            'rule_code' => $certificate->rule?->code,
            'rule_name' => $certificate->rule?->name,
            'is_manual_override' => $certificate->isManualOverride(),
            'override_reason' => $certificate->override_reason,

            // Payment info
            'payment_date' => $payment?->payment_date?->format('d/m/Y'),
            'payment_reference' => $payment?->reference,

            // GL account
            'gl_account' => $certificate->getGLAccountCode(),

            // Hash (for verification)
            'hash' => $certificate->hash,
            'chain_sequence' => $certificate->chain_sequence,
        ];
    }

    /**
     * Save PDF to storage and return path.
     *
     * Note: This is a placeholder. Actual PDF generation would require
     * a library like dompdf, mpdf, or similar.
     */
    public function savePDF(WithholdingCertificate $certificate): string
    {
        $directory = storage_path('app/withholding-certificates');

        if (! file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = $this->generateFilename($certificate);
        $filepath = $directory.'/'.$filename;

        // TODO: Implement actual PDF generation
        // For now, just save HTML
        $html = $this->generateHTML($certificate);
        file_put_contents($filepath.'.html', $html);

        return $filepath;
    }
}
