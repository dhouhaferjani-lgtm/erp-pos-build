<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Enums\Vertical;
use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared fixtures for the DPA lane CF (guided cancel-invoice flow) test suite.
 *
 * Every CF test needs the same spine: a tenant/company with a real permission
 * set, two locations (the (product, location) tuple split of CF-D11 is
 * meaningless with one), physical + service products, and helpers that build the
 * delivery-note → invoice linkage shapes the delivered-quantity resolver has to
 * traverse. Duplicating that across seven test classes is how the fixtures drift
 * apart and the tests stop describing the same system.
 *
 * Real models and the real `RolesAndPermissionsSeeder` only — no fakes.
 */
trait BuildsCancelFlowFixtures
{
    protected Tenant $cfTenant;

    protected Company $cfCompany;

    protected User $cfUser;

    protected Partner $cfPartner;

    protected Location $cfLocationA;

    protected Location $cfLocationB;

    protected Product $cfProduct;

    protected Product $cfProductTwo;

    protected function bootCancelFlowFixtures(string $slug = 'cf-lane'): void
    {
        // `vat_periods.country_code` carries a real FK to `countries`, so anything
        // in this lane that creates a VAT period needs the row to exist first.
        Country::firstOrCreate(
            ['code' => 'TN'],
            [
                'name' => 'Tunisia',
                'currency_code' => 'TND',
                'currency_symbol' => 'د.ت',
            ],
        );

        $this->cfTenant = Tenant::create([
            'name' => 'CF Lane Tenant',
            'slug' => $slug.'-'.bin2hex(random_bytes(3)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->cfCompany = Company::create([
            'tenant_id' => $this->cfTenant->id,
            'name' => 'CF Lane Company',
            'legal_name' => 'CF Lane Company SARL',
            'tax_id' => 'CF-TAX',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->cfTenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->cfUser = User::create([
            'tenant_id' => $this->cfTenant->id,
            'name' => 'CF Lane User',
            'email' => 'cf-'.bin2hex(random_bytes(3)).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->cfUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->cfUser->id,
            'company_id' => $this->cfCompany->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->cfCompany->id);

        $this->cfLocationA = Location::create([
            'company_id' => $this->cfCompany->id,
            'code' => 'CF-WH-A',
            'name' => 'CF Warehouse A',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->cfLocationB = Location::create([
            'company_id' => $this->cfCompany->id,
            'code' => 'CF-WH-B',
            'name' => 'CF Warehouse B',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->cfPartner = Partner::create([
            'tenant_id' => $this->cfTenant->id,
            'company_id' => $this->cfCompany->id,
            'name' => 'CF Customer',
            'type' => PartnerType::Customer,
            'country_code' => 'TN',
        ]);

        $this->cfProduct = Product::create([
            'tenant_id' => $this->cfTenant->id,
            'company_id' => $this->cfCompany->id,
            'name' => 'CF Physical Product',
            'sku' => 'CF-PHYS-'.bin2hex(random_bytes(3)),
            'type' => ProductType::Part,
            'unit' => 'unit',
            'cost_price' => '40.000000',
            'is_active' => true,
        ]);

        $this->cfProductTwo = Product::create([
            'tenant_id' => $this->cfTenant->id,
            'company_id' => $this->cfCompany->id,
            'name' => 'CF Physical Product Two',
            'sku' => 'CF-PHYS2-'.bin2hex(random_bytes(3)),
            'type' => ProductType::Part,
            'unit' => 'unit',
            'cost_price' => '25.000000',
            'is_active' => true,
        ]);
    }

    /**
     * A "services only" invoice line, in the shape the schema actually produces:
     * `CreateDocumentRequest`'s `lines.*.service_id` carries `prohibits:lines.*.product_id`
     * (`CreateDocumentRequest.php:104-114`), so a service line has a NULL
     * `product_id`. That is also the live predicate `receiveStockBack()` keys on
     * (`ReturnNoteService.php:174` — its companion `$line->product->is_service`
     * test is dead: no such column or accessor exists on `Product`, see the CF
     * report), so keying CF-D6's `requires_return_decision` on `product_id`
     * cannot disagree with what actually restocks.
     *
     * @return array<string, mixed>
     */
    protected function cfServiceLine(string $unitPrice = '80.000'): array
    {
        return [
            'product_id' => null,
            'description' => 'CF Labour (service)',
            'quantity' => '1.0000',
            'unit_price' => $unitPrice,
        ];
    }

    /**
     * A POSTED, sealed invoice with the given lines.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     */
    protected function cfPostedInvoice(array $lines, array $overrides = []): Document
    {
        return $this->cfCreateSealableDocument(array_merge([
            'tenant_id' => $this->cfTenant->id,
            'company_id' => $this->cfCompany->id,
            'location_id' => null,
            'partner_id' => $this->cfPartner->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Sealed,
            'status' => DocumentStatus::Posted,
            'document_number' => 'CF-INV-'.bin2hex(random_bytes(4)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
            'fiscal_hash' => hash('sha256', 'cf-inv-'.bin2hex(random_bytes(4))),
            'chain_sequence' => 1,
        ], $overrides), $lines);
    }

    /**
     * A CONFIRMED delivery note (the only shape the delivered-quantity resolver
     * reads) with the given lines.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     */
    protected function cfConfirmedDeliveryNote(array $lines, array $overrides = []): Document
    {
        return $this->cfCreateSealableDocument(array_merge([
            'tenant_id' => $this->cfTenant->id,
            'company_id' => $this->cfCompany->id,
            'location_id' => $this->cfLocationA->id,
            'partner_id' => $this->cfPartner->id,
            'type' => DocumentType::DeliveryNote,
            'fiscal_category' => FiscalCategory::DeliveryNote,
            'fiscal_status' => FiscalStatus::Sealed,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'CF-DN-'.bin2hex(random_bytes(4)),
            'document_date' => now()->toDateString(),
            'confirmed_at' => now(),
            'confirmed_by' => $this->cfUser->id,
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
            'fiscal_hash' => hash('sha256', 'cf-dn-'.bin2hex(random_bytes(4))),
            'chain_sequence' => 1,
        ], $overrides), $lines);
    }

    /**
     * Create a document DRAFT-FIRST, attach its lines, compute its totals, and only THEN
     * seal it — if the caller actually wants it sealed.
     *
     * ── WHY THIS EXISTS (gate CF round 4, PG-surfaced CRITICAL) ──
     * The previous shape INSERTed the row with `fiscal_status = SEALED` and zero totals,
     * attached lines, then UPDATEd `subtotal` / `tax_amount` / `total`. All three are in
     * the immutable-field list of `enforce_document_immutability()`
     * (`2025_12_11_054716_add_document_immutability_trigger.php`), so on PostgreSQL every
     * one of the lane's `[PG]`-registered classes died in `setUp()` with
     * "Cannot modify sealed fiscal document" — **78 failed / 21 passed** on a scratch
     * PG 16, against green on SQLite.
     *
     * Nobody saw it because that migration RETURNS EARLY on SQLite, so the trigger never
     * exists on the default lane. The production code is NOT implicated — `payload` and
     * `status` are not in the protected list, so the guided cancel's writes are legal —
     * but every fiscal claim this lane made had only ever executed against a database
     * where the trigger, `lockForUpdate()` and the advisory locks are all no-ops.
     *
     * The order below is the same one the real sealers use
     * (`ReturnNoteService::confirmWithFiscalChain()` computes totals while the document is
     * still a draft, precisely because the trigger rejects them afterwards), so the
     * fixture now mirrors production instead of contradicting it.
     *
     * SEALING IS CONDITIONAL, which is the part a crude patch gets wrong: several tests
     * deliberately pass `fiscal_status => Draft` overrides, and re-sealing those trips
     * `chk_fiscal_mandatory_core` on a missing `fiscal_hash`. The target state is read
     * from the MERGED attributes, so a caller asking for a draft gets a draft.
     *
     * @param  array<string, mixed>  $attributes  Already merged (defaults + overrides).
     * @param  list<array<string, mixed>>  $lines
     */
    private function cfCreateSealableDocument(array $attributes, array $lines): Document
    {
        $sealedState = [
            'fiscal_status' => $attributes['fiscal_status'] ?? FiscalStatus::Draft,
            'fiscal_hash' => $attributes['fiscal_hash'] ?? null,
            'chain_sequence' => $attributes['chain_sequence'] ?? null,
        ];

        $wantsSeal = $sealedState['fiscal_status'] === FiscalStatus::Sealed;

        // Draft on the way in: `chk_fiscal_mandatory_core` only demands a hash and a chain
        // sequence once a document is SEALED, so a draft may legally carry neither.
        $document = Document::create(array_merge($attributes, [
            'fiscal_status' => FiscalStatus::Draft,
            'fiscal_hash' => null,
            'chain_sequence' => null,
        ]));

        $this->cfAttachLines($document, $lines);
        $this->cfRecomputeTotals($document);

        if ($wantsSeal) {
            // Legal: the trigger enforces only when `OLD.fiscal_status = 'SEALED'`, and at
            // this update it is still DRAFT.
            $document->update($sealedState);
        }

        return $document->refresh();
    }

    /**
     * Link an invoice to delivery notes the way `DeliveryNoteToInvoiceConverter`
     * does: `invoice.payload.source_delivery_note_ids`.
     *
     * @param  list<Document>  $deliveryNotes
     */
    protected function cfLinkInvoiceToDeliveryNotes(Document $invoice, array $deliveryNotes): void
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
     * does: the invoice carries `source_document_id = order.id` (set by
     * `CopiesDocumentData::createTargetDocument()`), and the ORDER's payload
     * carries `delivery_note_ids`.
     *
     * @param  list<Document>  $deliveryNotes
     */
    protected function cfLinkInvoiceViaSalesOrder(Document $invoice, array $deliveryNotes): Document
    {
        $order = Document::create([
            'tenant_id' => $this->cfTenant->id,
            'company_id' => $this->cfCompany->id,
            'partner_id' => $this->cfPartner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'CF-SO-'.bin2hex(random_bytes(4)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
            'payload' => [
                'delivery_note_ids' => array_map(
                    static fn (Document $dn): string => $dn->id,
                    $deliveryNotes,
                ),
            ],
        ]);

        $invoice->update(['source_document_id' => $order->id]);
        $invoice->refresh();

        return $order;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function cfAttachLines(Document $document, array $lines): void
    {
        foreach ($lines as $index => $line) {
            /** @var numeric-string $quantity */
            $quantity = (string) ($line['quantity'] ?? '1.0000');
            /** @var numeric-string $unitPrice */
            $unitPrice = (string) ($line['unit_price'] ?? '100.000');
            /** @var numeric-string|null $discountPercent */
            $discountPercent = isset($line['discount_percent']) ? (string) $line['discount_percent'] : null;
            /** @var numeric-string|null $discountAmount */
            $discountAmount = isset($line['discount_amount']) ? (string) $line['discount_amount'] : null;

            DocumentLine::create([
                'document_id' => $document->id,
                'line_number' => $index + 1,
                'product_id' => $line['product_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
                'description' => (string) ($line['description'] ?? 'CF line'),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_percent' => $discountPercent,
                'discount_amount' => $discountAmount,
                'tax_rate' => (string) ($line['tax_rate'] ?? '0.00'),
                'line_total' => DocumentLine::computeLineTotal(
                    $quantity,
                    $unitPrice,
                    $discountPercent,
                    $discountAmount,
                    3,
                ),
            ]);
        }
    }

    private function cfRecomputeTotals(Document $document): void
    {
        $document->load('lines');
        $subtotal = '0.000';
        $tax = '0.000';

        foreach ($document->lines as $line) {
            $net = (string) $line->line_total;
            $subtotal = bcadd($subtotal, $net, 3);
            $tax = bcadd($tax, bcmul($net, bcdiv((string) $line->tax_rate, '100', 4), 3), 3);
        }

        $document->update([
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total' => bcadd($subtotal, $tax, 3),
        ]);
    }
}
