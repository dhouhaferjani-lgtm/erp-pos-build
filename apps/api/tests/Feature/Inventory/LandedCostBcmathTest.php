<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentAdditionalCost;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Inventory\Application\Services\LandedCostService;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Drift-exposing coverage for {@see LandedCostService}.
 *
 * Additional costs are distributed across PO lines proportionally by value.
 * The pre-bcmath implementation computed each line's share in native float
 * (`$additionalCostsTotal * ($lineTotal / $subtotal)`), then `round(..., scale)`.
 * Two problems compound on a many-line PO:
 *
 *  1. The float proportion carries IEEE-754 error into every line's share.
 *  2. Per-line rounding leaves an un-captured residue, so the sum of the
 *     allocated_costs columns does NOT equal the additional-costs input total —
 *     value silently leaks out of (or into) inventory cost.
 *
 * The bcmath implementation distributes the first N-1 lines proportionally and
 * assigns the exact running remainder to the final line, so the sum of the
 * persisted allocations equals the input total to the millième. This test
 * asserts that exact reconciliation across a realistic 30-line TND purchase
 * order.
 */
class LandedCostBcmathTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $warehouse;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'LC Bcmath Tenant',
            'slug' => 'lc-bcmath-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'LC Bcmath Company',
            'legal_name' => 'LC Bcmath Company LLC',
            'tax_id' => 'TAX-LC-001',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        // Bind TND company → scale 3.
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-LC-01',
            'name' => 'LC Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'LC Supplier',
            'type' => PartnerType::Supplier,
        ]);
    }

    public function test_30_line_allocation_sums_exactly_to_input_costs(): void
    {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-LC-0001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);

        // 30 lines with awkward, non-round TND values to provoke float drift
        // and proportional-rounding residue.
        $subtotal = '0.000';
        for ($i = 1; $i <= 30; $i++) {
            $product = Product::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'sku' => 'LC-SKU-'.$i,
                'name' => 'LC Product '.$i,
                'type' => ProductType::Part,
                'is_active' => true,
            ]);

            // unit_price = 3.333 + i*1.111, qty = 1 + (i mod 3) → varied line totals
            $unitPrice = bcadd('3.333', bcmul((string) $i, '1.111', 3), 3);
            $quantity = (string) (1 + ($i % 3));
            $lineTotal = bcmul($quantity, $unitPrice, 3);
            $subtotal = bcadd($subtotal, $lineTotal, 3);

            DocumentLine::create([
                'document_id' => $po->id,
                'product_id' => $product->id,
                'product_code' => $product->sku,
                'line_number' => $i,
                'description' => $product->name,
                'quantity' => $quantity.'.0000',
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'allocated_costs' => '0.000',
            ]);
        }

        $po->update(['subtotal' => $subtotal, 'total' => $subtotal]);

        // Two additional-cost rows: freight + insurance. The sum of these is the
        // exact figure the allocations must reconcile back to.
        DocumentAdditionalCost::create([
            'document_id' => $po->id,
            'cost_type' => 'transport',
            'description' => 'Freight',
            'amount' => '137.500',
        ]);
        DocumentAdditionalCost::create([
            'document_id' => $po->id,
            'cost_type' => 'insurance',
            'description' => 'Insurance',
            'amount' => '42.875',
        ]);

        $inputCostsTotal = bcadd('137.500', '42.875', 3); // 180.375

        /** @var LandedCostService $service */
        $service = app(LandedCostService::class);
        $service->allocateCosts($po->fresh(['lines']) ?? $po);

        // Sum the persisted allocations at the TND boundary scale.
        $allocatedSum = '0.000';
        foreach (($po->fresh(['lines']) ?? $po)->lines as $line) {
            $allocatedSum = bcadd($allocatedSum, (string) $line->allocated_costs, 3);
        }

        // Exact reconciliation — no residue lost, no IEEE-754 drift.
        $this->assertSame(
            0,
            bccomp($inputCostsTotal, $allocatedSum, 3),
            "Allocated sum {$allocatedSum} must equal input costs {$inputCostsTotal} exactly",
        );
    }

    /**
     * P2-4 regression: the largest-remainder absorber must be located by VALUE
     * (last positive-base line), not by assuming a 0-based sequential key. When a
     * caller hands the service a non-zero-keyed collection (e.g. keyBy('id')),
     * the old `$index >= $lastIndex` check could match no line and silently drop
     * the residue. With ->values() re-keying + value-based absorber, the sum
     * still reconciles exactly.
     */
    public function test_allocation_reconciles_with_non_zero_keyed_line_collection(): void
    {
        $po = $this->makePurchaseOrder('PO-LC-KEYED');

        // Three awkward TND lines.
        $this->makeLine($po, 1, quantity: '2', unitPrice: '3.337'); // 6.674
        $this->makeLine($po, 2, quantity: '1', unitPrice: '11.119'); // 11.119
        $this->makeLine($po, 3, quantity: '3', unitPrice: '4.441'); // 13.323

        DocumentAdditionalCost::create([
            'document_id' => $po->id,
            'cost_type' => 'transport',
            'description' => 'Freight',
            'amount' => '100.001',
        ]);

        $fresh = $po->fresh(['lines']);
        $this->assertNotNull($fresh);

        // Force a NON-zero-keyed collection on the relation (keyed by UUID string).
        $fresh->setRelation('lines', $fresh->lines->keyBy('id'));

        /** @var LandedCostService $service */
        $service = app(LandedCostService::class);
        $service->allocateCosts($fresh);

        $allocatedSum = '0.000';
        $reloaded = $po->fresh(['lines']);
        $this->assertNotNull($reloaded);
        foreach ($reloaded->lines as $line) {
            $allocatedSum = bcadd($allocatedSum, (string) $line->allocated_costs, 3);
        }

        $this->assertSame(
            0,
            bccomp('100.001', $allocatedSum, 3),
            "Allocated sum {$allocatedSum} must equal input 100.001 exactly even with a keyed collection",
        );
    }

    /**
     * P2-5 regression: when the genuinely-last line has line_total 0, the residue
     * must NOT be dumped on it (which would inflate a zero-base line's landed unit
     * cost). The absorber is the last POSITIVE-base line, so the zero-base trailing
     * line receives 0 allocated cost while the sum still reconciles exactly.
     */
    public function test_zero_base_last_line_does_not_absorb_remainder(): void
    {
        $po = $this->makePurchaseOrder('PO-LC-ZEROLAST');

        $this->makeLine($po, 1, quantity: '1', unitPrice: '33.333'); // 33.333 (positive)
        $this->makeLine($po, 2, quantity: '1', unitPrice: '66.667'); // 66.667 (positive)
        $zeroLine = $this->makeLine($po, 3, quantity: '1', unitPrice: '0.000'); // 0.000 (free/promo, LAST)

        DocumentAdditionalCost::create([
            'document_id' => $po->id,
            'cost_type' => 'transport',
            'description' => 'Freight',
            'amount' => '50.000',
        ]);

        /** @var LandedCostService $service */
        $service = app(LandedCostService::class);
        $service->allocateCosts($po->fresh(['lines']) ?? $po);

        $reloaded = $po->fresh(['lines']);
        $this->assertNotNull($reloaded);

        $allocatedSum = '0.000';
        $zeroLineAllocated = null;
        foreach ($reloaded->lines as $line) {
            $allocatedSum = bcadd($allocatedSum, (string) $line->allocated_costs, 3);
            if ($line->id === $zeroLine->id) {
                $zeroLineAllocated = (string) $line->allocated_costs;
            }
        }

        // The zero-base trailing line gets nothing (residue went to a positive line).
        $this->assertNotNull($zeroLineAllocated);
        $this->assertSame(
            0,
            bccomp('0', $zeroLineAllocated, 3),
            "Zero-base last line must receive 0 allocated cost, got {$zeroLineAllocated}",
        );

        // And the total still reconciles exactly.
        $this->assertSame(
            0,
            bccomp('50.000', $allocatedSum, 3),
            "Allocated sum {$allocatedSum} must equal input 50.000 exactly",
        );
    }

    private function makePurchaseOrder(string $number): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $number,
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);
    }

    /**
     * @param  numeric-string  $quantity
     * @param  numeric-string  $unitPrice
     */
    private function makeLine(Document $po, int $lineNumber, string $quantity, string $unitPrice): DocumentLine
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'LC-SKU-'.$po->id.'-'.$lineNumber,
            'name' => 'LC Product '.$lineNumber,
            'type' => ProductType::Part,
            'is_active' => true,
        ]);

        $lineTotal = bcmul($quantity, $unitPrice, 3);

        return DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'line_number' => $lineNumber,
            'description' => $product->name,
            'quantity' => $quantity.'.0000',
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'allocated_costs' => '0.000',
        ]);
    }

    public function test_calculate_landed_unit_cost_truncates_non_terminating_fraction(): void
    {
        /** @var LandedCostService $service */
        $service = app(LandedCostService::class);

        // (100.000 + 0.000 + 0.000) / 3 = 33.3333… → bcmath boundary scale 3 = 33.333.
        $result = $service->calculateLandedUnitCost(
            lineTotal: 100.0,
            allocatedCost: 0.0,
            nonRecoverableTax: 0.0,
            quantity: 3.0,
        );

        $this->assertSame(0, bccomp('33.333', (string) $result, 3));
    }
}
