<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryPostException;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterInterface;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Modules\Document\Presentation\Controllers\DocumentConversionController;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * R-10 — PER-SITE CATCH NARROWING at
 * `DocumentConversionController::receivePurchaseOrderGoods()`.
 *
 * enforcement-P3's M1 census (`docs/handoff/reviews/enforcement-p3/M1-census.md`
 * §6 R-10, row 7 of the §5.5 catcher census) recorded that this method's broad
 * `catch (\InvalidArgumentException)` arm intercepts the GL posting chokepoint's
 * balance refusal — `UnbalancedJournalEntryPostException` is an
 * `\InvalidArgumentException` by parentage — and renders it as
 * **422 `VALIDATION_ERROR`**. A fiscal imbalance is not a fixable request.
 *
 * WHY THE REFUSAL IS SIMULATED HERE AND NOT DRIVEN THROUGH A REAL GOODS RECEIPT
 * (this is a finding, not a shortcut). On the code as it stands the chokepoint's
 * refusal CANNOT reach this arm, on two independent counts:
 *
 *   1. `receivePurchaseOrderGoods` has NO ROUTE. A repo-wide grep for the method
 *      name finds only its definition and a source-anchored assertion in
 *      `DocumentConversionTenantIsolationTest`; the routed receive endpoint is
 *      `PurchaseOrderController::receive` (`Document/Presentation/routes.php:263`),
 *      a different method with its own arms. This test therefore binds a
 *      test-only route to the method, the technique
 *      `Tests\Feature\Bootstrap\RefundFlowExceptionRenderingTest` already uses,
 *      so the response still goes through the real render pipeline.
 *   2. Even invoked directly, the only GL post beneath
 *      `GoodsReceiptService::receiveGoods()/receiveAll()` is the GR-IR twin
 *      dispatched as a `GoodsReceived` event, and its sole listener
 *      (`PostGrIrOnGoodsReceipt::handle`, `EventServiceProvider:147`) swallows
 *      every `\Throwable`. The unswallowed `failClosedGrir` direct call at
 *      `GoodsReceiptService:409` is armed only by `post(..., true)`, passed on
 *      this codebase by `StandaloneReceiptService:134` alone — never by the
 *      converter path this controller drives.
 *
 * So the refusal arrives at this catch only through a converter that raises it,
 * which is exactly what the substituted registry below models: a MINIMAL
 * container substitution, used for the refusal path only, and justified by the
 * unreachability above rather than by convenience. The two regression tests
 * beside it use the REAL registry and real documents.
 */
final class GoodsReceiptConversionUnbalancedGlNarrowingTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = '/api/__test/r10/purchase-orders';

    private Tenant $tenant;

    private Company $company;

    private Location $warehouse;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'R10 Conversion Tenant',
            'slug' => 'r10-conversion-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'R10 Conversion Company',
            'legal_name' => 'R10 Conversion Company SARL',
            'tax_id' => 'R10-TAX',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'R10-WH',
            'name' => 'R10 Conversion Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'R10 Conversion Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        // The production method is unrouted (see the class docblock); bind it to
        // a test-only URL so the assertions exercise the real controller method
        // AND the real bootstrap/app.php render pipeline.
        Route::middleware('api')->post(
            self::ROUTE.'/{id}/receive-goods',
            [DocumentConversionController::class, 'receivePurchaseOrderGoods'],
        );
    }

    /**
     * RED-FIRST TARGET. Before the narrowing this returned
     * `422 {error.code: VALIDATION_ERROR}`; the chokepoint's refusal must
     * instead escape the controller and render through the global handler's
     * catch-all as `500 {error.code: INTERNAL_ERROR}`.
     */
    public function test_chokepoint_balance_refusal_is_not_downgraded_to_422_validation_error(): void
    {
        $purchaseOrder = $this->purchaseOrder();

        $registry = new DocumentConverterRegistry;
        $registry->register($this->converterRaising(
            UnbalancedJournalEntryPostException::forChokepoint('100.000', '90.000')
        ));
        $this->app->instance(DocumentConverterRegistry::class, $registry);

        $response = $this->postJson(self::ROUTE."/{$purchaseOrder->id}/receive-goods");

        $response->assertStatus(500);
        $response->assertJsonPath('error.code', 'INTERNAL_ERROR');

        self::assertNotSame(
            'VALIDATION_ERROR',
            $response->json('error.code'),
            'A GL balance refusal must never be reported as a client-fixable validation error.',
        );
    }

    /**
     * REGRESSION: a genuine `\InvalidArgumentException` from the REAL registry —
     * `DocumentConverterRegistry:93`, raised when no converter is registered for
     * the requested pair — keeps its 422 `VALIDATION_ERROR` contract.
     */
    public function test_unconvertible_source_document_still_returns_422_validation_error(): void
    {
        $invoice = $this->document(DocumentType::Invoice, DocumentStatus::Confirmed);

        $response = $this->postJson(self::ROUTE."/{$invoice->id}/receive-goods");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        self::assertStringContainsString(
            'No converter registered',
            (string) $response->json('error.message'),
        );
    }

    /**
     * REGRESSION: the `\RuntimeException` arm is explicitly OUT of R-10's scope
     * and must be byte-for-byte unaffected. `PurchaseOrderToGoodsReceiptConverter`
     * raises a plain `\RuntimeException` for an already-received order; it must
     * still render 422 `GOODS_RECEIPT_ERROR`.
     */
    public function test_runtime_exception_arm_still_returns_422_goods_receipt_error(): void
    {
        $purchaseOrder = $this->document(DocumentType::PurchaseOrder, DocumentStatus::Received);

        $response = $this->postJson(self::ROUTE."/{$purchaseOrder->id}/receive-goods");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'GOODS_RECEIPT_ERROR');
        self::assertStringContainsString(
            'already been fully received',
            (string) $response->json('error.message'),
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * A PurchaseOrder -> PurchaseOrder converter that raises $exception. It
     * occupies exactly the registry slot `receivePurchaseOrderGoods` looks up,
     * so the controller's try body is entered and the arm under test is the one
     * that decides the response.
     */
    private function converterRaising(\Throwable $exception): DocumentConverterInterface
    {
        return new class($exception) implements DocumentConverterInterface
        {
            public function __construct(private readonly \Throwable $exception) {}

            public function sourceType(): DocumentType
            {
                return DocumentType::PurchaseOrder;
            }

            public function targetType(): DocumentType
            {
                return DocumentType::PurchaseOrder;
            }

            public function convert(Document $source, array $options = []): Document
            {
                throw $this->exception;
            }

            public function canConvert(Document $source): bool
            {
                return true;
            }

            public function getConversionErrors(Document $source): array
            {
                return [];
            }
        };
    }

    private function purchaseOrder(): Document
    {
        return $this->document(DocumentType::PurchaseOrder, DocumentStatus::Confirmed);
    }

    private function document(DocumentType $type, DocumentStatus $status): Document
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'R10-PROD-'.fake()->unique()->numerify('####'),
            'name' => 'R10 Conversion Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => false,
            'cost_price' => '0.000000',
            'last_purchase_cost' => '0.000000',
        ]);

        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => $type,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => $status,
            'document_number' => 'R10-'.fake()->unique()->numerify('######'),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '10.000',
            'tax_amount' => '0.000',
            'total' => '10.000',
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => '2.0000',
            'free_quantity' => '0.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'free_quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
            'line_total' => '10.000',
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => '5.000000',
            'price_entry_mode' => 'unit',
        ]);

        /** @var Document $fresh */
        $fresh = $document->fresh(['lines']);

        return $fresh;
    }
}
