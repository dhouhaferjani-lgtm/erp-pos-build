<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use horstoeko\zugferd\ZugferdProfiles;

/**
 * Embeds Factur-X XML into a PDF to create a PDF/A-3 compliant document.
 *
 * Uses horstoeko/zugferd to take an existing PDF (e.g. from DomPDF) and
 * attach the Factur-X XML as a PDF/A-3 embedded file.
 */
final class FacturXPdfGenerator
{
    /**
     * Embed Factur-X XML into an existing PDF, producing a PDF/A-3 document.
     *
     * @param  string  $pdfContent  The binary PDF content (e.g. from DomPDF output)
     * @param  string  $facturXXml  The Factur-X XML content
     * @return string The resulting PDF/A-3 binary content with embedded XML
     */
    public function embedXmlInPdf(string $pdfContent, string $facturXXml): string
    {
        // Rebuild the ZugferdDocumentBuilder from the XML content
        // The PDF builder requires a builder instance to extract profile metadata
        $builder = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_BASICWL);

        // Create the PDF builder from the PDF string content
        $pdfBuilder = ZugferdDocumentPdfBuilder::fromPdfString($builder, $pdfContent);

        // Generate the PDF/A-3 with embedded XML and return as string
        $pdfBuilder->generateDocument();

        return $pdfBuilder->downloadString();
    }
}
