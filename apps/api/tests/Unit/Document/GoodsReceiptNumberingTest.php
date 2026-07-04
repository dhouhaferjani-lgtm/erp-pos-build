<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GoodsReceiptNumberingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private DocumentNumberingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'GRN Numbering Tenant',
            'slug' => 'grn-numbering-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GRN Numbering Company',
            'legal_name' => 'GRN Numbering Company SARL',
            'tax_id' => 'GRN-TAX',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->service = new DocumentNumberingService;
    }

    #[Test]
    public function goods_receipt_sequence_uses_grn_prefix_and_increments_sequentially(): void
    {
        $year = date('Y');

        $first = $this->service->generateForKey($this->tenant->id, $this->company->id, 'goods_receipt', 'GRN');
        $second = $this->service->generateForKey($this->tenant->id, $this->company->id, 'goods_receipt', 'GRN');

        $this->assertSame("GRN-{$year}-0001", $first);
        $this->assertSame("GRN-{$year}-0002", $second);
    }

    #[Test]
    public function goods_receipt_sequence_is_isolated_from_purchase_order_sequence(): void
    {
        $year = date('Y');

        $purchaseOrder = $this->service->generateNumber($this->tenant->id, $this->company->id, DocumentType::PurchaseOrder);
        $goodsReceipt = $this->service->generateForKey($this->tenant->id, $this->company->id, 'goods_receipt', 'GRN');

        $this->assertMatchesRegularExpression('/^PO-\d{4}-0001$/', $purchaseOrder);
        $this->assertSame("GRN-{$year}-0001", $goodsReceipt);
    }

    #[Test]
    public function goods_receipt_sequence_is_isolated_per_company(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other GRN Company',
            'legal_name' => 'Other GRN Company SARL',
            'tax_id' => 'GRN-TAX-2',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->service->generateForKey($this->tenant->id, $this->company->id, 'goods_receipt', 'GRN');
        $this->service->generateForKey($this->tenant->id, $this->company->id, 'goods_receipt', 'GRN');

        $otherFirst = $this->service->generateForKey($this->tenant->id, $otherCompany->id, 'goods_receipt', 'GRN');

        $this->assertMatchesRegularExpression('/^GRN-\d{4}-0001$/', $otherFirst);
    }

    #[Test]
    public function typed_document_numbering_still_uses_existing_prefixes(): void
    {
        $invoice = $this->service->generateNumber($this->tenant->id, $this->company->id, DocumentType::Invoice);
        $quote = $this->service->generateNumber($this->tenant->id, $this->company->id, DocumentType::Quote);

        $this->assertMatchesRegularExpression('/^INV-\d{4}-0001$/', $invoice);
        $this->assertMatchesRegularExpression('/^QT-\d{4}-0001$/', $quote);
    }
}
