<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Application\Services\TEJExportService;
use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use App\Modules\Taxation\Domain\Entities\WithholdingTaxRule;
use App\Modules\Taxation\Domain\Enums\CertificateStatus;
use App\Modules\Taxation\Domain\Enums\WithholdingDirection;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for TEJ Export Service
 *
 * Tests XML generation for Tunisia's TEJ platform for withholding tax certificates.
 */
class TEJExportServiceTest extends TestCase
{
    use RefreshDatabase;

    private TEJExportService $service;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TEJExportService::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create([
            'name' => 'Société Test SARL',
            'tax_id' => '1234567/A/B/C/000',
            'country_code' => 'TN',
        ]);

        $this->partner = Partner::factory()
            ->for($this->tenant)
            ->for($this->company)
            ->create([
                'name' => 'Fournisseur ABC',
                'vat_number' => '9876543/X/Y/Z/000',
                'country_code' => 'TN',
            ]);
    }

    /** @test */
    public function it_generates_single_certificate_xml(): void
    {
        $certificate = $this->createCertificate();

        $xml = $this->service->generateXML($certificate);

        $this->assertNotEmpty($xml);
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('<DeclarationRetenue>', $xml);
        $this->assertStringContainsString('<Declarant>', $xml);
        $this->assertStringContainsString('<Retenue>', $xml);

        // Company info
        $this->assertStringContainsString('<MatriculeFiscal>1234567/A/B/C/000</MatriculeFiscal>', $xml);
        $this->assertStringContainsString('<RaisonSociale>Société Test SARL</RaisonSociale>', $xml);

        // Partner info
        $this->assertStringContainsString('<Nom>Fournisseur ABC</Nom>', $xml);

        // Amounts
        $this->assertStringContainsString('<MontantBrut>1000.000</MontantBrut>', $xml);
        $this->assertStringContainsString('<TauxRetenue>10.00</TauxRetenue>', $xml);
        $this->assertStringContainsString('<MontantRetenu>100.000</MontantRetenu>', $xml);

        // Certificate number
        $this->assertStringContainsString('<NumeroCertificat>WHT-2026-0001</NumeroCertificat>', $xml);

        // Period
        $this->assertStringContainsString('<Periode>', $xml);
        $this->assertStringContainsString('<Annee>', $xml);

        // Validate it's well-formed XML
        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml));
    }

    /** @test */
    public function it_generates_batch_xml_for_multiple_certificates(): void
    {
        $certificates = collect();

        for ($i = 1; $i <= 3; $i++) {
            $certificates->push($this->createCertificate(
                certificateNumber: sprintf('WHT-2026-%04d', $i),
                grossAmount: (string) ($i * 1000).'.000',
                withholdingAmount: (string) ($i * 100).'.000',
                netAmount: (string) ($i * 900).'.000',
            ));
        }

        $xml = $this->service->generateBatchXML($certificates);

        $this->assertNotEmpty($xml);
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);

        // Should have one Declarant
        $this->assertEquals(1, substr_count($xml, '<Declarant>'));

        // Should have 3 Retenue entries
        $this->assertEquals(3, substr_count($xml, '<Retenue>'));

        // Check all certificate numbers present
        $this->assertStringContainsString('WHT-2026-0001', $xml);
        $this->assertStringContainsString('WHT-2026-0002', $xml);
        $this->assertStringContainsString('WHT-2026-0003', $xml);

        // Validate it's well-formed XML
        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml));
    }

    /** @test */
    public function it_escapes_xml_special_characters(): void
    {
        // Create partner with special characters in name
        $partnerWithSpecialChars = Partner::factory()
            ->for($this->tenant)
            ->for($this->company)
            ->create([
                'name' => 'Test & Sons <Imports> "Ltd"',
                'vat_number' => '1111111/A/B/C/000',
                'country_code' => 'TN',
            ]);

        $certificate = $this->createCertificate(partner: $partnerWithSpecialChars);

        $xml = $this->service->generateXML($certificate);

        // Verify XML is well-formed (would fail if special chars not escaped)
        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml));

        // Verify special chars are escaped
        $this->assertStringNotContainsString('<Imports>', $xml);
        $this->assertStringContainsString('&amp;', $xml);
    }

    /** @test */
    public function it_throws_exception_for_empty_batch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot generate XML for empty certificate collection');

        $this->service->generateBatchXML(collect());
    }

    /** @test */
    public function it_includes_period_from_issued_date(): void
    {
        $issuedAt = now()->setMonth(6)->setYear(2026);

        $certificate = $this->createCertificate();
        $certificate->update(['issued_at' => $issuedAt]);
        $certificate->refresh();

        $xml = $this->service->generateXML($certificate);

        $this->assertStringContainsString('<Mois>06</Mois>', $xml);
        $this->assertStringContainsString('<Annee>2026</Annee>', $xml);
    }

    /** @test */
    public function it_generates_correct_filename_for_single_certificate(): void
    {
        $certificate = $this->createCertificate();

        $filename = $this->service->generateFilename($certificate);

        $this->assertStringStartsWith('TEJ_', $filename);
        $this->assertStringEndsWith('.xml', $filename);
        $this->assertStringNotContainsString('BATCH', $filename);
    }

    /** @test */
    public function it_generates_correct_filename_for_batch(): void
    {
        $certificate = $this->createCertificate();

        $filename = $this->service->generateFilename($certificate, true);

        $this->assertStringStartsWith('TEJ_BATCH_', $filename);
        $this->assertStringEndsWith('.xml', $filename);
    }

    /** @test */
    public function it_saves_xml_to_storage(): void
    {
        $certificate = $this->createCertificate();
        $xml = $this->service->generateXML($certificate);
        $filename = $this->service->generateFilename($certificate);

        $filepath = $this->service->saveToStorage($xml, $filename);

        $this->assertFileExists($filepath);

        $savedContent = file_get_contents($filepath);
        $this->assertEquals($xml, $savedContent);

        // Clean up
        @unlink($filepath);
    }

    /** @test */
    public function it_includes_transaction_type_when_rule_has_one(): void
    {
        $rule = WithholdingTaxRule::create([
            'country_code' => 'TN',
            'code' => 'TN_SERVICES_10',
            'name' => 'Services Withholding',
            'transaction_type' => 'services',
            'rate' => '0.1000',
            'effective_from' => now()->subYear(),
            'is_active' => true,
        ]);

        $certificate = $this->createCertificate(ruleId: $rule->id);

        $xml = $this->service->generateXML($certificate);

        $this->assertStringContainsString('<TypeOperation>services</TypeOperation>', $xml);
    }

    /**
     * Create a test certificate.
     */
    private function createCertificate(
        string $certificateNumber = 'WHT-2026-0001',
        string $grossAmount = '1000.000',
        string $withholdingAmount = '100.000',
        string $netAmount = '900.000',
        ?Partner $partner = null,
        ?string $ruleId = null,
    ): WithholdingCertificate {
        $document = Document::factory()
            ->for($this->company)
            ->for($partner ?? $this->partner)
            ->create([
                'type' => 'invoice',
                'total' => $grossAmount,
                'currency' => 'TND',
                'status' => 'posted',
            ]);

        $payment = Payment::factory()
            ->for($this->company)
            ->create([
                'partner_id' => ($partner ?? $this->partner)->id,
                'amount' => $netAmount,
                'currency' => 'TND',
            ]);

        $certificate = WithholdingCertificate::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => ($partner ?? $this->partner)->id,
            'document_id' => $document->id,
            'payment_id' => $payment->id,
            'certificate_number' => $certificateNumber,
            'year' => 2026,
            'direction' => WithholdingDirection::PURCHASE,
            'status' => CertificateStatus::ISSUED,
            'currency' => 'TND',
            'gross_amount' => $grossAmount,
            'withholding_rate' => '0.1000',
            'withholding_amount' => $withholdingAmount,
            'net_amount' => $netAmount,
            'withholding_rule_id' => $ruleId,
            'hash' => hash('sha256', 'test-'.$certificateNumber),
            'chain_sequence' => 1,
            'issued_at' => now(),
        ]);

        // Reload with relationships
        $certificate->load(['company', 'partner', 'payment', 'rule']);

        return $certificate;
    }
}
