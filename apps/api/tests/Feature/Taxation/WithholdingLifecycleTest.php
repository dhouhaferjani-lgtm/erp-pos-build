<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Application\Services\WithholdingCertificateService;
use App\Modules\Taxation\Application\Services\WithholdingHashChainService;
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
 * End-to-end lifecycle tests for the withholding certificate system.
 *
 * Tests the full flow: payment -> auto-generate certificate -> issue -> verify hash chain.
 */
class WithholdingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private WithholdingCertificateService $certificateService;

    private WithholdingHashChainService $hashChainService;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->certificateService = app(WithholdingCertificateService::class);
        $this->hashChainService = app(WithholdingHashChainService::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create([
            'name' => 'Lifecycle Test Company',
            'tax_id' => 'LT-12345',
            'country_code' => 'TN',
        ]);

        $this->partner = Partner::factory()
            ->for($this->tenant)
            ->for($this->company)
            ->create([
                'name' => 'Lifecycle Partner',
                'country_code' => 'TN',
                'tax_status' => PartnerTaxStatus::NON_REGISTERED,
                'withholding_exempt' => false,
            ]);

        $this->user = User::factory()->for($this->tenant)->create();

        $this->actingAs($this->user);
        session(['company_id' => $this->company->id]);
    }

    /** @test */
    public function it_completes_full_lifecycle_payment_to_certificate_to_hash_chain(): void
    {
        // Step 1: Create withholding rule
        $rule = $this->createRule('TN_SERVICES_15', 15.0);

        // Step 2: Create invoice and payment
        $document = $this->createInvoice('5000.000');
        $payment = $this->createPayment($document, '4250.000');

        // Step 3: Auto-generate certificate from payment
        $certificateData = $this->certificateService->createFromPayment($payment, $document);

        $this->assertNotNull($certificateData);
        $this->assertEquals(CertificateStatus::DRAFT, $certificateData->status);
        $this->assertEquals('5000.000', $certificateData->grossAmount);
        $this->assertEquals('0.1500', $certificateData->withholdingRate);
        $this->assertEquals('750.000', $certificateData->withholdingAmount);
        $this->assertEquals('4250.000', $certificateData->netAmount);
        $this->assertNull($certificateData->hash);

        // Step 4: Issue the certificate (adds to hash chain)
        $issuedData = $this->certificateService->issue($certificateData->id, $this->user->id);

        $this->assertEquals(CertificateStatus::ISSUED, $issuedData->status);
        $this->assertNotNull($issuedData->hash);
        $this->assertNotNull($issuedData->issuedAt);
        $this->assertEquals(1, $issuedData->chainSequence);
        $this->assertNull($issuedData->previousHash);

        // Step 5: Verify hash chain
        $chainValid = $this->certificateService->verifyHashChain(
            $this->company->id,
            WithholdingDirection::PURCHASE->value
        );
        $this->assertTrue($chainValid);

        // Step 6: Verify chain details
        $chainDetails = $this->hashChainService->getChainDetails(
            $this->company->id,
            WithholdingDirection::PURCHASE->value
        );
        $this->assertEquals(1, $chainDetails['total_certificates']);
        $this->assertEquals(1, $chainDetails['last_sequence']);
        $this->assertEquals($issuedData->hash, $chainDetails['last_hash']);
        $this->assertTrue($chainDetails['chain_valid']);
    }

    /** @test */
    public function it_prevents_modification_of_issued_certificate(): void
    {
        $rule = $this->createRule('TN_SERVICES_10', 10.0);

        $document = $this->createInvoice('1000.000');
        $payment = $this->createPayment($document, '900.000');

        $certificateData = $this->certificateService->createFromPayment($payment, $document);
        $this->certificateService->issue($certificateData->id, $this->user->id);

        // Attempt to issue again should fail
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Certificate cannot be issued in current status');

        $this->certificateService->issue($certificateData->id, $this->user->id);
    }

    /** @test */
    public function it_generates_sequential_certificate_numbers_per_year(): void
    {
        $rule = $this->createRule('TN_PROF_10', 10.0);

        $certificateNumbers = [];

        for ($i = 1; $i <= 5; $i++) {
            $document = $this->createInvoice('1000.000');
            $payment = $this->createPayment($document, '900.000');
            $certificateData = $this->certificateService->createFromPayment($payment, $document);

            $certificate = WithholdingCertificate::find($certificateData->id);
            $certificateNumbers[] = $certificate->certificate_number;
        }

        // Verify sequential numbering
        $year = now()->year;
        for ($i = 0; $i < 5; $i++) {
            $expectedNumber = sprintf('WHT-%d-%04d', $year, $i + 1);
            $this->assertEquals($expectedNumber, $certificateNumbers[$i]);
        }
    }

    /** @test */
    public function it_builds_correct_hash_chain_across_multiple_certificates(): void
    {
        $rule = $this->createRule('TN_SERVICES_10', 10.0);

        $issuedCertificates = [];

        // Create and issue 3 certificates
        for ($i = 0; $i < 3; $i++) {
            $document = $this->createInvoice(($i + 1) * 1000 .'.000');
            $payment = $this->createPayment($document, ($i + 1) * 900 .'.000');

            $certData = $this->certificateService->createFromPayment($payment, $document);
            $issuedData = $this->certificateService->issue($certData->id, $this->user->id);
            $issuedCertificates[] = $issuedData;
        }

        // Verify chain structure
        // First certificate has no previous hash
        $this->assertNull($issuedCertificates[0]->previousHash);
        $this->assertEquals(1, $issuedCertificates[0]->chainSequence);

        // Second certificate references first
        $this->assertEquals($issuedCertificates[0]->hash, $issuedCertificates[1]->previousHash);
        $this->assertEquals(2, $issuedCertificates[1]->chainSequence);

        // Third certificate references second
        $this->assertEquals($issuedCertificates[1]->hash, $issuedCertificates[2]->previousHash);
        $this->assertEquals(3, $issuedCertificates[2]->chainSequence);

        // All hashes must be unique
        $hashes = array_map(fn ($c) => $c->hash, $issuedCertificates);
        $this->assertCount(3, array_unique($hashes));

        // Verify full chain integrity
        $this->assertTrue(
            $this->certificateService->verifyHashChain(
                $this->company->id,
                WithholdingDirection::PURCHASE->value
            )
        );
    }

    /** @test */
    public function it_completes_lifecycle_through_tej_submission(): void
    {
        $rule = $this->createRule('TN_SERVICES_10', 10.0);

        $document = $this->createInvoice('2000.000');
        $payment = $this->createPayment($document, '1800.000');

        // Create -> Issue -> Submit to TEJ
        $certData = $this->certificateService->createFromPayment($payment, $document);
        $this->assertEquals(CertificateStatus::DRAFT, $certData->status);

        $issuedData = $this->certificateService->issue($certData->id, $this->user->id);
        $this->assertEquals(CertificateStatus::ISSUED, $issuedData->status);

        $submittedData = $this->certificateService->submitToTEJ(
            $issuedData->id,
            'TEJ-2026-ABC123',
            $this->user->id
        );
        $this->assertEquals(CertificateStatus::SUBMITTED, $submittedData->status);
        $this->assertEquals('TEJ-2026-ABC123', $submittedData->tejReference);
        $this->assertNotNull($submittedData->tejSubmittedAt);

        // Submitted certificate cannot be voided
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Certificate cannot be voided in current status');

        $this->certificateService->void($submittedData->id, 'test reason', $this->user->id);
    }

    /** @test */
    public function it_voids_certificate_and_chain_remains_valid(): void
    {
        $rule = $this->createRule('TN_SERVICES_10', 10.0);

        // Create and issue certificate 1
        $document1 = $this->createInvoice('1000.000');
        $payment1 = $this->createPayment($document1, '900.000');
        $cert1 = $this->certificateService->createFromPayment($payment1, $document1);
        $cert1Issued = $this->certificateService->issue($cert1->id, $this->user->id);

        // Create and issue certificate 2
        $document2 = $this->createInvoice('2000.000');
        $payment2 = $this->createPayment($document2, '1800.000');
        $cert2 = $this->certificateService->createFromPayment($payment2, $document2);
        $cert2Issued = $this->certificateService->issue($cert2->id, $this->user->id);

        // Void certificate 1 (should not break chain since voiding doesn't change hash)
        $voidedData = $this->certificateService->void(
            $cert1Issued->id,
            'Payment cancelled',
            $this->user->id
        );

        $this->assertEquals(CertificateStatus::VOIDED, $voidedData->status);

        // Hash chain should still be valid (voiding doesn't alter hashes)
        $this->assertTrue(
            $this->certificateService->verifyHashChain(
                $this->company->id,
                WithholdingDirection::PURCHASE->value
            )
        );
    }

    /** @test */
    public function it_handles_manual_override_in_lifecycle(): void
    {
        // No rule needed - manual override
        $document = $this->createInvoice('3000.000');
        $payment = $this->createPayment($document, '2850.000');

        // Create with manual 5% override
        $certData = $this->certificateService->createFromPayment(
            $payment,
            $document,
            '5.0',
            'Special tax agreement'
        );

        $this->assertEquals('3000.000', $certData->grossAmount);
        $this->assertEquals('0.0500', $certData->withholdingRate);
        $this->assertEquals('150.000', $certData->withholdingAmount);
        $this->assertEquals('2850.000', $certData->netAmount);
        $this->assertEquals('Special tax agreement', $certData->overrideReason);
        $this->assertNull($certData->withholdingRuleId);

        // Issue with hash chain
        $issuedData = $this->certificateService->issue($certData->id, $this->user->id);

        $this->assertEquals(CertificateStatus::ISSUED, $issuedData->status);
        $this->assertNotNull($issuedData->hash);

        // Chain is valid
        $this->assertTrue(
            $this->certificateService->verifyHashChain(
                $this->company->id,
                WithholdingDirection::PURCHASE->value
            )
        );
    }

    /** @test */
    public function it_maintains_separate_chains_per_direction(): void
    {
        $rule = $this->createRule('TN_SERVICES_10', 10.0);

        // Create and issue a PURCHASE certificate
        $doc1 = $this->createInvoice('1000.000');
        $pay1 = $this->createPayment($doc1, '900.000');
        $purchaseCert = $this->certificateService->createFromPayment($pay1, $doc1);
        $purchaseIssued = $this->certificateService->issue($purchaseCert->id, $this->user->id);

        // Verify purchase chain
        $this->assertEquals(1, $purchaseIssued->chainSequence);
        $this->assertNull($purchaseIssued->previousHash);

        // Create and issue another PURCHASE certificate
        $doc2 = $this->createInvoice('2000.000');
        $pay2 = $this->createPayment($doc2, '1800.000');
        $purchaseCert2 = $this->certificateService->createFromPayment($pay2, $doc2);
        $purchaseIssued2 = $this->certificateService->issue($purchaseCert2->id, $this->user->id);

        // Second purchase cert should chain to first
        $this->assertEquals(2, $purchaseIssued2->chainSequence);
        $this->assertEquals($purchaseIssued->hash, $purchaseIssued2->previousHash);

        // Both chains should be valid
        $this->assertTrue(
            $this->certificateService->verifyHashChain(
                $this->company->id,
                WithholdingDirection::PURCHASE->value
            )
        );
    }

    /**
     * Helper: Create a withholding tax rule
     */
    private function createRule(string $code, float $ratePercentage): WithholdingTaxRule
    {
        return WithholdingTaxRule::create([
            'country_code' => 'TN',
            'code' => $code,
            'name' => 'Test Rule '.$code,
            'transaction_type' => null,
            'partner_tax_status' => 'NON_REGISTERED',
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
}
