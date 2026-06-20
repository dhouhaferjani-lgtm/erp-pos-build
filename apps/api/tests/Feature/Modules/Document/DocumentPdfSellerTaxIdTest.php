<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Document;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Application\Services\DocumentPdfService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Document PDFs (invoice / credit note / delivery note) must render the
 * establishment (location) seller tax identity for multi-branch tenants —
 * branch override → company fallback — with a per-country label.
 */
final class DocumentPdfSellerTaxIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_renders_branch_tax_id_with_localized_country_label(): void
    {
        Country::create([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'tax_id_label' => 'Matricule Fiscal',
        ]);

        $document = $this->makeInvoice(
            countryCode: 'TN',
            companyTaxId: 'COMPANY-MF-0000',
            branchTaxId: 'BRANCH-MF-1234',
        );

        $data = $this->app->make(DocumentPdfService::class)->viewDataFor($document);
        $html = view('documents.components.parties', $data)->render();

        $this->assertStringContainsString('BRANCH-MF-1234', $html);
        $this->assertStringContainsString('Matricule Fiscal', $html);
        $this->assertStringNotContainsString('COMPANY-MF-0000', $html);
    }

    public function test_invoice_falls_back_to_company_tax_id_when_branch_has_none(): void
    {
        $document = $this->makeInvoice(
            countryCode: 'FR',
            companyTaxId: 'COMPANY-ONLY-TAX',
            branchTaxId: null,
        );

        $data = $this->app->make(DocumentPdfService::class)->viewDataFor($document);
        $html = view('documents.components.parties', $data)->render();

        $this->assertStringContainsString('COMPANY-ONLY-TAX', $html);
    }

    private function makeInvoice(string $countryCode, string $companyTaxId, ?string $branchTaxId): Document
    {
        $suffix = random_int(10000, 99999);

        $tenant = Tenant::create([
            'name' => 'Doc Seller Tax Tenant',
            'slug' => "doc-seller-tax-{$suffix}",
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Doc Company {$suffix}",
            'legal_name' => "Doc Company {$suffix} SARL",
            'tax_id' => $companyTaxId,
            'country_code' => $countryCode,
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ]);

        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'Branch',
            'code' => "B{$suffix}",
            'type' => LocationType::Shop,
            'address_country' => $countryCode,
            'tax_id' => $branchTaxId,
            'is_default' => false,
            'is_active' => true,
        ]);

        $partner = Partner::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Doc Partner',
            'type' => PartnerType::Customer,
        ]);

        return Document::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => "INV-{$suffix}",
            'document_date' => now()->toDateString(),
            'currency' => 'EUR',
            'subtotal' => '100.000',
            'tax_amount' => '20.000',
            'total' => '120.000',
            'balance_due' => '120.000',
        ]);
    }
}
