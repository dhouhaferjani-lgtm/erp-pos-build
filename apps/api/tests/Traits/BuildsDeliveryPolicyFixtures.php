<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Enums\Vertical;
use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DeliveryNoteService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared fixtures for the DPA sub-wave 3E (pre-delivery invoicing policy) suite.
 *
 * Every 3E test needs the same spine: a TN tenant/company with a real permission
 * set and a real chart of accounts (posting runs the GL preflight), a warehouse
 * with stock (delivery-note confirmation issues stock for real), a physical
 * product, and helpers that build the **four invoice shapes the delivery gate has
 * to tell apart**:
 *
 *   1. order-sourced   — `invoice.source_document_id = order`, order payload
 *                        carries `delivery_note_ids` (SalesOrderToInvoiceConverter)
 *   2. converted       — `invoice.payload.source_delivery_note_ids`
 *                        (DeliveryNoteToInvoiceConverter)
 *   3. standalone      — neither linkage
 *   4. service-only    — no physical line at all
 *
 * Shapes 1 and 2 are the two linkage shapes `DeliveredQuantityResolver` reads;
 * the pre-T25f gates read only shape 1, which is the whole reason T25f exists.
 *
 * Real models and the real seeders only — no fakes.
 */
trait BuildsDeliveryPolicyFixtures
{
    protected Tenant $dpTenant;

    protected Company $dpCompany;

    protected User $dpUser;

    protected Partner $dpPartner;

    protected Location $dpLocation;

    protected Product $dpProduct;

    protected function bootDeliveryPolicyFixtures(string $countryCode = 'TN'): void
    {
        Country::firstOrCreate(
            ['code' => 'TN'],
            ['name' => 'Tunisia', 'currency_code' => 'TND', 'currency_symbol' => 'د.ت'],
        );
        Country::firstOrCreate(
            ['code' => 'FR'],
            ['name' => 'France', 'currency_code' => 'EUR', 'currency_symbol' => '€'],
        );

        $this->dpTenant = Tenant::create([
            'name' => '3E Lane Tenant',
            'slug' => 'dpa-3e-'.bin2hex(random_bytes(4)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->dpCompany = Company::create([
            'tenant_id' => $this->dpTenant->id,
            'name' => '3E Lane Company',
            'legal_name' => '3E Lane Company SARL',
            'tax_id' => '3E-TAX',
            'country_code' => $countryCode,
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->dpTenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->dpUser = User::create([
            'tenant_id' => $this->dpTenant->id,
            'name' => '3E Lane User',
            'email' => 'dpa3e-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->dpUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->dpUser->id,
            'company_id' => $this->dpCompany->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->dpCompany->id);

        // Posting runs the GL preflight, which needs real accounts.
        (new TunisiaChartOfAccountsSeeder)->run($this->dpCompany->id, $this->dpTenant->id);

        $this->dpLocation = Location::create([
            'company_id' => $this->dpCompany->id,
            'code' => 'DP-WH',
            'name' => '3E Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->dpPartner = Partner::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'name' => '3E Customer',
            'type' => PartnerType::Customer,
            'country_code' => 'TN',
        ]);

        $this->dpProduct = Product::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'name' => '3E Physical Product',
            'sku' => 'DP-PHYS-'.bin2hex(random_bytes(3)),
            'type' => ProductType::Part,
            'unit' => 'unit',
            'is_physical' => true,
            'unit_price' => '100.000',
            'cost_price' => '60.000000',
            'is_active' => true,
        ]);

        StockLevel::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'product_id' => $this->dpProduct->id,
            'location_id' => $this->dpLocation->id,
            'quantity' => '1000.0000',
            'reserved' => '0.0000',
        ]);
    }

    /**
     * A physical invoice/DN line for `$this->dpProduct`.
     *
     * @return array<string, mixed>
     */
    protected function dpPhysicalLine(string $quantity = '2.0000', string $unitPrice = '100.000'): array
    {
        return [
            'product_id' => $this->dpProduct->id,
            'location_id' => $this->dpLocation->id,
            'description' => '3E physical line',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ];
    }

    /**
     * A service line: NULL `product_id` — the live "not physical" shape
     * (`CreateDocumentRequest` prohibits `product_id` alongside `service_id`).
     *
     * @return array<string, mixed>
     */
    protected function dpServiceLine(string $unitPrice = '80.000'): array
    {
        return [
            'product_id' => null,
            'description' => '3E labour (service)',
            'quantity' => '1.0000',
            'unit_price' => $unitPrice,
        ];
    }

    /**
     * A CONFIRMED invoice ready to be posted. No linkage of any kind — the
     * "standalone" shape.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     */
    protected function dpConfirmedInvoice(array $lines, array $overrides = []): Document
    {
        return $this->dpCreateDocument(array_merge([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'DP-INV-'.bin2hex(random_bytes(4)),
        ], $overrides), $lines);
    }

    /**
     * A CONFIRMED credit note ready to be posted.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    protected function dpConfirmedCreditNote(array $lines): Document
    {
        return $this->dpCreateDocument([
            'type' => DocumentType::CreditNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'DP-CN-'.bin2hex(random_bytes(4)),
        ], $lines);
    }

    /**
     * A DRAFT delivery note. Confirm it through `DeliveryNoteService` when the
     * test wants real stock movements and a real fiscal seal.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     */
    protected function dpDraftDeliveryNote(array $lines, array $overrides = []): Document
    {
        return $this->dpCreateDocument(array_merge([
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Draft,
            'location_id' => $this->dpLocation->id,
            'document_number' => 'DP-DN-'.bin2hex(random_bytes(4)),
        ], $overrides), $lines);
    }

    /**
     * A delivery note confirmed through the REAL service — stock issued,
     * `quantity_delivered` back-filled, fiscal chain sealed.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     */
    protected function dpConfirmedDeliveryNote(array $lines, array $overrides = []): Document
    {
        $deliveryNote = $this->dpDraftDeliveryNote($lines, $overrides);

        return app(DeliveryNoteService::class)
            ->confirm($deliveryNote);
    }

    /**
     * Link an invoice to delivery notes the way `DeliveryNoteToInvoiceConverter`
     * does (`DeliveryNoteToInvoiceConverter.php:150`, `:223`): the CONVERTED shape.
     *
     * @param  list<Document>  $deliveryNotes
     */
    protected function dpLinkConvertedShape(Document $invoice, array $deliveryNotes): void
    {
        $invoice->update([
            'payload' => array_merge($invoice->payload ?? [], [
                'source_delivery_note_ids' => array_map(
                    static fn (Document $dn): string => $dn->id,
                    $deliveryNotes,
                ),
            ]),
        ]);
        $invoice->refresh();
    }

    /**
     * Link an invoice to delivery notes the way `SalesOrderToInvoiceConverter`
     * does: the ORDER shape — invoice `source_document_id = order`, order payload
     * carries `delivery_note_ids`.
     *
     * @param  list<Document>  $deliveryNotes
     */
    protected function dpLinkOrderShape(Document $invoice, array $deliveryNotes): Document
    {
        $order = $this->dpCreateDocument([
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'DP-SO-'.bin2hex(random_bytes(4)),
            'payload' => [
                'delivery_note_ids' => array_map(
                    static fn (Document $dn): string => $dn->id,
                    $deliveryNotes,
                ),
            ],
        ], []);

        $invoice->update(['source_document_id' => $order->id]);
        $invoice->refresh();

        return $order;
    }

    /**
     * An order-shape link with an EMPTY `delivery_note_ids` array — the
     * "nothing was ever delivered against the order" population.
     */
    protected function dpLinkOrderShapeWithNoDeliveryNotes(Document $invoice): Document
    {
        return $this->dpLinkOrderShape($invoice, []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    private function dpCreateDocument(array $attributes, array $lines): Document
    {
        $document = Document::create(array_merge([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'partner_id' => $this->dpPartner->id,
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
            'fiscal_status' => FiscalStatus::Draft,
            'fiscal_category' => FiscalCategory::fromDocumentType(
                $attributes['type'] ?? DocumentType::Invoice,
            ),
        ], $attributes));

        foreach ($lines as $index => $line) {
            /** @var numeric-string $quantity */
            $quantity = (string) ($line['quantity'] ?? '1.0000');
            /** @var numeric-string $unitPrice */
            $unitPrice = (string) ($line['unit_price'] ?? '100.000');

            $attributes = [
                'document_id' => $document->id,
                'line_number' => $index + 1,
                'product_id' => $line['product_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
                'description' => (string) ($line['description'] ?? '3E line'),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => (string) ($line['tax_rate'] ?? '0.00'),
                'line_total' => DocumentLine::computeLineTotal($quantity, $unitPrice, null, null, 3),
            ];

            // `document_lines.quantity_delivered` is NOT NULL with a column
            // default — only set it when the test asks for a specific value.
            if (isset($line['quantity_delivered'])) {
                $attributes['quantity_delivered'] = (string) $line['quantity_delivered'];
            }

            DocumentLine::create($attributes);
        }

        $document->load('lines');
        $subtotal = '0.000';
        foreach ($document->lines as $line) {
            $subtotal = bcadd($subtotal, (string) $line->line_total, 3);
        }

        $document->update([
            'subtotal' => $subtotal,
            'tax_amount' => '0.000',
            'total' => $subtotal,
        ]);

        return $document->refresh();
    }
}
