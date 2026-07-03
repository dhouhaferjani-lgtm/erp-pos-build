<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\CreateRfqData;
use App\Modules\Procurement\Application\PurchaseQuoteRequestAwardService;
use App\Modules\Procurement\Application\PurchaseQuoteRequestService;
use App\Modules\Procurement\Application\UpdateRfqData;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PurchaseQuoteRequestInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'RFQ Invariant Tenant',
            'slug' => 'rfq-invariant-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'RFQ Invariant Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_create_response_and_award_write_zero_gl_and_zero_stock_for_rfq(): void
    {
        $journalCount = JournalEntry::count();
        $stockCount = StockMovement::count();

        $rfq = $this->respondedRfq();
        $po = app(PurchaseQuoteRequestAwardService::class)->award($rfq->id, $this->tenant->id, $this->company->id);

        $this->assertSame(DocumentType::PurchaseOrder, $po->type);
        $this->assertSame($journalCount, JournalEntry::count());
        $this->assertSame($stockCount, StockMovement::count());
    }

    public function test_rfq_is_non_fiscal_and_never_payable_or_receivable(): void
    {
        $rfq = $this->respondedRfq();

        $this->assertSame(FiscalCategory::NonFiscal, FiscalCategory::fromDocumentType(DocumentType::PurchaseQuoteRequest));
        $this->assertFalse(DocumentType::PurchaseQuoteRequest->affectsReceivable());
        $this->assertSame(0, DocumentType::PurchaseQuoteRequest->receivableDirection());
        $this->assertFalse(DocumentType::PurchaseQuoteRequest->canTransitionToPaid());
        $this->assertSame('0', $rfq->getOutstandingAmount());
        $this->assertSame('unpaid', $rfq->getPaymentStatus()->value);
    }

    public function test_rfq_service_paths_never_set_posted_paid_or_received(): void
    {
        $rfq = $this->respondedRfq();

        $this->assertTrue(in_array($rfq->status, [DocumentStatus::Draft, DocumentStatus::Confirmed, DocumentStatus::Cancelled], true));
        $this->assertFalse(in_array($rfq->status, [DocumentStatus::Posted, DocumentStatus::Paid, DocumentStatus::Received], true));
    }

    private function respondedRfq(): Document
    {
        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Invariant Supplier',
            'type' => PartnerType::Supplier,
        ]);

        $rfq = app(PurchaseQuoteRequestService::class)->createGroup(
            new CreateRfqData(
                partnerIds: [$supplier->id],
                lines: [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => '4.0000',
                        'unit_price' => null,
                        'description' => 'Invariant product',
                    ],
                ],
                validityDate: null,
                notes: null,
            ),
            $this->tenant->id,
            $this->company->id,
            $this->company->currency,
        )->first();

        $this->assertInstanceOf(Document::class, $rfq);

        return app(PurchaseQuoteRequestService::class)->recordResponse(
            $rfq->id,
            $this->tenant->id,
            $this->company->id,
            new UpdateRfqData(
                lines: [
                    [
                        'id' => $rfq->lines->first()?->id,
                        'product_id' => $this->product->id,
                        'quantity' => '4.0000',
                        'unit_price' => '11.000',
                        'description' => 'Invariant product',
                    ],
                ],
                validityDate: null,
                supplierReference: null,
                leadTimeDays: null,
            ),
        );
    }
}
