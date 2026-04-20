<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Application\Services\FacturXService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FacturXProfile;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FacturXEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private FacturXService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FacturXService;

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-eligibility-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    public function test_credit_notes_are_not_eligible(): void
    {
        $document = $this->createDocument([
            'type' => DocumentType::CreditNote,
        ]);

        $this->assertFalse($this->service->isEligible($document));
    }

    public function test_already_processed_documents_are_not_eligible(): void
    {
        $document = $this->createDocument([
            'facturx_xml' => '<xml>existing xml</xml>',
            'facturx_profile' => FacturXProfile::BasicWL,
            'facturx_generated_at' => now(),
        ]);

        $this->assertFalse($this->service->isEligible($document));
    }

    public function test_draft_invoices_are_eligible_if_other_criteria_met(): void
    {
        $document = $this->createDocument([
            'status' => DocumentStatus::Draft,
        ]);

        $this->assertTrue($this->service->isEligible($document));
    }

    public function test_posted_invoices_are_eligible(): void
    {
        $document = $this->createDocument([
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
        ]);

        $this->assertTrue($this->service->isEligible($document));
    }

    public function test_confirmed_invoices_are_eligible(): void
    {
        $document = $this->createDocument([
            'status' => DocumentStatus::Confirmed,
        ]);

        $this->assertTrue($this->service->isEligible($document));
    }

    public function test_expense_type_is_not_eligible(): void
    {
        $document = $this->createDocument([
            'type' => DocumentType::Expense,
        ]);

        $this->assertFalse($this->service->isEligible($document));
    }

    public function test_return_note_is_not_eligible(): void
    {
        $document = $this->createDocument([
            'type' => DocumentType::ReturnNote,
        ]);

        $this->assertFalse($this->service->isEligible($document));
    }

    public function test_delivery_note_is_not_eligible(): void
    {
        $document = $this->createDocument([
            'type' => DocumentType::DeliveryNote,
        ]);

        $this->assertFalse($this->service->isEligible($document));
    }

    public function test_uk_company_is_not_eligible(): void
    {
        $company = $this->createCompany(['country_code' => 'GB']);
        $partner = $this->createPartner($company, ['vat_number' => 'GB123456789']);
        $document = $this->createInvoice($company, $partner);

        $this->assertFalse($this->service->isEligible($document));
    }

    public function test_italian_company_is_not_eligible(): void
    {
        $company = $this->createCompany(['country_code' => 'IT']);
        $partner = $this->createPartner($company, ['vat_number' => 'IT12345678901']);
        $document = $this->createInvoice($company, $partner);

        $this->assertFalse($this->service->isEligible($document));
    }

    public function test_b2c_invoice_without_partner_vat_is_not_eligible(): void
    {
        $company = $this->createCompany(['country_code' => 'FR']);
        $partner = $this->createPartner($company, ['vat_number' => null]);
        $document = $this->createInvoice($company, $partner);

        $this->assertFalse($this->service->isEligible($document));
    }

    public function test_facturx_profile_enum_has_expected_values(): void
    {
        $this->assertSame('minimum', FacturXProfile::Minimum->value);
        $this->assertSame('basicwl', FacturXProfile::BasicWL->value);
        $this->assertSame('basic', FacturXProfile::Basic->value);
        $this->assertSame('en16931', FacturXProfile::EN16931->value);
        $this->assertSame('extended', FacturXProfile::Extended->value);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCompany(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'Eligibility Test Company',
            'legal_name' => 'Eligibility Test Company SAS',
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
            'vat_number' => 'FR12345678901',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPartner(Company $company, array $overrides = []): Partner
    {
        return Partner::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => 'Eligibility Test Partner',
            'type' => PartnerType::Customer,
            'vat_number' => 'FR98765432109',
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
            'status' => DocumentStatus::Draft,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => 'INV-'.random_int(10000, 99999),
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
    private function createDocument(array $overrides = []): Document
    {
        $company = $this->createCompany();
        $partner = $this->createPartner($company);

        return $this->createInvoice($company, $partner, $overrides);
    }
}
