<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DeliveryNoteService;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Document\Domain\Services\ReturnNoteService;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Database\Factories\CompanyFactory;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Illuminate\Support\Str;

/**
 * ONE fixture origin for the DPA Wave-3 sub-wave 3A suites (T1 characterisation,
 * T2 classification, T3 precision, T4 physical predicate, T5 POS cost snapshot).
 *
 * A tenant + FR company with a seeded French chart, one POS-enabled warehouse,
 * a terminal, a CASH payment method and a customer — plus the document builders
 * that drive the four real exit/entry writers. Deliberately shared rather than
 * copied per suite: this program's dominant defect class is two origins drifting
 * apart, and a fixture is no exception.
 *
 * The consuming test class must `use RefreshDatabase` and call
 * `bootWave3ExitFixtures()` from `setUp()`.
 */
trait BuildsWave3ExitFixtures
{
    protected string $tenantId;

    protected string $companyId;

    protected string $locationId;

    protected string $terminalId;

    protected string $operatorId;

    protected Partner $customer;

    protected function bootWave3ExitFixtures(): void
    {
        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        /** @var Company $company */
        $company = CompanyFactory::new()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        app(CompanyContext::class)->setCompanyId($this->companyId);

        (new FranceChartOfAccountsSeeder)->run($this->companyId, $this->tenantId);

        $location = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->companyId,
            'name' => 'Main Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
            'pos_enabled' => true,
        ]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $this->terminalId = $terminal->id;

        $user = User::factory()->create(['tenant_id' => $this->tenantId]);
        $this->operatorId = $user->id;
        $this->actingAs($user);

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        $this->customer = Partner::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'type' => PartnerType::Customer,
            'name' => 'Wave 3 Customer',
            'code' => 'CUST-'.Str::upper(Str::random(6)),
            'country_code' => 'FR',
        ]);
    }

    protected function physicalProduct(string $costPrice, string $name = 'Wave 3 Widget'): Product
    {
        return Product::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'sku' => 'PROD-'.Str::upper(Str::random(6)),
            'name' => $name,
            'type' => ProductType::Part,
            'unit' => 'piece',
            'cost_price' => $costPrice,
            'sale_price' => '100.00',
            'purchase_price' => '45.00',
            'tax_rate' => 0,
            'is_active' => true,
            'is_physical' => true,
        ]);
    }

    /**
     * A product row that is NOT physical but IS a product (`product_id` set) —
     * the population D-19 / T4 turns on: today's three `is_service` guards read
     * a phantom column and let this line move stock.
     */
    protected function nonPhysicalProduct(string $costPrice = '20.000000'): Product
    {
        return Product::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'sku' => 'SVC-'.Str::upper(Str::random(6)),
            'name' => 'Labour',
            'type' => ProductType::Service,
            'unit' => 'hour',
            'cost_price' => $costPrice,
            'sale_price' => '80.00',
            'tax_rate' => 0,
            'is_active' => true,
            'is_physical' => false,
        ]);
    }

    protected function seedStock(string $productId, string $quantity): StockLevel
    {
        return StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $productId,
            'variant_id' => null,
            'location_id' => $this->locationId,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    protected function postedInvoiceFor(Product $product, string $quantity): Document
    {
        $invoice = $this->draftDocument(DocumentType::Invoice, $product, $quantity, 'INV');
        $invoice->update(['status' => DocumentStatus::Confirmed]);

        /** @var Document $confirmed */
        $confirmed = $invoice->fresh(['lines']);

        return $this->app->make(DocumentPostingService::class)->post($confirmed);
    }

    protected function confirmedDeliveryNoteFor(Product $product, string $quantity): Document
    {
        $deliveryNote = $this->draftDocument(DocumentType::DeliveryNote, $product, $quantity, 'DN');

        return $this->app->make(DeliveryNoteService::class)->confirm($deliveryNote);
    }

    protected function confirmedReturnNoteFor(Product $product, string $quantity): Document
    {
        $returnNote = $this->draftDocument(DocumentType::ReturnNote, $product, $quantity, 'RN');

        return $this->app->make(ReturnNoteService::class)->confirm($returnNote);
    }

    protected function draftDocument(DocumentType $type, Product $product, string $quantity, string $prefix): Document
    {
        /** @var numeric-string $quantity */
        $lineTotal = bcmul($quantity, '100.00', 2);

        $document = Document::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->companyId,
            'tenant_id' => $this->tenantId,
            'type' => $type,
            'status' => DocumentStatus::Draft,
            'partner_id' => $this->customer->id,
            'location_id' => $this->locationId,
            'document_number' => $prefix.'-W3A-'.Str::upper(Str::random(6)),
            'document_date' => now(),
            'currency' => 'EUR',
            'total' => $lineTotal,
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $document->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'location_id' => $this->locationId,
            'description' => $product->name,
            'quantity' => $quantity,
            'unit_price' => '100.00',
            'tax_rate' => 0,
            'line_total' => $lineTotal,
        ]);

        /** @var Document $fresh */
        $fresh = $document->fresh(['lines']);

        return $fresh;
    }

    /**
     * Narrow a decimal-column read to `numeric-string` for bcmath (no float ever
     * touches money or quantity — house rule 19).
     *
     * @return numeric-string
     */
    protected function numericString(mixed $value): string
    {
        $string = (string) $value; // @phpstan-ignore-line cast.string

        if (! is_numeric($string)) {
            throw new \RuntimeException('decimal column must read back numeric, got: '.$string);
        }

        /** @var numeric-string $string */
        return $string;
    }
}
