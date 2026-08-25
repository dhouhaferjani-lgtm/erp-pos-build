<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\PurchaseOrderConfirmed;
use App\Modules\Document\Domain\Services\PurchaseOrderService;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\LandedCostService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PurchaseOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrderService $service;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        // Bind CompanyContext so CurrencyScaleResolver has context
        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

        $landedCostService = $this->app->make(LandedCostService::class);
        $taxCalculationService = $this->app->make(TaxCalculationService::class);
        // W2-6 r2 C2: confirm() now refuses unpriced lines, which needs the money
        // scale of the document's currency (rule 19 — resolver, never a literal).
        $scaleResolver = $this->app->make(CurrencyScaleResolverInterface::class);
        $this->service = new PurchaseOrderService($landedCostService, $taxCalculationService, $scaleResolver);

        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Authenticate a user for confirmed_by tracking
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->actingAs($this->user);
    }

    public function test_confirms_purchase_order(): void
    {
        // Arrange
        $purchaseOrder = $this->createDraftPurchaseOrder();
        $this->assertEquals(DocumentStatus::Draft, $purchaseOrder->status);

        // Act
        $confirmedPO = $this->service->confirm($purchaseOrder);

        // Assert
        $this->assertEquals(DocumentStatus::Confirmed, $confirmedPO->status);
        $this->assertNotNull($confirmedPO->confirmed_at);
        $this->assertNotNull($confirmedPO->confirmed_by);
    }

    public function test_confirm_accepts_explicit_actor_id(): void
    {
        $purchaseOrder = $this->createDraftPurchaseOrder(['document_number' => 'PO-2025-ACTOR']);
        $actor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $confirmedPO = $this->service->confirm($purchaseOrder, $actor->id);

        $this->assertEquals(DocumentStatus::Confirmed, $confirmedPO->status);
        $this->assertSame($actor->id, $confirmedPO->confirmed_by);
    }

    public function test_allocates_landed_costs_on_confirm(): void
    {
        // Arrange
        $purchaseOrder = $this->createDraftPurchaseOrder();

        // Act
        $confirmedPO = $this->service->confirm($purchaseOrder);

        // Assert - lines should have allocated costs
        $confirmedPO->lines->each(function (DocumentLine $line) {
            $this->assertNotNull($line->allocated_costs);
            $this->assertNotNull($line->landed_unit_cost);
        });
    }

    public function test_dispatches_purchase_order_confirmed_event(): void
    {
        // Arrange
        Event::fake([PurchaseOrderConfirmed::class]);
        $purchaseOrder = $this->createDraftPurchaseOrder();

        // Act
        $confirmedPO = $this->service->confirm($purchaseOrder);

        // Assert
        Event::assertDispatched(PurchaseOrderConfirmed::class, function (PurchaseOrderConfirmed $event) use ($confirmedPO) {
            return $event->purchaseOrderId === $confirmedPO->id
                && $event->companyId === $confirmedPO->company_id
                && $event->documentNumber === $confirmedPO->document_number;
        });
    }

    public function test_confirmation_does_not_post_supplier_invoice_gl(): void
    {
        $purchaseOrder = $this->createDraftPurchaseOrder([
            'subtotal' => '1000.00',
            'currency' => 'EUR',
        ]);

        $confirmedPO = $this->service->confirm($purchaseOrder);

        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'supplier_invoice')
            ->where('source_id', $confirmedPO->id)
            ->count());

        $this->partner->refresh();
        $this->assertEquals('0.000', $this->partner->payable_balance);
    }

    public function test_throws_exception_if_not_draft(): void
    {
        // Arrange
        $purchaseOrder = $this->createDraftPurchaseOrder();
        $purchaseOrder->update(['status' => DocumentStatus::Confirmed]);

        // Act & Assert
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only draft purchase orders can be confirmed');

        $this->service->confirm($purchaseOrder);
    }

    public function test_throws_exception_if_not_purchase_order(): void
    {
        // Arrange
        $salesOrder = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SO-001',
            'document_date' => now(),
            'currency' => 'USD',
            'total' => '100.00',
        ]);

        // Act & Assert
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only purchase orders can be confirmed with this service');

        $this->service->confirm($salesOrder);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function createDraftPurchaseOrder(array $overrides = [], string $lineTaxRate = '0.00'): Document
    {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Draft,
            'document_number' => 'PO-2025-001',
            'document_date' => now(),
            'currency' => 'USD',
            'subtotal' => '1000.00',
            'tax_amount' => '0.00',
            'total' => '1000.00',
            ...$overrides,
        ]);

        // Add lines
        DocumentLine::create([
            'document_id' => $po->id,
            'line_number' => 1,
            'description' => 'Product A',
            'quantity' => '10.00',
            'unit_price' => '100.00',
            'tax_rate' => $lineTaxRate,
            'line_total' => '1000.00',
        ]);

        return $po->fresh(['lines']);
    }
}
