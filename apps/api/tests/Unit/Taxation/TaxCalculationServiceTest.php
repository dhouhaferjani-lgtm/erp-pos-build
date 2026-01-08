<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaxCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TaxCalculationService;
    }

    /** @test */
    public function it_calculates_single_percentage_tax(): void
    {
        // Setup: Create company, partner, tax config
        $company = Company::factory()->create(['country_code' => 'TN']);
        $partner = Partner::factory()->create([
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        $taxConfig = TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'TVA 19%',
            'code' => 'TVA_19',
            'tax_type' => 'PERCENTAGE',
            'percentage_rate' => '19.00',
            'applies_to' => 'LINE_ITEMS',
            'sequence_order' => 1,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => [],
            'is_active' => true,
            'is_stamp_duty' => false,
        ]);

        $document = Document::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
        ]);

        DocumentLine::factory()->create([
            'document_id' => $document->id,
            'quantity' => '1',
            'unit_price' => '100.000',
            'tax_rate' => '19.00',
        ]);

        // Execute
        $result = $this->service->calculateDocumentTaxes($document);

        // Assert
        $this->assertCount(1, $result->taxes);
        $this->assertEquals('100.000', $result->subtotal);
        $this->assertEquals('19.000', $result->lineItemsTaxTotal);
        $this->assertEquals('0', $result->documentTaxTotal);
        $this->assertEquals('19.000', $result->totalTax);
        $this->assertEquals('119.000', $result->total);
    }

    /** @test */
    public function it_calculates_fixed_amount_tax(): void
    {
        // Setup
        $company = Company::factory()->create(['country_code' => 'TN']);
        $partner = Partner::factory()->create([
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        $stampDuty = TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'Stamp Duty',
            'code' => 'STAMP_1',
            'tax_type' => 'FIXED_AMOUNT',
            'fixed_amount' => '1.000',
            'applies_to' => 'DOCUMENT_TOTAL',
            'sequence_order' => 99,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => [],
            'is_active' => true,
            'is_stamp_duty' => true,
        ]);

        $document = Document::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
        ]);

        DocumentLine::factory()->create([
            'document_id' => $document->id,
            'quantity' => '1',
            'unit_price' => '100.000',
            'tax_rate' => '0',
        ]);

        // Execute
        $result = $this->service->calculateDocumentTaxes($document);

        // Assert
        $this->assertCount(1, $result->taxes);
        $this->assertEquals('100.000', $result->subtotal);
        $this->assertEquals('0', $result->lineItemsTaxTotal);
        $this->assertEquals('1.000', $result->documentTaxTotal);
        $this->assertEquals('1.000', $result->totalTax);
        $this->assertEquals('101.000', $result->total);
        $this->assertTrue($result->taxes[0]->isStampDuty);
    }

    /** @test */
    public function it_returns_exemption_info_for_exempt_partner(): void
    {
        // Setup
        $company = Company::factory()->create(['country_code' => 'TN']);
        $partner = Partner::factory()->create([
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::EXEMPT,
            'tax_exemption_reason' => 'Government entity',
        ]);

        $document = Document::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
        ]);

        DocumentLine::factory()->create([
            'document_id' => $document->id,
            'quantity' => '1',
            'unit_price' => '100.000',
        ]);

        // Execute
        $result = $this->service->calculateDocumentTaxes($document);

        // Assert
        $this->assertNotNull($result->exemptionInfo);
        $this->assertEquals('EXEMPT', $result->exemptionInfo['status']);
        $this->assertEquals('Government entity', $result->exemptionInfo['reason']);
        $this->assertFalse($result->exemptionInfo['hasValidCertificate']);
        $this->assertNotEmpty($result->exemptionInfo['warnings']);
    }
}
