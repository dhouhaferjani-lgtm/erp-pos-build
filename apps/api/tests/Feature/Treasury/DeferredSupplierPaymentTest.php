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
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class DeferredSupplierPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $supplier;

    private Partner $customer;

    private PaymentRepository $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
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
        $this->supplier = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Supplier,
        ]);
        $this->customer = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Customer,
        ]);
        $this->bank = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => RepositoryType::BankAccount,
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
            'currency' => 'TND',
            'balance' => '500.000',
        ]);
    }

    public function test_deferred_supplier_cheque_posts_one_issue_entry_no_bank_line_and_no_movement(): void
    {
        $invoice = $this->supplierInvoice('100.000');
        $method = $this->method(InstrumentKind::Cheque);

        $response = $this->postPayment($this->supplierPayload($invoice, $method, 'CH-SUP-100'))
            ->assertCreated();
        $payment = Payment::query()->findOrFail((string) $response->json('data.id'));
        $instrument = PaymentInstrument::query()->where('payment_id', $payment->id)->sole();

        self::assertSame(InstrumentDirection::Outbound, $instrument->direction);
        self::assertSame(InstrumentStatus::Received, $instrument->status);
        self::assertSame($instrument->id, $payment->instrument_id);
        self::assertSame(1, JournalEntry::query()->where('source_type', 'instrument')->where('source_id', $instrument->id)->count());
        $entry = JournalEntry::query()->with('lines.account')->findOrFail($payment->journal_entry_id);
        $supplierPayableLine = $entry->lines->firstWhere('account.code', '401');
        self::assertNotNull($supplierPayableLine);
        self::assertSame('100.000', $supplierPayableLine->debit);
        self::assertSame($this->supplier->id, $supplierPayableLine->partner_id);
        self::assertSame('100.000', $entry->lines->firstWhere('account.code', '4035')?->credit);
        self::assertSame(0, $entry->lines->where('account_id', $this->bank->gl_account_id)->count());
        self::assertSame(0, RepositoryMovement::query()->where('source_id', $payment->id)->count());
        self::assertSame('500.000', $this->bank->fresh()?->balance);
        self::assertNotNull(InstrumentEvent::query()->where('action_key', "instrument:{$instrument->id}:issue")->first());
    }

    public function test_deferred_supplier_effet_credits_effets_payable(): void
    {
        $invoice = $this->supplierInvoice('75.000');
        $method = $this->method(InstrumentKind::Effet);
        $payload = $this->supplierPayload($invoice, $method, 'EF-SUP-75');
        $payload['instrument']['maturity_date'] = '2026-09-30';

        $response = $this->postPayment($payload)->assertCreated();
        $payment = Payment::query()->findOrFail((string) $response->json('data.id'));
        $entry = JournalEntry::query()->with('lines.account')->findOrFail($payment->journal_entry_id);

        self::assertSame('75.000', $entry->lines->firstWhere('account.code', '403')?->credit);
        self::assertSame(0, $entry->lines->where('account_id', $this->bank->gl_account_id)->count());
        self::assertDatabaseCount('repository_movements', 0);
    }

    public function test_deferred_supplier_rejects_a_non_bank_repository(): void
    {
        $invoice = $this->supplierInvoice('40.000');
        $method = $this->method(InstrumentKind::Cheque);
        $repository = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $this->bank->gl_account_id,
            'currency' => 'TND',
            'balance' => '100.000',
        ]);
        $payload = $this->supplierPayload($invoice, $method, 'CH-NOT-BANK');
        $payload['repository_id'] = $repository->id;

        $this->postPayment($payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_OUTBOUND_REPOSITORY');
        self::assertDatabaseCount('payments', 0);
        self::assertDatabaseCount('payment_instruments', 0);
    }

    public function test_deferred_customer_and_immediate_supplier_regressions_keep_existing_shapes(): void
    {
        $customerInvoice = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'currency' => 'TND',
            'total' => '30.000',
            'balance_due' => '30.000',
        ]);
        $customerMethod = $this->method(InstrumentKind::Cheque);
        $customerResponse = $this->postPayment([
            'partner_id' => $this->customer->id,
            'payment_method_id' => $customerMethod->id,
            'repository_id' => $this->bank->id,
            'amount' => '30.000',
            'currency' => 'TND',
            'payment_date' => '2026-07-18',
            'allocations' => [['document_id' => $customerInvoice->id, 'amount' => '30.000']],
            'instrument' => ['reference' => 'CH-CUST-30'],
        ])->assertCreated();
        $customerPayment = Payment::query()->findOrFail((string) $customerResponse->json('data.id'));
        $customerEntry = JournalEntry::query()->with('lines.account')->findOrFail($customerPayment->journal_entry_id);
        self::assertSame('30.000', $customerEntry->lines->firstWhere('account.code', '5312')?->debit);
        self::assertSame(0, RepositoryMovement::query()->where('source_id', $customerPayment->id)->count());

        $supplierInvoice = $this->supplierInvoice('45.000');
        $immediateMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => false,
            'instrument_kind' => null,
        ]);
        $supplierResponse = $this->postPayment([
            'partner_id' => $this->supplier->id,
            'payment_method_id' => $immediateMethod->id,
            'repository_id' => $this->bank->id,
            'amount' => '45.000',
            'currency' => 'TND',
            'payment_date' => '2026-07-18',
            'allocations' => [['document_id' => $supplierInvoice->id, 'amount' => '45.000']],
        ])->assertCreated();
        $supplierPayment = Payment::query()->findOrFail((string) $supplierResponse->json('data.id'));
        $supplierEntry = JournalEntry::query()->with('lines.account')->findOrFail($supplierPayment->journal_entry_id);
        self::assertSame('45.000', $supplierEntry->lines->firstWhere('account.code', '401')?->debit);
        self::assertSame('45.000', $supplierEntry->lines->firstWhere('account_id', $this->bank->gl_account_id)?->credit);
        self::assertSame(1, RepositoryMovement::query()->where('source_id', $supplierPayment->id)->count());
    }

    public function test_same_idempotency_header_does_not_create_a_second_issue_entry_or_instrument(): void
    {
        $invoice = $this->supplierInvoice('60.000');
        $payload = $this->supplierPayload($invoice, $this->method(InstrumentKind::Cheque), 'CH-IDEMP-60');

        $first = $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'deferred-supplier-issue-001')
            ->postJson('/api/v1/payments', $payload)
            ->assertCreated();
        $second = $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'deferred-supplier-issue-001')
            ->postJson('/api/v1/payments', $payload)
            ->assertOk();

        self::assertSame($first->json('data.id'), $second->json('data.id'));
        self::assertSame(1, Payment::query()->where('idempotency_key', 'deferred-supplier-issue-001')->count());
        self::assertDatabaseCount('payment_instruments', 1);
        self::assertSame(1, JournalEntry::query()->where('source_type', 'instrument')->count());
        self::assertSame(1, InstrumentEvent::query()->whereNotNull('action_key')->count());
    }

    private function supplierInvoice(string $amount): Document
    {
        $invoice = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Posted,
            'currency' => 'TND',
            'subtotal' => $amount,
            'tax_amount' => '0.000',
            'total' => $amount,
            'balance_due' => $amount,
        ]);
        DB::transaction(function () use ($invoice, $amount): void {
            app(GeneralLedgerService::class)->createSupplierInvoiceJournalEntry(
                companyId: $this->company->id,
                partnerId: $this->supplier->id,
                invoiceId: $invoice->id,
                totalAmount: $amount,
                netAmount: $amount,
                vatAmount: '0.000',
                expenseAccountId: Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchaseExpenses)->id,
                date: new \DateTimeImmutable('2026-07-18'),
                user: $this->user,
                description: 'Deferred supplier test invoice',
                currencyCode: 'TND',
            );
        });

        return $invoice;
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

    /** @return array<string, mixed> */
    private function supplierPayload(Document $invoice, PaymentMethod $method, string $reference): array
    {
        return [
            'partner_id' => $this->supplier->id,
            'payment_method_id' => $method->id,
            'repository_id' => $this->bank->id,
            'amount' => $invoice->balance_due,
            'currency' => 'TND',
            'payment_date' => '2026-07-18',
            'allocations' => [['document_id' => $invoice->id, 'amount' => $invoice->balance_due]],
            'instrument' => ['reference' => $reference],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function postPayment(array $payload): TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/v1/payments', $payload);
    }
}
