<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Application\Services\ExpenseService;
use App\Modules\Expense\Domain\Enums\ExpenseKind;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExpenseServiceVatTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private ExpenseService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->service = app(ExpenseService::class);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_create_derives_vat_aware_subtotal_tax_and_default_deductible_percent(): void
    {
        $expense = $this->createExpense([
            'total' => '119.000',
            'vat_amount' => '19.000',
        ]);

        $this->assertSame('100.000', $expense->subtotal);
        $this->assertSame('19.000', $expense->tax_amount);
        $this->assertSame('100.00', $expense->expenseMetadata?->vat_deductible_percent);
    }

    public function test_create_without_vat_keeps_vatless_values_backward_compatible(): void
    {
        $expense = $this->createExpense(['total' => '119.000']);

        $this->assertSame('119.000', $expense->subtotal);
        $this->assertNull($expense->tax_amount);
        $this->assertNull($expense->expenseMetadata?->vat_rate);
        $this->assertNull($expense->expenseMetadata?->vat_deductible_percent);
    }

    public function test_create_normalizes_zero_vat_to_the_vatless_shape(): void
    {
        $vatless = $this->createExpense(['total' => '119.000']);
        $zeroVat = $this->createExpense([
            'total' => '119.000',
            'vat_amount' => '0',
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '50.00',
        ]);

        $this->assertSame($vatless->subtotal, $zeroVat->subtotal);
        $this->assertSame($vatless->tax_amount, $zeroVat->tax_amount);
        $this->assertSame($vatless->expenseMetadata?->vat_rate, $zeroVat->expenseMetadata?->vat_rate);
        $this->assertSame($vatless->expenseMetadata?->vat_deductible_percent, $zeroVat->expenseMetadata?->vat_deductible_percent);
    }

    public function test_create_honors_document_date_without_payment_date(): void
    {
        $expense = $this->createExpense([
            'total' => '25.000',
            'document_date' => '2026-08-01',
        ], includePaymentDate: false);

        $this->assertSame('2026-08-01', $expense->document_date->toDateString());
    }

    public function test_create_rejects_vat_on_a_linked_cost(): void
    {
        [$purchaseOrder, $supplierInvoice] = $this->linkedCostDocuments();

        $this->expectException(\DomainException::class);

        $this->createExpense([
            'total' => '119.000',
            'vat_amount' => '19.000',
            'expense_kind' => ExpenseKind::LinkedCost->value,
            'linked_invoice_id' => $supplierInvoice->id,
            'linked_operation_id' => $purchaseOrder->id,
        ]);
    }

    public function test_update_rejects_total_that_is_less_than_merged_stored_vat(): void
    {
        $expense = $this->createExpense([
            'total' => '100.000',
            'vat_amount' => '15.000',
        ]);

        $this->expectException(\DomainException::class);

        $this->service->update($expense, ['total' => '10.000']);
    }

    public function test_create_rejects_vat_equal_to_total(): void
    {
        $this->expectException(\DomainException::class);

        $this->createExpense([
            'total' => '19.000',
            'vat_amount' => '19.000',
        ]);
    }

    public function test_create_rejects_off_grid_total_for_company_currency(): void
    {
        $euroCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'EUR',
        ]);

        $this->expectException(\DomainException::class);

        $this->createExpense([
            'company_id' => $euroCompany->id,
            'total' => '119.005',
            'vat_amount' => '19.00',
        ]);
    }

    public function test_create_rejects_off_grid_vat_for_company_currency(): void
    {
        $euroCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'EUR',
        ]);

        $this->expectException(\DomainException::class);

        $this->createExpense([
            'company_id' => $euroCompany->id,
            'total' => '119.00',
            'vat_amount' => '19.005',
        ]);
    }

    public function test_create_does_not_normalize_off_grid_subminor_vat_to_zero(): void
    {
        $euroCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'EUR',
        ]);

        $this->expectException(\DomainException::class);

        $this->createExpense([
            'company_id' => $euroCompany->id,
            'total' => '119.00',
            'vat_amount' => '0.001',
        ]);
    }

    public function test_create_rejects_every_nonzero_fraction_for_zero_decimal_currency(): void
    {
        $yenCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'JPY',
        ]);

        $this->expectException(\DomainException::class);

        $this->createExpense([
            'company_id' => $yenCompany->id,
            'total' => '119.01',
            'vat_amount' => '19',
        ]);
    }

    public function test_create_rejects_off_grid_digits_beyond_one_extra_comparison_place(): void
    {
        $euroCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'EUR',
        ]);

        $this->expectException(\DomainException::class);

        $this->createExpense([
            'company_id' => $euroCompany->id,
            'total' => '119.0005',
            'vat_amount' => '19.00',
        ]);
    }

    public function test_update_clearing_vat_rederives_subtotal_and_clears_the_full_trio(): void
    {
        $expense = $this->createExpense([
            'total' => '119.000',
            'vat_amount' => '19.000',
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '50.00',
        ]);

        $updated = $this->service->update($expense, ['vat_amount' => null]);

        $this->assertSame('119.000', $updated->subtotal);
        $this->assertNull($updated->tax_amount);
        $this->assertNull($updated->expenseMetadata?->vat_rate);
        $this->assertNull($updated->expenseMetadata?->vat_deductible_percent);
    }

    public function test_update_changing_only_vat_rederives_subtotal_from_merged_total(): void
    {
        $expense = $this->createExpense([
            'total' => '119.000',
            'vat_amount' => '19.000',
        ]);

        $updated = $this->service->update($expense, ['vat_amount' => '9.000']);

        $this->assertSame('110.000', $updated->subtotal);
        $this->assertSame('9.000', $updated->tax_amount);
    }

    public function test_create_persists_partner_on_document(): void
    {
        $partner = Partner::factory()->supplier()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $expense = $this->createExpense([
            'total' => '25.000',
            'partner_id' => $partner->id,
        ]);

        $this->assertSame($partner->id, $expense->partner_id);
    }

    public function test_create_uses_explicit_company_when_no_company_context_is_bound(): void
    {
        app(CompanyContext::class)->clear();

        $expense = $this->createExpense(['total' => '25.000']);

        $this->assertSame($this->company->id, $expense->company_id);
        $this->assertSame('TND', $expense->currency);
    }

    public function test_update_rejects_vat_against_the_stored_linked_cost_kind(): void
    {
        $expense = $this->draftExpense([
            'total' => '100.000',
            'subtotal' => '100.000',
        ]);
        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'expense_kind' => ExpenseKind::LinkedCost,
            'is_paid' => false,
        ]);

        $this->expectException(\DomainException::class);

        $this->service->update($expense->load('expenseMetadata'), ['vat_amount' => '10.000']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createExpense(array $overrides = [], bool $includePaymentDate = true): Document
    {
        $data = [
            'company_id' => $this->company->id,
            'total' => '100.000',
            'is_paid' => false,
        ];

        if ($includePaymentDate) {
            $data['payment_date'] = '2026-07-13';
        }

        return $this->service->create(array_merge($data, $overrides), $this->user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function draftExpense(array $overrides = []): Document
    {
        return Document::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Draft,
            'document_number' => '',
            'document_date' => '2026-07-13',
            'currency' => 'TND',
            'subtotal' => '100.000',
            'total' => '100.000',
        ], $overrides));
    }

    /**
     * @return array{0: Document, 1: Document}
     */
    private function linkedCostDocuments(): array
    {
        $supplier = Partner::factory()->supplier()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $purchaseOrder = Document::factory()->purchaseOrder()->confirmed()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $supplier->id,
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
        ]);
        DocumentLine::create([
            'document_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => '1.0000',
            'unit_price' => '100.000',
            'line_total' => '100.000',
            'accrual_unit_cost' => '100.000000',
        ]);
        $supplierInvoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $supplier->id,
            'source_document_id' => $purchaseOrder->id,
            'type' => DocumentType::SupplierInvoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-VAT-LINK',
            'document_date' => '2026-07-13',
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
        ]);

        return [$purchaseOrder, $supplierInvoice];
    }
}
