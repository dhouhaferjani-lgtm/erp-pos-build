<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\ClearInstrumentData;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DeferredTenderGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    private PaymentRepository $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('admin');
        UserCompanyMembership::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->bank = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'bank_account',
            'currency' => 'TND',
            'balance' => '200.000',
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
        ]);
    }

    public function test_supplier_traite_keeps_cash_payment_shape_and_registers_outbound_instrument(): void
    {
        $invoice = $this->supplierInvoice('50.000');
        $method = $this->method(InstrumentKind::Effet);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $this->bank->id,
            'amount' => '50.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [['document_id' => $invoice->id, 'amount' => '50.000']],
            'instrument' => ['reference' => 'OUT-TR-50', 'maturity_date' => now()->addDays(20)->toDateString()],
        ])->assertCreated();

        $payment = Payment::query()->whereKey((string) $response->json('data.id'))->firstOrFail();
        $instrument = PaymentInstrument::query()->where('payment_id', $payment->id)->sole();
        $this->assertSame('outbound', $instrument->direction->value);
        $this->assertDatabaseCount('repository_movements', 1);
        $this->assertSame('150.000', $this->bank->fresh()?->balance);
        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);
        $this->assertSame('50.000', $entry->lines->firstWhere('account_id', $this->bank->gl_account_id)?->credit);
    }

    public function test_all_four_side_doors_reject_maturity_methods(): void
    {
        $method = $this->method(InstrumentKind::Cheque);
        $invoice = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'status' => DocumentStatus::Posted,
            'currency' => 'TND',
            'total' => '20.000',
            'balance_due' => '20.000',
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'document_id' => $invoice->id,
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'payments' => [[
                'payment_method_id' => $method->id,
                'repository_id' => $this->bank->id,
                'amount' => '20.000',
            ]],
        ])->assertUnprocessable();
        $this->actingAs($this->user)->postJson("/api/v1/documents/{$invoice->id}/split-payment", [
            'splits' => [
                ['payment_method_id' => $method->id, 'amount' => '10.000', 'repository_id' => $this->bank->id],
                ['payment_method_id' => $method->id, 'amount' => '10.000', 'repository_id' => $this->bank->id],
            ],
        ])->assertUnprocessable();
        $this->actingAs($this->user)->postJson('/api/v1/payments/deposit', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'amount' => '20.000',
            'currency' => 'TND',
            'repository_id' => $this->bank->id,
        ])->assertUnprocessable();
        $this->actingAs($this->user)->postJson('/api/v1/payments/on-account', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'amount' => '20.000',
            'currency' => 'TND',
            'repository_id' => $this->bank->id,
        ])->assertUnprocessable();
    }

    public function test_all_four_side_doors_still_accept_immediate_methods(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => false,
            'instrument_kind' => null,
        ]);
        $first = $this->customerInvoice('20.000');
        $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'document_id' => $first->id,
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'payments' => [[
                'payment_method_id' => $method->id,
                'repository_id' => $this->bank->id,
                'amount' => '20.000',
            ]],
        ])->assertCreated();

        $second = $this->customerInvoice('20.000');
        $this->actingAs($this->user)->postJson("/api/v1/documents/{$second->id}/split-payment", [
            'splits' => [
                ['payment_method_id' => $method->id, 'amount' => '10.000', 'repository_id' => $this->bank->id],
                ['payment_method_id' => $method->id, 'amount' => '10.000', 'repository_id' => $this->bank->id],
            ],
        ])->assertCreated();

        $this->actingAs($this->user)->postJson('/api/v1/payments/deposit', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'amount' => '5.000',
            'currency' => 'TND',
            'repository_id' => $this->bank->id,
        ])->assertCreated();
        $this->actingAs($this->user)->postJson('/api/v1/payments/on-account', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'amount' => '5.000',
            'currency' => 'TND',
            'repository_id' => $this->bank->id,
        ])->assertCreated();
    }

    public function test_supplier_maturity_method_with_other_kind_creates_no_instrument(): void
    {
        $invoice = $this->supplierInvoice('10.000');
        $method = $this->method(InstrumentKind::Other);

        $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $this->bank->id,
            'amount' => '10.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [['document_id' => $invoice->id, 'amount' => '10.000']],
        ])->assertCreated();

        $this->assertDatabaseCount('payment_instruments', 0);
        $this->assertDatabaseCount('repository_movements', 1);
    }

    public function test_pending_instrument_blocks_refund_partial_and_reverse_but_cleared_allows_refund(): void
    {
        $method = $this->method(InstrumentKind::Cheque);
        $service = app(PaymentRefundService::class);
        foreach (['full', 'partial', 'reverse'] as $operationName) {
            [$payment] = $this->pendingPayment($method, $operationName);
            try {
                match ($operationName) {
                    'full' => $service->refundPayment($payment, 'blocked'),
                    'partial' => $service->partialRefund($payment, '5.000', 'blocked'),
                    'reverse' => $service->reversePayment($payment, 'blocked'),
                };
                $this->fail('pending instrument must block the cash refund/reverse path');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('instrument', strtolower($exception->getMessage()));
            }
        }

        [$payment, $instrument] = $this->pendingPayment($method, 'cleared');
        $instrument->update(['status' => InstrumentStatus::Cleared]);
        $this->assertTrue($service->canRefund($payment->fresh() ?? $payment));
    }

    public function test_cleared_deferred_payment_can_refund_from_the_cleared_bank_repository(): void
    {
        $method = $this->method(InstrumentKind::Cheque);
        $invoice = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'status' => DocumentStatus::Posted,
            'currency' => 'TND',
            'total' => '30.000',
            'balance_due' => '30.000',
        ]);
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $this->bank->id,
            'amount' => '30.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [['document_id' => $invoice->id, 'amount' => '30.000']],
            'instrument' => ['reference' => 'CLEAR-THEN-REFUND'],
        ])->assertCreated();
        $payment = Payment::query()->whereKey((string) $response->json('data.id'))->firstOrFail();
        $instrument = PaymentInstrument::query()->where('payment_id', $payment->id)->sole();
        $lifecycle = app(InstrumentLifecycleService::class);
        $lifecycle->deposit($instrument->id, $this->bank->id, $this->user->id);
        $lifecycle->clear(new ClearInstrumentData($instrument->id, 'TND', userId: $this->user->id));

        $refund = app(PaymentRefundService::class)->refundPayment($payment->fresh() ?? $payment, 'after clear');

        $this->assertSame('-30.000', $refund->amount);
        $this->assertDatabaseHas('repository_movements', [
            'payment_repository_id' => $this->bank->id,
            'source_type' => 'refund',
            'source_id' => $payment->id,
            'direction' => 'out',
        ]);
    }

    public function test_payment_api_exposes_dishonored_at(): void
    {
        $payment = Payment::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'dishonored_at' => now(),
        ]);

        $this->actingAs($this->user)->getJson("/api/v1/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.dishonored_at', $payment->dishonored_at?->toIso8601String());
    }

    private function method(InstrumentKind $kind): PaymentMethod
    {
        return PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => true,
            'instrument_kind' => $kind,
        ]);
    }

    private function supplierInvoice(string $amount): Document
    {
        $invoice = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SupplierInvoice,
            'status' => DocumentStatus::Posted,
            'currency' => 'TND',
            'total' => $amount,
            'balance_due' => $amount,
        ]);
        app(GeneralLedgerService::class)->createSupplierInvoiceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            invoiceId: $invoice->id,
            totalAmount: $amount,
            netAmount: $amount,
            vatAmount: '0.000',
            expenseAccountId: Account::findByPurposeOrFail(
                $this->company->id,
                SystemAccountPurpose::PurchaseExpenses,
            )->id,
            date: now(),
            user: $this->user,
            currencyCode: 'TND',
        );

        return $invoice;
    }

    private function customerInvoice(string $amount): Document
    {
        return Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'status' => DocumentStatus::Posted,
            'currency' => 'TND',
            'total' => $amount,
            'balance_due' => $amount,
        ]);
    }

    /** @return array{Payment, PaymentInstrument} */
    private function pendingPayment(PaymentMethod $method, string $suffix): array
    {
        $payment = Payment::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $this->bank->id,
            'amount' => '30.000',
            'currency' => 'TND',
        ]);
        $instrument = PaymentInstrument::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $method->id,
            'payment_id' => $payment->id,
            'partner_id' => $this->partner->id,
            'reference' => 'PENDING-'.Str::upper($suffix),
            'amount' => '30.000',
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => InstrumentStatus::Received,
            'kind' => InstrumentKind::Cheque,
            'direction' => 'inbound',
            'origin' => 'web',
            'repository_id' => $this->bank->id,
        ]);
        $payment->update(['instrument_id' => $instrument->id]);

        return [$payment, $instrument];
    }
}
