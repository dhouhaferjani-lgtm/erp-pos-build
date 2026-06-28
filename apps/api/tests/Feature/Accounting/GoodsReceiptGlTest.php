<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\GeneralLedgerHashService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Domain\Events\GoodsReceived;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GoodsReceiptGlTest — verifies that receiving goods posts Dr Inventory / Cr 408 (GR-IR).
 *
 * Tests covered:
 * 1. Single receipt posts a balanced JE Dr Inventory / Cr GoodsReceivedNotInvoiced
 * 2. Partial receipts accrue 408 incrementally (two entries total correct sum)
 * 3. Re-dispatching the same GoodsReceived (same movementId) is idempotent
 */
final class GoodsReceiptGlTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Account $inventoryAccount;

    private Account $grirAccount;

    private GeneralLedgerHashService $hashService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'GR-IR GL Test Tenant',
            'slug' => 'grir-gl-test-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GR-IR GL Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        // Seed Tunisia chart so Inventory + GoodsReceivedNotInvoiced purposes resolve.
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        /** @var Account $inventoryAccount */
        $inventoryAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Inventory);
        $this->inventoryAccount = $inventoryAccount;

        /** @var Account $grirAccount */
        $grirAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::GoodsReceivedNotInvoiced);
        $this->grirAccount = $grirAccount;

        $this->hashService = app(GeneralLedgerHashService::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Dispatch a GoodsReceived event for a single receipt line.
     */
    private function dispatchGoodsReceived(
        string $movementId,
        string $qty,
        string $unitCost,
    ): void {
        event(new GoodsReceived(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            productId: Str::uuid()->toString(),
            locationId: Str::uuid()->toString(),
            poLineId: Str::uuid()->toString(),
            movementId: $movementId,
            receivedQty: $qty,
            unitCost: $unitCost,
            currency: 'TND',
        ));
    }

    // =========================================================================
    // 1. Single receipt: Dr Inventory 50.000 / Cr 408 50.000
    // =========================================================================

    public function test_single_receipt_posts_gr_ir_dr_inventory_cr_408(): void
    {
        $movementId = Str::uuid()->toString();

        $this->dispatchGoodsReceived($movementId, '5.0000', '10.000');

        // One JE should exist for this movement
        $entry = JournalEntry::where('source_type', 'goods_receipt')
            ->where('source_id', $movementId)
            ->firstOrFail();

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);

        // Must have exactly 2 lines — no VAT leg
        $this->assertCount(2, $entry->lines);

        // Dr Inventory line
        $drLine = $entry->lines->where('account_id', $this->inventoryAccount->id)->first();
        $this->assertNotNull($drLine, 'Debit leg on Inventory account must exist');
        $this->assertSame('50.000', $drLine->debit);
        $this->assertSame('0.000', $drLine->credit);
        $this->assertNull($drLine->partner_id);

        // Cr GoodsReceivedNotInvoiced (408) line
        $crLine = $entry->lines->where('account_id', $this->grirAccount->id)->first();
        $this->assertNotNull($crLine, 'Credit leg on GoodsReceivedNotInvoiced (408) account must exist');
        $this->assertSame('0.000', $crLine->debit);
        $this->assertSame('50.000', $crLine->credit);
        $this->assertNull($crLine->partner_id);

        // Debits == Credits (TND scale 3, bcmath — no float)
        $totalDebit = '0.000';
        $totalCredit = '0.000';
        foreach ($entry->lines as $line) {
            $totalDebit = bcadd($totalDebit, $line->debit, 3);
            $totalCredit = bcadd($totalCredit, $line->credit, 3);
        }
        $this->assertSame('0.000', bcsub($totalDebit, $totalCredit, 3), 'Entry must balance');

        // Hash chain verifies
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
    }

    // =========================================================================
    // 1b. Half-up rounding at TND scale 3 diverges from truncation
    // =========================================================================

    public function test_half_up_rounding_tnd_scale_3_diverges_from_truncation(): void
    {
        // Arithmetic verification (must match production formula):
        //   bcmul('3.3335', '3', scale+2=5) = '10.00050'
        //   bcround('10.00050', 3) HALF-UP => '10.001'  (4th decimal is 5 → round up)
        //   bcadd('10.00050', '0', 3) truncate   => '10.000'  (diverges)
        // Only the HALF-UP path yields '10.001'.
        $movementId = Str::uuid()->toString();

        $this->dispatchGoodsReceived($movementId, '3.0000', '3.3335');

        $entry = JournalEntry::where('source_type', 'goods_receipt')
            ->where('source_id', $movementId)
            ->firstOrFail();

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);

        // Dr Inventory — must be exactly '10.001', not '10.000'
        $drLine = $entry->lines->where('account_id', $this->inventoryAccount->id)->first();
        $this->assertNotNull($drLine, 'Debit leg on Inventory account must exist');
        $this->assertSame('10.001', $drLine->debit);
        $this->assertSame('0.000', $drLine->credit);

        // Cr GoodsReceivedNotInvoiced (408) — same amount
        $crLine = $entry->lines->where('account_id', $this->grirAccount->id)->first();
        $this->assertNotNull($crLine, 'Credit leg on 408 account must exist');
        $this->assertSame('0.000', $crLine->debit);
        $this->assertSame('10.001', $crLine->credit);

        // Balance (bcmath, no float)
        $totalDebit = '0.000';
        $totalCredit = '0.000';
        foreach ($entry->lines as $line) {
            $totalDebit = bcadd($totalDebit, $line->debit, 3);
            $totalCredit = bcadd($totalCredit, $line->credit, 3);
        }
        $this->assertSame('0.000', bcsub($totalDebit, $totalCredit, 3), 'Entry must balance');

        // Hash chain verifies
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
    }

    // =========================================================================
    // 2. Partial receipts accrue 408 incrementally
    // =========================================================================

    public function test_partial_receipts_accrue_gr_ir_incrementally(): void
    {
        $movementId1 = Str::uuid()->toString();
        $movementId2 = Str::uuid()->toString();

        // First receipt: 3 units @ 10.000
        $this->dispatchGoodsReceived($movementId1, '3.0000', '10.000');

        // Second receipt: 2 units @ 10.000
        $this->dispatchGoodsReceived($movementId2, '2.0000', '10.000');

        // Two distinct JEs
        $entries = JournalEntry::where('source_type', 'goods_receipt')
            ->whereIn('source_id', [$movementId1, $movementId2])
            ->get();

        $this->assertCount(2, $entries, 'Two receipt events must produce two JEs');

        // Total Cr 408 across both entries should be 50.000 (bcmath, no float)
        $totalCredit408 = '0.000';
        foreach ($entries->flatMap(fn ($e) => $e->lines)->where('account_id', $this->grirAccount->id) as $line) {
            $totalCredit408 = bcadd($totalCredit408, $line->credit, 3);
        }
        $this->assertSame('50.000', $totalCredit408, 'Total 408 accrual must equal 50.000');

        // Each entry individually balances (bcmath, no float)
        foreach ($entries as $entry) {
            $this->assertSame(JournalEntryStatus::Posted, $entry->status);
            $totalDebit = '0.000';
            $totalCredit = '0.000';
            foreach ($entry->lines as $line) {
                $totalDebit = bcadd($totalDebit, $line->debit, 3);
                $totalCredit = bcadd($totalCredit, $line->credit, 3);
            }
            $this->assertSame('0.000', bcsub($totalDebit, $totalCredit, 3), "Entry {$entry->id} must balance");
        }

        // Full hash chain still verifies after two entries
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
    }

    // =========================================================================
    // 3. receiveGoods() emit path: precision-sensitive wiring is covered end-to-end
    // =========================================================================

    public function test_receive_goods_service_posts_gr_ir_journal_entry(): void
    {
        // WeightedAverageCostService::scale() calls getScale() with no explicit currency,
        // so CompanyContext must be bound (same pattern as GoodsReceiptTest setUp).
        app(CompanyContext::class)->setCompanyId($this->company->id);

        // Additional scaffolding required by GoodsReceiptService::receiveGoods().
        $location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-GRIR-01',
            'name' => 'GR-IR Test Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'GL Test Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-GRIR-GL',
            'name' => 'GR-IR GL Test Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'cost_price' => '0.000',
        ]);

        // Confirmed PO with one line: qty=5 @ unit_cost=10.000 → total=50.000
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $supplier->id,
            'location_id' => $location->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-GRIR-GL-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '50.000',
            'tax_amount' => '0.000',
            'total' => '50.000',
        ]);

        DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => '5.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'unit_price' => '10.000',
            'line_total' => '50.0000',
            'allocated_costs' => '0.0000',
        ]);

        /** @var Document $po */
        $po = $po->fresh(['lines']);
        $line = $po->lines->firstOrFail();

        // Drive the real GoodsReceiptService emit path (not dispatchGoodsReceived helper)
        $service = app(GoodsReceiptService::class);
        $service->receiveGoods($po, [$line->id => '5.0000']);

        // A GR-IR JE must have been posted via the GoodsReceived event listener
        $entry = JournalEntry::where('source_type', 'goods_receipt')
            ->where('company_id', $this->company->id)
            ->firstOrFail();

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertCount(2, $entry->lines);

        // Dr Inventory 50.000
        $drLine = $entry->lines->where('account_id', $this->inventoryAccount->id)->first();
        $this->assertNotNull($drLine, 'Debit leg on Inventory account must exist');
        $this->assertSame('50.000', $drLine->debit);
        $this->assertSame('0.000', $drLine->credit);

        // Cr GoodsReceivedNotInvoiced (408) 50.000
        $crLine = $entry->lines->where('account_id', $this->grirAccount->id)->first();
        $this->assertNotNull($crLine, 'Credit leg on 408 account must exist');
        $this->assertSame('0.000', $crLine->debit);
        $this->assertSame('50.000', $crLine->credit);

        // Hash chain verifies
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
    }

    // =========================================================================
    // 4. Idempotency: re-dispatching same movementId must not create a second JE
    // =========================================================================

    public function test_idempotent_on_same_movement_id(): void
    {
        $movementId = Str::uuid()->toString();

        $this->dispatchGoodsReceived($movementId, '5.0000', '10.000');
        $this->dispatchGoodsReceived($movementId, '5.0000', '10.000'); // same movementId again

        $count = JournalEntry::where('source_type', 'goods_receipt')
            ->where('source_id', $movementId)
            ->count();

        $this->assertSame(1, $count, 'Duplicate GoodsReceived with same movementId must not post a second JE');
    }
}
