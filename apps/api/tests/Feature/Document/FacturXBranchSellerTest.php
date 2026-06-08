<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Application\Services\FacturXService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacturXBranchSellerTest extends TestCase
{
    use RefreshDatabase;

    public function test_facturx_seller_uses_branch_identity(): void
    {
        $document = $this->makeDocumentWithLocationIdentity(
            branchVatNumber: 'BRANCH-VA',
            branchTaxId: 'BRANCH-FC',
            branchSiret: 'BRANCH-SIRET',
        );

        $xml = $this->app->make(FacturXService::class)->generateXml($document);

        $this->assertStringContainsString('BRANCH-VA', $xml);
        $this->assertStringContainsString('BRANCH-FC', $xml);
        $this->assertStringContainsString('BRANCH-SIRET', $xml);
        $this->assertStringNotContainsString('COMPANY-FC', $xml);
    }

    public function test_facturx_seller_falls_back_to_company_identity_when_branch_identity_is_null(): void
    {
        $document = $this->makeDocumentWithLocationIdentity(
            branchVatNumber: null,
            branchTaxId: null,
            branchSiret: null,
        );

        $xml = $this->app->make(FacturXService::class)->generateXml($document);

        $this->assertStringContainsString('COMPANY-VA', $xml);
        $this->assertStringContainsString('COMPANY-FC', $xml);
        $this->assertStringContainsString('COMPANY-SIRET', $xml);
    }

    private function makeDocumentWithLocationIdentity(?string $branchVatNumber, ?string $branchTaxId, ?string $branchSiret): Document
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Seller Company',
            'legal_name' => 'Seller Company SARL',
            'country_code' => 'FR',
            'vat_number' => 'COMPANY-VA',
            'tax_id' => 'COMPANY-FC',
            'legal_identifiers' => ['siret' => 'COMPANY-SIRET'],
        ]);
        $location = Location::factory()->create([
            'company_id' => $company->id,
            'vat_number' => $branchVatNumber,
            'tax_id' => $branchTaxId,
            'legal_identifiers' => $branchSiret === null ? null : ['siret' => $branchSiret],
        ]);
        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'vat_number' => 'FR12345678901',
            'country_code' => 'FR',
        ]);
        $document = Document::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
        ]);
        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Service A',
            'quantity' => '1.0000',
            'unit_price' => '100.000',
            'tax_rate' => '20.00',
            'line_total' => '100.000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'allocated_costs' => '0.000000',
        ]);

        return $document;
    }
}
