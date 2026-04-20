<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Application\Services\WithholdingCertificateService;
use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use App\Modules\Taxation\Domain\Entities\WithholdingTaxRule;
use App\Modules\Taxation\Domain\Enums\CertificateStatus;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Enums\WithholdingDirection;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for Withholding Certificate Service
 *
 * Tests the complete certificate lifecycle including:
 * - Creation from payment
 * - Issuing with hash chain
 * - TEJ submission
 * - Voiding
 */
class WithholdingCertificateTest extends TestCase
{
    use RefreshDatabase;

    private WithholdingCertificateService $service;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WithholdingCertificateService::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create(['country_code' => 'TN']);
        $this->partner = Partner::factory()
            ->for($this->tenant)
            ->for($this->company)
            ->create([
                'country_code' => 'TN',
                'tax_status' => PartnerTaxStatus::NON_REGISTERED,
                'withholding_exempt' => false,
            ]);
        $this->user = User::factory()->for($this->tenant)->create();

        $this->actingAs($this->user);
        $this->setCompanyContext($this->company);
    }

    /** @test */
    public function it_creates_certificate_from_payment(): void
    {
        // Arrange
        $rule = $this->createRule('TN_PROF_10', 10.0, null, 'NON_REGISTERED');
        $document = $this->createInvoice('1000.000');
        $payment = $this->createPayment($document, '900.000');

        // Act
        $certificateData = $this->service->createFromPayment($payment, $document);

        // Assert
        $this->assertNotNull($certificateData);
        $this->assertEquals($this->company->id, $certificateData->companyId);
        $this->assertEquals($this->partner->id, $certificateData->partnerId);
        $this->assertEquals('1000.000', $certificateData->grossAmount);
        $this->assertEquals('0.1000', $certificateData->withholdingRate);
        $this->assertEquals('100.000', $certificateData->withholdingAmount);
        $this->assertEquals('900.000', $certificateData->netAmount);
        $this->assertEquals(CertificateStatus::DRAFT, $certificateData->status);

        // Verify database
        $this->assertDatabaseHas('withholding_certificates', [
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'gross_amount' => '1000.000',
            'withholding_amount' => '100.000',
            'status' => CertificateStatus::DRAFT->value,
        ]);
    }

    /** @test */
    public function it_issues_certificate_with_hash_chain(): void
    {
        // Arrange
        $rule = $this->createRule('TN_PROF_10', 10.0, null, 'NON_REGISTERED');
        $document = $this->createInvoice('1000.000');
        $payment = $this->createPayment($document, '900.000');

        $certificateData = $this->service->createFromPayment($payment, $document);

        // Act
        $issuedData = $this->service->issue($certificateData->id, $this->user->id);

        // Assert
        $this->assertEquals(CertificateStatus::ISSUED, $issuedData->status);
        $this->assertNotNull($issuedData->hash);
        $this->assertNull($issuedData->previousHash); // First in chain
        $this->assertEquals(1, $issuedData->chainSequence);
        $this->assertNotNull($issuedData->issuedAt);
        $this->assertEquals($this->user->id, $issuedData->issuedBy);

        // Verify hash chain
        $certificate = WithholdingCertificate::find($certificateData->id);
        $this->assertNotNull($certificate->hash);
        $this->assertTrue($this->service->verifyHashChain(
            $this->company->id,
            WithholdingDirection::PURCHASE->value
        ));
    }

    /** @test */
    public function it_creates_sequential_hash_chain(): void
    {
        // Arrange
        $rule = $this->createRule('TN_PROF_10', 10.0, null, 'NON_REGISTERED');

        // Create and issue first certificate
        $document1 = $this->createInvoice('1000.000');
        $payment1 = $this->createPayment($document1, '900.000');
        $cert1Data = $this->service->createFromPayment($payment1, $document1);
        $cert1Issued = $this->service->issue($cert1Data->id, $this->user->id);

        // Create and issue second certificate
        $document2 = $this->createInvoice('2000.000');
        $payment2 = $this->createPayment($document2, '1800.000');
        $cert2Data = $this->service->createFromPayment($payment2, $document2);
        $cert2Issued = $this->service->issue($cert2Data->id, $this->user->id);

        // Assert
        $this->assertEquals(1, $cert1Issued->chainSequence);
        $this->assertNull($cert1Issued->previousHash);

        $this->assertEquals(2, $cert2Issued->chainSequence);
        $this->assertEquals($cert1Issued->hash, $cert2Issued->previousHash);

        // Verify complete chain integrity
        $this->assertTrue($this->service->verifyHashChain(
            $this->company->id,
            WithholdingDirection::PURCHASE->value
        ));
    }

    /** @test */
    public function it_submits_certificate_to_tej(): void
    {
        // Arrange
        $rule = $this->createRule('TN_PROF_10', 10.0, null, 'NON_REGISTERED');
        $document = $this->createInvoice('1000.000');
        $payment = $this->createPayment($document, '900.000');

        $certificateData = $this->service->createFromPayment($payment, $document);
        $issuedData = $this->service->issue($certificateData->id, $this->user->id);

        // Act
        $submittedData = $this->service->submitToTEJ(
            $issuedData->id,
            'TEJ-2025-123456',
            $this->user->id
        );

        // Assert
        $this->assertEquals(CertificateStatus::SUBMITTED, $submittedData->status);
        $this->assertEquals('TEJ-2025-123456', $submittedData->tejReference);
        $this->assertNotNull($submittedData->tejSubmittedAt);
    }

    /** @test */
    public function it_voids_certificate(): void
    {
        // Arrange
        $rule = $this->createRule('TN_PROF_10', 10.0, null, 'NON_REGISTERED');
        $document = $this->createInvoice('1000.000');
        $payment = $this->createPayment($document, '900.000');

        $certificateData = $this->service->createFromPayment($payment, $document);
        $issuedData = $this->service->issue($certificateData->id, $this->user->id);

        // Act
        $voidedData = $this->service->void(
            $issuedData->id,
            'Payment was cancelled',
            $this->user->id
        );

        // Assert
        $this->assertEquals(CertificateStatus::VOIDED, $voidedData->status);
    }

    /** @test */
    public function it_allows_manual_override(): void
    {
        // Arrange
        $document = $this->createInvoice('1000.000');
        $payment = $this->createPayment($document, '950.000');

        // Act - Override to 5% instead of automatic 10%
        $certificateData = $this->service->createFromPayment(
            $payment,
            $document,
            5.0,
            'Special agreement with supplier'
        );

        // Assert
        $this->assertEquals('1000.000', $certificateData->grossAmount);
        $this->assertEquals('0.0500', $certificateData->withholdingRate);
        $this->assertEquals('50.000', $certificateData->withholdingAmount);
        $this->assertEquals('950.000', $certificateData->netAmount);
        $this->assertEquals('Special agreement with supplier', $certificateData->overrideReason);
        // Manual override doesn't have a rule ID
    }

    /** @test */
    public function it_prevents_issuing_already_issued_certificate(): void
    {
        // Arrange
        $rule = $this->createRule('TN_PROF_10', 10.0, null, 'NON_REGISTERED');
        $document = $this->createInvoice('1000.000');
        $payment = $this->createPayment($document, '900.000');

        $certificateData = $this->service->createFromPayment($payment, $document);
        $this->service->issue($certificateData->id, $this->user->id);

        // Act & Assert
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Certificate cannot be issued in current status');

        $this->service->issue($certificateData->id, $this->user->id);
    }

    /** @test */
    public function it_generates_sequential_certificate_numbers_per_year(): void
    {
        // Arrange
        $rule = $this->createRule('TN_PROF_10', 10.0, null, 'NON_REGISTERED');

        // Create 3 certificates
        for ($i = 1; $i <= 3; $i++) {
            $document = $this->createInvoice('1000.000');
            $payment = $this->createPayment($document, '900.000');
            $certificateData = $this->service->createFromPayment($payment, $document);

            $certificate = WithholdingCertificate::find($certificateData->id);
            $this->assertEquals(sprintf('WHT-%d-%04d', now()->year, $i), $certificate->certificate_number);
        }
    }

    /**
     * Helper: Create a withholding tax rule
     */
    private function createRule(
        string $code,
        float $ratePercentage,
        ?string $transactionType = null,
        ?string $partnerTaxStatus = null
    ): WithholdingTaxRule {
        return WithholdingTaxRule::create([
            'country_code' => 'TN',
            'code' => $code,
            'name' => 'Test Rule '.$code,
            'transaction_type' => $transactionType,
            'partner_tax_status' => $partnerTaxStatus,
            'rate' => $ratePercentage / 100,
            'effective_from' => now()->subYear(),
            'is_active' => true,
        ]);
    }

    /**
     * Helper: Create a test invoice
     */
    private function createInvoice(string $total): Document
    {
        return Document::factory()->for($this->company)->for($this->partner)->create([
            'type' => 'invoice',
            'total' => $total,
            'currency' => 'TND',
            'status' => 'posted',
        ]);
    }

    /**
     * Helper: Create a test payment
     */
    private function createPayment(Document $document, string $amount): Payment
    {
        return Payment::factory()->for($this->company)->create([
            'partner_id' => $this->partner->id,
            'amount' => $amount,
            'currency' => 'TND',
        ]);
    }

    /**
     * Helper: Set company context for tenant-scoped operations
     */
    private function setCompanyContext(Company $company): void
    {
        session(['company_id' => $company->id]);
    }
}
