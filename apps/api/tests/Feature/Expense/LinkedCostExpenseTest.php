<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentAdditionalCost;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Modules\Taxation\Domain\Repositories\VatDataRepositoryInterface;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class LinkedCostExpenseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $supplier;

    private Location $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Linked Cost Tenant',
            'slug' => 'linked-cost-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Linked Cost Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Linked Cost User',
            'email' => 'linked-cost-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'expenses.create',
            'expenses.post',
            'expenses.view',
            'purchase-orders.update',
            'documents.view',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'LC-WH',
            'name' => 'Linked Cost Warehouse',
            'type' => LocationType::Warehouse,
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Transport Supplier',
            'type' => PartnerType::Supplier,
        ]);
    }

    public function test_linked_purchase_cost_posts_sold_fraction_to_cogs_and_on_hand_fraction_to_wac(): void
    {
        [$po, $supplierInvoice, $product] = $this->receivedPoWithSupplierInvoice('10.0000', '10.000');

        /** @var WeightedAverageCostService $wac */
        $wac = app(WeightedAverageCostService::class);
        $wac->recordSale(
            product: $product,
            location: $this->warehouse,
            quantity: 6.0000,
            reference: 'SALE-AFTER-RECEIPT',
            referenceType: null,
            referenceId: 'sale-after-receipt',
        );

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => RepositoryType::CashRegister,
            'balance' => '500.000',
        ]);

        $create = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/expenses', [
                'vendor_name' => 'Freight cash desk',
                'total' => '100.000',
                'payment_date' => now()->toDateString(),
                'is_paid' => true,
                'payment_repository_id' => $repo->id,
                'expense_kind' => 'linked_cost',
                'linked_invoice_id' => $supplierInvoice->id,
                'linked_operation_id' => $po->id,
                'cost_type' => 'transport',
                'split_method' => 'by_value',
            ]);

        $create->assertCreated();
        $expenseId = $create->json('data.id');
        $this->assertIsString($expenseId);

        $cost = DocumentAdditionalCost::query()
            ->where('expense_document_id', $expenseId)
            ->firstOrFail();
        $this->assertSame($po->id, $cost->document_id);
        $this->assertSame('wac_adjustment', $cost->application_path->value);
        $this->assertNull($cost->applied_at);

        $post = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/expenses/{$expenseId}/post");

        $post->assertOk();
        $post->assertJsonPath('data.application.inventory_total', '40.000');
        $post->assertJsonPath('data.application.cogs_total', '60.000');

        $this->assertSame('400.000', $repo->fresh()->balance);
        $this->assertSame('20.000000', $product->fresh()?->cost_price);
        $this->assertNotNull($cost->fresh()?->applied_at);

        $inventoryAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Inventory);
        $cogsAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::CostOfGoodsSold);
        $cashAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Cash);

        $entry = JournalEntry::query()
            ->where('source_type', 'linked_cost_capitalization')
            ->where('source_id', $cost->id)
            ->with('lines')
            ->firstOrFail();

        $this->assertTrue($entry->lines->contains(fn ($line): bool => $line->account_id === $inventoryAccount->id && $line->debit === '40.000' && $line->credit === '0.000'));
        $this->assertTrue($entry->lines->contains(fn ($line): bool => $line->account_id === $cogsAccount->id && $line->debit === '60.000' && $line->credit === '0.000'));
        $this->assertTrue($entry->lines->contains(fn ($line): bool => $line->account_id === $cashAccount->id && $line->debit === '0.000' && $line->credit === '100.000'));

        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => 'adjustment',
            'reference_type' => DocumentAdditionalCost::class,
            'reference_id' => $cost->id,
            'total_cost' => '40.000000',
        ]);
    }

    public function test_partially_received_purchase_operation_is_rejected_in_phase_one(): void
    {
        [$po, $supplierInvoice] = $this->partiallyReceivedPoWithSupplierInvoice();

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => RepositoryType::CashRegister,
            'balance' => '500.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/expenses', [
                'vendor_name' => 'Freight cash desk',
                'total' => '100.000',
                'payment_date' => now()->toDateString(),
                'is_paid' => true,
                'payment_repository_id' => $repo->id,
                'expense_kind' => 'linked_cost',
                'linked_invoice_id' => $supplierInvoice->id,
                'linked_operation_id' => $po->id,
                'cost_type' => 'transport',
                'split_method' => 'by_value',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'OPERATION_PARTIALLY_RECEIVED');
        $this->assertDatabaseCount('document_additional_costs', 0);
    }

    public function test_reverse_linked_cost_restores_cash_posts_contra_and_marks_cost_reversed(): void
    {
        [$po, $supplierInvoice, $product] = $this->receivedPoWithSupplierInvoice('10.0000', '10.000');

        /** @var WeightedAverageCostService $wac */
        $wac = app(WeightedAverageCostService::class);
        $wac->recordSale($product, $this->warehouse, 6.0, 'SALE-AFTER-RECEIPT', null, 'sale-after-receipt');

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => RepositoryType::CashRegister,
            'balance' => '500.000',
        ]);

        $expense = $this->createLinkedExpense($po, $supplierInvoice, $repo);
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/expenses/{$expense->id}/post")
            ->assertOk();

        $cost = DocumentAdditionalCost::query()
            ->where('expense_document_id', $expense->id)
            ->firstOrFail();

        $reverse = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/expenses/{$expense->id}/reverse");

        $reverse->assertOk();
        $reverse->assertJsonPath('data.cash_reversed', true);

        $this->assertSame('500.000', $repo->fresh()->balance);
        $this->assertSame('10.000000', $product->fresh()?->cost_price);
        $this->assertNotNull($cost->fresh()?->reversed_at);

        $reversalCost = DocumentAdditionalCost::query()
            ->where('reverses_cost_id', $cost->id)
            ->firstOrFail();
        $this->assertSame('-100.000', $reversalCost->amount);

        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'linked_cost_capitalization_reversal',
            'source_id' => $reversalCost->id,
            'company_id' => $this->company->id,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => 'adjustment',
            'reference_type' => DocumentAdditionalCost::class,
            'reference_id' => $reversalCost->id,
            'total_cost' => '-40.000000',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/expenses/{$expense->id}/reverse")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ALREADY_REVERSED');
    }

    /**
     * V5 (2026-08-03 gate, docs/superpowers/reviews/2026-08-03-vat-declaration-gate.md):
     * ExpenseService::post()'s LinkedCost branch used to write NO
     * DocumentTaxDetail row at all, so a linked-cost expense's input VAT
     * could never reach the declaration. `assertVatInvariants()` currently
     * forbids VAT fields on a linked-cost expense AT CREATION time
     * ("landed-cost capitalization consumes the full amount") -- so this
     * simulates the ExpenseMetadata shape a future policy change or legacy
     * record could carry, by writing vat_rate/vat_deductible_percent onto
     * an already-created linked-cost expense's metadata directly, to prove
     * the LinkedCost branch's writer fires (mirrors the Generic branch
     * exactly) rather than remaining permanently dead code.
     */
    public function test_linked_cost_expense_snapshots_input_vat_when_metadata_carries_a_rate(): void
    {
        [$po, $supplierInvoice] = $this->receivedPoWithSupplierInvoice('10.0000', '10.000');

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => RepositoryType::CashRegister,
            'balance' => '500.000',
        ]);

        $expense = $this->createLinkedExpense($po, $supplierInvoice, $repo);
        // 100.000 total, 19% VAT -> subtotal 84.034 / tax_amount 15.966.
        $expense->update([
            'subtotal' => '84.034',
            'tax_amount' => '15.966',
        ]);
        $expense->expenseMetadata()->update([
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '100.00',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/expenses/{$expense->id}/post")
            ->assertOk();

        $detail = DocumentTaxDetail::query()->where('document_id', $expense->id)->first();
        $this->assertNotNull($detail, 'LinkedCost branch must snapshot input VAT when metadata carries a rate');
        $this->assertSame(TaxType::Percentage, $detail->tax_type);
        $this->assertSame('19.00', $detail->tax_rate);
        $this->assertSame('15.966', $detail->tax_amount);
        $this->assertFalse($detail->is_stamp_duty);
        /** @var numeric-string $detailBase */
        $detailBase = (string) $detail->tax_base;
        /** @var numeric-string $detailRate */
        $detailRate = (string) $detail->tax_rate;
        // Q2 ruling (docs/superpowers/tickets/2026-08-06-expert-comptable-rulings-q2-q3.md)
        // decorrelates the declared base from the deducted VAT amount for a
        // PARTIALLY-deductible expense -- base × rate == tax_amount is no
        // longer a general contract. This fixture is 100% deductible
        // (`vat_deductible_percent => '100.00'` above), where the full
        // facial base and the (now-abolished) deductible-proportion base
        // coincide, so the identity still holds HERE ONLY -- it pins the
        // LinkedCost branch's arithmetic at 100% deductible, not a
        // general base×rate contract.
        $this->assertSame(
            $detail->tax_amount,
            bcmul($detailBase, bcdiv($detailRate, '100', 6), 3),
            'at 100% deductible the full facial base and the deductible VAT amount coincide on the LinkedCost branch too',
        );

        // The declaration's INPUT side must now see this expense's VAT.
        $repository = app(VatDataRepositoryInterface::class);
        $aggregations = $repository->aggregateByRateAndDirection(
            $this->company->id,
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        );
        $inputBucket = collect($aggregations)->first(fn ($a) => $a->direction === 'INPUT' && $a->taxRate === '19.00');
        $this->assertNotNull($inputBucket, 'Declaration INPUT side must be non-zero for a linked-cost expense carrying VAT');
        $this->assertSame('15.966', $inputBucket->vatAmount);
    }

    /**
     * @return array{0: Document, 1: Document, 2: Product}
     */
    private function receivedPoWithSupplierInvoice(string $qty, string $unitPrice): array
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'LC-PROD-'.Str::random(6),
            'name' => 'Linked Cost Product',
            'type' => ProductType::Part,
            'is_physical' => true,
            'requires_batch_tracking' => false,
            'cost_price' => '0.000000',
        ]);

        $po = $this->makePurchaseOrder('PO-LC-FULL', bcmul($qty, $unitPrice, 3));
        DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => $qty,
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => $unitPrice,
            'line_total' => bcmul($qty, $unitPrice, 3),
            'allocated_costs' => '0.000000',
        ]);

        /** @var GoodsReceiptService $receipt */
        $receipt = app(GoodsReceiptService::class);
        $po = $po->fresh(['lines']);
        $this->assertNotNull($po);
        $receipt->receiveGoods($po, [$po->lines->first()->id => $qty]);

        $supplierInvoice = $this->makeSupplierInvoice($po);

        return [$po->fresh(['lines']) ?? $po, $supplierInvoice, $product->fresh() ?? $product];
    }

    /**
     * @return array{0: Document, 1: Document}
     */
    private function partiallyReceivedPoWithSupplierInvoice(): array
    {
        $receivedProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'LC-REC-'.Str::random(6),
            'name' => 'Received Product',
            'type' => ProductType::Part,
            'is_physical' => true,
            'requires_batch_tracking' => false,
        ]);
        $unreceivedProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'LC-OPEN-'.Str::random(6),
            'name' => 'Open Product',
            'type' => ProductType::Part,
            'is_physical' => true,
            'requires_batch_tracking' => false,
        ]);

        $po = $this->makePurchaseOrder('PO-LC-PARTIAL', '200.000');
        $receivedLine = DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $receivedProduct->id,
            'product_code' => $receivedProduct->sku,
            'line_number' => 1,
            'description' => $receivedProduct->name,
            'quantity' => '10.0000',
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '10.000',
            'line_total' => '100.000',
            'allocated_costs' => '0.000000',
        ]);
        DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $unreceivedProduct->id,
            'product_code' => $unreceivedProduct->sku,
            'line_number' => 2,
            'description' => $unreceivedProduct->name,
            'quantity' => '10.0000',
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '10.000',
            'line_total' => '100.000',
            'allocated_costs' => '0.000000',
        ]);

        /** @var GoodsReceiptService $receipt */
        $receipt = app(GoodsReceiptService::class);
        $receipt->receiveGoods($po->fresh(['lines']) ?? $po, [$receivedLine->id => '10.0000']);

        return [$po->fresh(['lines']) ?? $po, $this->makeSupplierInvoice($po)];
    }

    private function createLinkedExpense(Document $po, Document $supplierInvoice, PaymentRepository $repo): Document
    {
        $create = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/expenses', [
                'vendor_name' => 'Freight cash desk',
                'total' => '100.000',
                'payment_date' => now()->toDateString(),
                'is_paid' => true,
                'payment_repository_id' => $repo->id,
                'expense_kind' => 'linked_cost',
                'linked_invoice_id' => $supplierInvoice->id,
                'linked_operation_id' => $po->id,
                'cost_type' => 'transport',
                'split_method' => 'by_value',
            ]);

        $create->assertCreated();

        return Document::query()->findOrFail($create->json('data.id'));
    }

    private function makePurchaseOrder(string $numberPrefix, string $total): Document
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
            'document_number' => $numberPrefix.'-'.Str::random(5),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
        ]);
    }

    private function makeSupplierInvoice(Document $po): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'source_document_id' => $po->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-LC-'.Str::random(6),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => $po->subtotal,
            'tax_amount' => '0.000',
            'total' => $po->total,
        ]);
    }
}
