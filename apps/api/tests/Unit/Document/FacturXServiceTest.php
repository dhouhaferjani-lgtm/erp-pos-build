<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Application\Services\FacturXService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FacturXProfile;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FacturXServiceTest extends TestCase
{
    use RefreshDatabase;

    private FacturXService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FacturXService();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-facturx-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    public function test_is_eligible_returns_true_for_french_b2b_invoice(): void
    {
        $document = $this->createEligibleDocument();

        $this->assertTrue($this->service->isEligible($document));
    }

    public function test_is_eligible_returns_false_for_non_french_company(): void
    {
        $company = $this->createCompany(['country_code' => 'TN']);
        $partner = $this->createPartner($company, ['vat_number' => 'TN123456789']);
        $document = $this->createInvoice($company, $partner);

        $this->assertFalse($this->service->isEligible($document));
    }

    public function test_is_eligible_returns_false_for_partner_without_vat_number(): void
    {
        $company = $this->createCompany(['country_code' => 'FR']);
        $partner = $this->createPartner($company, ['vat_number' => null]);
        $document = $this->createInvoice($company, $partner);

        $this->assertFalse($this->service->isEligible($document));
    }

    public function test_is_eligible_returns_false_for_partner_with_empty_vat_number(): void
    {
        $company = $this->createCompany(['country_code' => 'FR']);
        $partner = $this->createPartner($company, ['vat_number' => '']);
        $document = $this->createInvoice($company, $partner);

        $this->assertFalse($this->service->isEligible($document));
    }

    public function test_is_eligible_returns_false_for_non_invoice_types(): void
    {
        $nonInvoiceTypes = [
            DocumentType::Quote,
            DocumentType::SalesOrder,
            DocumentType::PurchaseOrder,
            DocumentType::CreditNote,
            DocumentType::DeliveryNote,
        ];

        foreach ($nonInvoiceTypes as $type) {
            $document = $this->createEligibleDocument(['type' => $type]);
            $this->assertFalse(
                $this->service->isEligible($document),
                "Expected isEligible to return false for {$type->value}"
            );
        }
    }

    public function test_is_eligible_returns_false_when_facturx_xml_already_exists(): void
    {
        $document = $this->createEligibleDocument([
            'facturx_xml' => '<xml>already generated</xml>',
        ]);

        $this->assertFalse($this->service->isEligible($document));
    }

    public function test_get_profile_returns_basic_wl(): void
    {
        $this->assertSame(FacturXProfile::BasicWL, $this->service->getProfile());
    }

    public function test_generate_xml_produces_valid_xml_string(): void
    {
        $document = $this->createEligibleDocument();

        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Service A',
            'quantity' => '2.000',
            'unit_price' => '50.000',
            'tax_rate' => '20.000',
            'line_total' => '100.000',
            'quantity_delivered' => '0.000',
            'quantity_received' => '0.000',
            'allocated_costs' => '0.000',
        ]);

        $xml = $this->service->generateXml($document);

        $this->assertNotEmpty($xml);
        $this->assertStringContainsString('<?xml', $xml);
        $this->assertStringContainsString($document->document_number, $xml);
    }

    public function test_generate_xml_contains_seller_information(): void
    {
        $document = $this->createEligibleDocument();
        $company = $document->company;

        $xml = $this->service->generateXml($document);

        $this->assertStringContainsString($company->name, $xml);
    }

    public function test_generate_xml_contains_buyer_information(): void
    {
        $document = $this->createEligibleDocument();
        $partner = $document->partner;

        $xml = $this->service->generateXml($document);

        $this->assertStringContainsString($partner->name, $xml);
    }

    public function test_generate_xml_contains_tax_information_for_lines_with_vat(): void
    {
        $document = $this->createEligibleDocument();

        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Item with VAT',
            'quantity' => '1.000',
            'unit_price' => '100.000',
            'tax_rate' => '20.000',
            'line_total' => '100.000',
            'quantity_delivered' => '0.000',
            'quantity_received' => '0.000',
            'allocated_costs' => '0.000',
        ]);

        $xml = $this->service->generateXml($document);

        $this->assertStringContainsString('VAT', $xml);
    }

    public function test_generate_xml_groups_lines_by_tax_rate(): void
    {
        $document = $this->createEligibleDocument();

        // Two lines with 20% VAT
        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Item A',
            'quantity' => '1.000',
            'unit_price' => '50.000',
            'tax_rate' => '20.000',
            'line_total' => '50.000',
            'quantity_delivered' => '0.000',
            'quantity_received' => '0.000',
            'allocated_costs' => '0.000',
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 2,
            'description' => 'Item B',
            'quantity' => '1.000',
            'unit_price' => '50.000',
            'tax_rate' => '20.000',
            'line_total' => '50.000',
            'quantity_delivered' => '0.000',
            'quantity_received' => '0.000',
            'allocated_costs' => '0.000',
        ]);

        // One line with 0% (zero-rated)
        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 3,
            'description' => 'Zero-rated item',
            'quantity' => '1.000',
            'unit_price' => '30.000',
            'tax_rate' => '0.000',
            'line_total' => '30.000',
            'quantity_delivered' => '0.000',
            'quantity_received' => '0.000',
            'allocated_costs' => '0.000',
        ]);

        $xml = $this->service->generateXml($document);

        $this->assertNotEmpty($xml);
        $this->assertStringContainsString('VAT', $xml);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCompany(array $overrides = []): Company
    {
        $suffix = random_int(10000, 99999);

        return Company::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => "Test Company {$suffix}",
            'legal_name' => "Test Company {$suffix} SAS",
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'primary_color' => '#2563eb',
            'fiscal_year_start_month' => 1,
            'invoice_prefix' => 'INV-',
            'invoice_next_number' => 1,
            'quote_prefix' => 'QUO-',
            'quote_next_number' => 1,
            'sales_order_prefix' => 'SO-',
            'sales_order_next_number' => 1,
            'purchase_order_prefix' => 'PO-',
            'purchase_order_next_number' => 1,
            'delivery_note_prefix' => 'DN-',
            'delivery_note_next_number' => 1,
            'receipt_prefix' => 'REC-',
            'receipt_next_number' => 1,
            'vat_number' => "FR{$suffix}01",
            'tax_id' => (string) $suffix,
            'address_street' => '10 Rue de la Paix',
            'address_city' => 'Paris',
            'address_postal_code' => '75002',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPartner(Company $company, array $overrides = []): Partner
    {
        $suffix = random_int(10000, 99999);

        return Partner::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => "Test Partner {$suffix}",
            'type' => PartnerType::Customer,
            'vat_number' => "FR{$suffix}09",
            'street_address' => '20 Avenue des Champs-Elysees',
            'city' => 'Paris',
            'postal_code' => '75008',
            'country_code' => 'FR',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createInvoice(Company $company, Partner $partner, array $overrides = []): Document
    {
        return Document::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'status' => \App\Modules\Document\Domain\Enums\DocumentStatus::Draft,
            'fiscal_category' => \App\Modules\Document\Domain\Enums\FiscalCategory::TaxInvoice,
            'fiscal_status' => \App\Modules\Document\Domain\Enums\FiscalStatus::Draft,
            'document_number' => 'INV-' . random_int(10000, 99999),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.000',
            'tax_amount' => '20.000',
            'total' => '120.000',
            'balance_due' => '120.000',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createEligibleDocument(array $overrides = []): Document
    {
        $company = $this->createCompany();
        $partner = $this->createPartner($company);

        return $this->createInvoice($company, $partner, $overrides);
    }
}
