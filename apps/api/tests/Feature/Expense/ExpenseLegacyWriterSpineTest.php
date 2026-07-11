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
use App\Modules\Expense\Application\Services\ExpenseService;
use App\Modules\Expense\Domain\Enums\ExpenseKind;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Exceptions\RepositoryFrozenException;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 21.5 — the LAST TWO legacy balance-writers (LinkedCost outflow in post()
 * and the inflow in reverse()) are migrated off RepositoryOutflow/InflowService
 * onto the treasury movement port. This test pins the migrated behaviour:
 *  - post() of a PAID linked-cost expense records ONE `out` movement keyed on the
 *    `:linked_cost` leg, linked to the capitalization JE, WITHOUT double-crediting
 *    cash (the capitalization JE still credits cash exactly once).
 *  - reverse() records ONE `in` movement keyed on the `:reversal` leg, linked to
 *    the reversal JE.
 *  - both drivers are atomic: a movement failure (frozen repository) rolls the GL
 *    post back with it.
 */
final class ExpenseLegacyWriterSpineTest extends TestCase
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
            'name' => 'Legacy Writer Tenant',
            'slug' => 'legacy-writer-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Legacy Writer Company',
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
            'name' => 'Legacy Writer User',
            'email' => 'legacy-writer-'.Str::random(8).'@example.test',
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
            'code' => 'LW-WH',
            'name' => 'Legacy Writer Warehouse',
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

    /**
     * (a) A PAID linked-cost expense post decrements the repository balance via
     * ONE movement (source_type=expense, direction=out, idempotency leg
     * `:linked_cost`) linked to the capitalization JE — and that JE still credits
     * Cash exactly once (no double-credit).
     */
    public function test_linked_cost_post_records_one_out_movement_linked_to_capitalization_je_without_double_crediting_cash(): void
    {
        [$po, $supplierInvoice] = $this->receivedPoWithSupplierInvoice('10.0000', '10.000');

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => RepositoryType::CashRegister,
            'balance' => '500.000',
            'currency' => 'TND',
        ]);

        $expense = $this->createLinkedExpense($po, $supplierInvoice, $repo);
        app(ExpenseService::class)->post($expense, $this->user);

        // Balance decremented through the port exactly once: 500 - 100 = 400.
        $this->assertSame('400.000', $repo->fresh()?->balance);

        $cost = DocumentAdditionalCost::query()
            ->where('expense_document_id', $expense->id)
            ->firstOrFail();

        $capEntry = JournalEntry::query()
            ->where('source_type', 'linked_cost_capitalization')
            ->where('source_id', $cost->id)
            ->with('lines')
            ->firstOrFail();

        // Exactly ONE expense movement, out, keyed on the `:linked_cost` leg,
        // linked to the capitalization JE.
        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'expense')
            ->get();
        $this->assertCount(1, $movements);
        $movement = $movements->first();
        $this->assertNotNull($movement);
        $this->assertSame('out', $movement->direction);
        $this->assertSame($expense->id, $movement->source_id);
        $this->assertStringEndsWith(':linked_cost', (string) $movement->idempotency_key);
        $this->assertSame($capEntry->id, $movement->journal_entry_id);

        // The capitalization JE credits Cash EXACTLY ONCE, for the full total —
        // the movement did not re-post cash (no double-credit).
        $cashAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Cash);
        $cashCredits = $capEntry->lines
            ->filter(fn ($line): bool => $line->account_id === $cashAccount->id && bccomp((string) $line->credit, '0', 3) > 0);
        $this->assertCount(1, $cashCredits);
        $this->assertSame('100.000', $cashCredits->first()?->credit);
    }

    /**
     * (b) reverse() of a paid linked-cost expense returns cash to the repository
     * via ONE `in` movement (idempotency leg `:reversal`), linked to the reversal
     * JE.
     */
    public function test_reverse_records_one_in_movement_linked_to_reversal_je(): void
    {
        [$po, $supplierInvoice] = $this->receivedPoWithSupplierInvoice('10.0000', '10.000');

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => RepositoryType::CashRegister,
            'balance' => '500.000',
            'currency' => 'TND',
        ]);

        $expense = $this->createLinkedExpense($po, $supplierInvoice, $repo);
        app(ExpenseService::class)->post($expense, $this->user);
        $this->assertSame('400.000', $repo->fresh()?->balance);

        $result = app(ExpenseService::class)->reverse($expense->fresh() ?? $expense, $this->user);
        $this->assertTrue($result['cash_reversed']);

        // Cash returned to the repository through the port: 400 + 100 = 500.
        $this->assertSame('500.000', $repo->fresh()?->balance);

        $reversalCost = DocumentAdditionalCost::query()
            ->where('expense_document_id', $result['reversal_expense_id'])
            ->whereNotNull('reverses_cost_id')
            ->firstOrFail();

        $reversalEntry = JournalEntry::query()
            ->where('source_type', 'linked_cost_capitalization_reversal')
            ->where('source_id', $reversalCost->id)
            ->with('lines')
            ->firstOrFail();
        $this->assertSame($reversalEntry->id, $result['gl_entry_id']);

        // The `in` movement carries the `:reversal` leg and links the reversal JE.
        $inMovement = DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'expense')
            ->where('direction', 'in')
            ->first();
        $this->assertNotNull($inMovement);
        $this->assertStringEndsWith(':reversal', (string) $inMovement->idempotency_key);
        $this->assertSame($reversalEntry->id, $inMovement->journal_entry_id);
        $this->assertSame($expense->id, $inMovement->source_id);

        // The reversal JE DEBITS Cash (money returns to the books) exactly once.
        $cashAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Cash);
        $cashDebits = $reversalEntry->lines
            ->filter(fn ($line): bool => $line->account_id === $cashAccount->id && bccomp((string) $line->debit, '0', 3) > 0);
        $this->assertCount(1, $cashDebits);
        $this->assertSame('100.000', $cashDebits->first()?->debit);
    }

    /**
     * (c-post) Atomicity: a frozen repository makes the `out` movement throw AFTER
     * the capitalization JE was posted in-transaction, so the whole post rolls
     * back — no movement, no capitalization JE, balance unchanged, expense Draft.
     */
    public function test_post_movement_failure_rolls_back_the_capitalization_gl(): void
    {
        [$po, $supplierInvoice] = $this->receivedPoWithSupplierInvoice('10.0000', '10.000');

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => RepositoryType::CashRegister,
            'balance' => '500.000',
            'currency' => 'TND',
            'frozen_at' => now(),
            'frozen_reason' => 'reconciliation',
        ]);

        $expense = $this->createLinkedExpense($po, $supplierInvoice, $repo);

        try {
            app(ExpenseService::class)->post($expense->fresh() ?? $expense, $this->user);
            $this->fail('Expected RepositoryFrozenException — the frozen repository must reject the out movement.');
        } catch (RepositoryFrozenException) {
            // expected
        }

        $this->assertSame('500.000', $repo->fresh()?->balance);
        $this->assertSame(0, DB::table('repository_movements')->where('payment_repository_id', $repo->id)->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'linked_cost_capitalization')->count());
        $freshExpense = $expense->fresh();
        $this->assertNotNull($freshExpense);
        $this->assertSame(DocumentStatus::Draft, $freshExpense->status);
    }

    /**
     * (c-reverse) Atomicity: freeze the repository AFTER a clean post, then
     * reverse. The `in` movement throws after the reversal JE was posted
     * in-transaction, so the reversal rolls back entirely — no reversal JE, no
     * `in` movement, the original cost stays un-reversed, balance unchanged.
     */
    public function test_reverse_movement_failure_rolls_back_the_reversal_gl(): void
    {
        [$po, $supplierInvoice] = $this->receivedPoWithSupplierInvoice('10.0000', '10.000');

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => RepositoryType::CashRegister,
            'balance' => '500.000',
            'currency' => 'TND',
        ]);

        $expense = $this->createLinkedExpense($po, $supplierInvoice, $repo);
        app(ExpenseService::class)->post($expense, $this->user);
        $this->assertSame('400.000', $repo->fresh()?->balance);

        $originalCost = DocumentAdditionalCost::query()
            ->where('expense_document_id', $expense->id)
            ->whereNull('reverses_cost_id')
            ->firstOrFail();

        // Freeze the repository so the reversal's inflow movement is rejected.
        // frozen_at/frozen_reason are port-managed (not fillable) — set directly.
        $repo->frozen_at = now();
        $repo->frozen_reason = 'reconciliation';
        $repo->save();

        try {
            app(ExpenseService::class)->reverse($expense->fresh() ?? $expense, $this->user);
            $this->fail('Expected RepositoryFrozenException — the frozen repository must reject the in movement.');
        } catch (RepositoryFrozenException) {
            // expected
        }

        // Reversal rolled back with the movement: balance still 400, no reversal
        // JE, no `in` movement, original cost not marked reversed.
        $this->assertSame('400.000', $repo->fresh()?->balance);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'linked_cost_capitalization_reversal')->count());
        $this->assertSame(0, DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('direction', 'in')
            ->count());
        $this->assertNull($originalCost->fresh()?->reversed_at);
    }

    // -------------------------------------------------------------------------
    // Fixtures (mirrored from LinkedCostExpenseTest)
    // -------------------------------------------------------------------------

    /**
     * @return array{0: Document, 1: Document}
     */
    private function receivedPoWithSupplierInvoice(string $qty, string $unitPrice): array
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'LW-PROD-'.Str::random(6),
            'name' => 'Legacy Writer Product',
            'type' => ProductType::Part,
            'is_physical' => true,
            'requires_batch_tracking' => false,
            'cost_price' => '0.000000',
        ]);

        $po = $this->makePurchaseOrder('PO-LW', bcmul($qty, $unitPrice, 3));
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
        $expense = Document::query()->findOrFail($create->json('data.id'));
        $this->assertSame(ExpenseKind::LinkedCost, $expense->expenseMetadata?->expense_kind);

        return $expense;
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
            'document_number' => 'SI-LW-'.Str::random(6),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => $po->subtotal,
            'tax_amount' => '0.000',
            'total' => $po->total,
        ]);
    }
}
