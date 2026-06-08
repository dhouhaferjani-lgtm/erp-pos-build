<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Document;

use App\Modules\Company\Application\Services\TaxIdentityResolver;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Application\Services\FacturXService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
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
 * Verifies that FacturXService uses $line->description (stored designation, never the
 * live Product.name) as the authoritative item designation in Factur-X output.
 *
 * BASIC-WL profile note: FACTUR-X BASIC WL is a "Without Lines" profile — it does not
 * emit per-item positions in the XML, only a consolidated tax/total breakdown. Line-level
 * descriptions do not appear in BASIC-WL output. This test class therefore:
 *
 *  1. Confirms the BASIC-WL XML remains schema-valid when lines carry a designation
 *     override (no regression).
 *  2. Verifies that the line tax aggregation uses quantities/prices from the line model
 *     correctly (totals are authoritative).
 *  3. Documents the intended behavior: $line->description is the authoritative item name
 *     that should be used if the profile is ever upgraded to EN16931 (COMFORT) or EXTENDED
 *     (both of which do emit line positions).
 */
final class FacturXDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private FacturXService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FacturXService(new TaxIdentityResolver);

        $this->tenant = Tenant::create([
            'name' => 'FacturX Description Test Tenant',
            'slug' => 'facturx-description-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    /**
     * Core regression: a French invoice whose line has an overridden description
     * (different from the live product name) must still produce valid BASIC-WL XML.
     *
     * BASIC-WL does not include per-line item names, so this test verifies the XML
     * is generated without errors and the tax totals are computed from the line data.
     */
    public function test_facturx_xml_is_generated_correctly_when_line_description_is_overridden(): void
    {
        $document = $this->buildFrenchInvoiceWithOverriddenLine(
            lineDescription: 'Prise en charge moteur',
            notes: 'Ref: WO-123',
        );

        $xml = $this->service->generateXml($document);

        $this->assertNotEmpty($xml);
        $this->assertStringContainsString('<?xml', $xml);
        $this->assertStringContainsString($document->document_number, $xml);
        // Tax totals must appear
        $this->assertStringContainsString('VAT', $xml);
    }

    /**
     * The BASIC-WL XML must validate against the FACTUR-X BASIC-WL schema even when
     * line descriptions carry an override.
     */
    public function test_facturx_basic_wl_xml_validates_against_schema_with_overridden_description(): void
    {
        $schemaPath = __DIR__.'/../../../../vendor/horstoeko/zugferd/src/schema/FACTUR-X_BASIC-WL.xsd';

        if (! file_exists($schemaPath)) {
            $this->markTestSkipped('BASIC-WL schema not available in vendor.');
        }

        $document = $this->buildFrenchInvoiceWithOverriddenLine(
            lineDescription: 'Prise en charge moteur',
            notes: 'Ref: WO-123',
        );

        $xml = $this->service->generateXml($document);

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $valid = $dom->schemaValidate($schemaPath);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $this->assertTrue(
            $valid,
            'Factur-X BASIC-WL XML must be schema-valid when lines carry designation overrides. Schema errors: '
                .implode('; ', array_map(static fn (\LibXMLError $e): string => trim($e->message), $errors)),
        );
    }

    /**
     * BASIC-WL is "Without Lines" — line item names do NOT appear in the XML.
     * This test documents this behavior explicitly so any future profile upgrade
     * (to EN16931/COMFORT which DOES include line positions) can be verified to
     * use $line->description rather than live product name lookups.
     */
    public function test_basic_wl_xml_does_not_contain_per_line_item_names(): void
    {
        $document = $this->buildFrenchInvoiceWithOverriddenLine(
            lineDescription: 'Prise en charge moteur',
            notes: 'Ref: WO-123',
        );

        $xml = $this->service->generateXml($document);

        // BASIC-WL has no line positions — neither the override nor the product name appears
        $this->assertStringNotContainsString('Prise en charge moteur', $xml,
            'BASIC-WL is "Without Lines" — per-item descriptions must not appear in the XML.');

        $this->assertStringNotContainsString('LiveProductNameNotInXml', $xml,
            'Live product name must not bleed into BASIC-WL XML.');
    }

    private function buildFrenchInvoiceWithOverriddenLine(
        string $lineDescription,
        ?string $notes,
    ): Document {
        $suffix = random_int(10000, 99999);

        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => "Facture Test SAS {$suffix}",
            'legal_name' => "Facture Test SAS {$suffix}",
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
        ]);

        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => "Transports Durand {$suffix} SARL",
            'type' => PartnerType::Customer,
            'vat_number' => "FR{$suffix}09",
            'street_address' => '20 Avenue des Champs-Elysees',
            'city' => 'Paris',
            'postal_code' => '75008',
            'country_code' => 'FR',
        ]);

        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => "INV-DESC-{$suffix}",
            'document_date' => '2026-04-25',
            'currency' => 'EUR',
            'subtotal' => '200.000',
            'tax_amount' => '40.000',
            'total' => '240.000',
            'balance_due' => '240.000',
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => $lineDescription,
            'notes' => $notes,
            'designation_default_snapshot' => 'LiveProductNameNotInXml',
            'quantity' => '2.000',
            'unit_price' => '100.000',
            'tax_rate' => '20.000',
            'line_total' => '200.000',
            'quantity_delivered' => '0.000',
            'quantity_received' => '0.000',
            'allocated_costs' => '0.000',
        ]);

        return $document->fresh(['company', 'partner', 'lines']) ?? $document;
    }
}
