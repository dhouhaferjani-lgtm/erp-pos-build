<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Application\Services\CertificatePDFService;
use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use App\Modules\Taxation\Domain\Enums\CertificateStatus;
use App\Modules\Taxation\Domain\Enums\WithholdingDirection;
use App\Modules\Tenant\Domain\Tenant;
use Barryvdh\DomPDF\PDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test PDF generation for withholding certificates.
 */
class CertificatePDFServiceTest extends TestCase
{
    use RefreshDatabase;

    private CertificatePDFService $pdfService;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdfService = app(CertificatePDFService::class);

        // Create test tenant
        $this->tenant = Tenant::factory()->create();

        // Create test company
        $this->company = Company::factory()->for($this->tenant)->create([
            'name' => 'Test Company SARL',
            'tax_id' => '1234567ABC',
            'country_code' => 'TN',
        ]);

        // Create test partner
        $this->partner = Partner::factory()
            ->for($this->tenant)
            ->for($this->company)
            ->create([
                'name' => 'Test Partner',
                'vat_number' => '7654321XYZ',
                'country_code' => 'TN',
            ]);
    }

    /** @test */
    public function it_generates_qr_code_for_certificate(): void
    {
        $certificate = $this->createCertificate();

        $qrCode = $this->pdfService->generateQRCode($certificate);

        // QR code should be base64 encoded
        $this->assertNotEmpty($qrCode);
        $this->assertTrue(base64_decode($qrCode, true) !== false);

        // Decoded image should be PNG
        $decoded = base64_decode($qrCode);
        $this->assertStringStartsWith("\x89PNG", $decoded);
    }

    /** @test */
    public function it_generates_html_with_certificate_data(): void
    {
        $certificate = $this->createCertificate();

        $html = $this->pdfService->generateHTML($certificate);

        // Check that HTML contains key information
        $this->assertStringContainsString($certificate->certificate_number, $html);
        $this->assertStringContainsString('Test Company SARL', $html);
        $this->assertStringContainsString('Test Partner', $html);
        $this->assertStringContainsString('1,000.000', $html); // gross amount

        // Check the rate: legal artifact must keep fixed two-decimal percent.
        $this->assertStringContainsString('10.00%', $html);

        $this->assertStringContainsString($certificate->hash, $html);

        // Check bilingual headers
        $this->assertStringContainsString('ATTESTATION DE RETENUE', $html);
        $this->assertStringContainsString('WITHHOLDING TAX CERTIFICATE', $html);
    }

    /** @test */
    public function it_generates_pdf_object(): void
    {
        $certificate = $this->createCertificate();

        $pdf = $this->pdfService->generatePDF($certificate);

        // Verify it's a PDF object
        $this->assertInstanceOf(PDF::class, $pdf);

        // Get PDF content
        $content = $pdf->output();

        // PDF should start with %PDF header
        $this->assertStringStartsWith('%PDF', $content);
    }

    /** @test */
    public function it_generates_correct_filename(): void
    {
        $certificate = $this->createCertificate();

        $filename = $this->pdfService->generateFilename($certificate);

        $expectedFilename = sprintf(
            'withholding-certificate-%s-%s.pdf',
            $certificate->certificate_number,
            $certificate->year
        );

        $this->assertEquals($expectedFilename, $filename);
    }

    /** @test */
    public function it_saves_pdf_to_storage(): void
    {
        $certificate = $this->createCertificate();

        $filepath = $this->pdfService->savePDF($certificate);

        // File should exist
        $this->assertFileExists($filepath);

        // File should be a valid PDF
        $content = file_get_contents($filepath);
        $this->assertStringStartsWith('%PDF', $content);

        // Clean up
        @unlink($filepath);
    }

    /** @test */
    public function it_includes_qr_code_in_generated_html(): void
    {
        $certificate = $this->createCertificate();

        $html = $this->pdfService->generateHTML($certificate);

        // HTML should contain QR code image
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('QR Code de vérification', $html);
    }

    /** @test */
    public function it_gets_pdf_content_as_string(): void
    {
        $certificate = $this->createCertificate();

        $content = $this->pdfService->getPDFContent($certificate);

        // Should be valid PDF content
        $this->assertStringStartsWith('%PDF', $content);
        $this->assertNotEmpty($content);
    }

    /**
     * Create a test certificate.
     */
    private function createCertificate(): WithholdingCertificate
    {
        return WithholdingCertificate::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'certificate_number' => 'TEST-2026-001',
            'year' => 2026,
            'reference' => 'REF-001',
            'direction' => WithholdingDirection::PURCHASE,
            'status' => CertificateStatus::ISSUED,
            'currency' => 'TND',
            'gross_amount' => '1000.000',
            'withholding_rate' => '0.100',
            'withholding_amount' => '100.000',
            'net_amount' => '900.000',
            'hash' => hash('sha256', 'test-certificate-data'),
            'chain_sequence' => 1,
            'issued_at' => now(),
        ]);
    }
}
