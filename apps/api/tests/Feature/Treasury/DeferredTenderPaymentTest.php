<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class DeferredTenderPaymentTest extends TestCase
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
            'balance' => '0.000',
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
        ]);
    }

    public function test_traite_payment_creates_linked_instrument_posts_413_and_moves_no_cash(): void
    {
        $invoice = $this->invoice('100.000');
        $method = $this->method(InstrumentKind::Effet);

        $response = $this->pay($method, '100.000', $invoice, [
            'instrument' => [
                'reference' => 'TR-100',
                'maturity_date' => now()->addDays(30)->toDateString(),
            ],
        ])->assertCreated();

        $payment = Payment::query()->whereKey((string) $response->json('data.id'))->firstOrFail();
        $instrument = PaymentInstrument::query()->where('payment_id', $payment->id)->sole();
        $this->assertSame(InstrumentKind::Effet, $instrument->kind);
        $this->assertSame($instrument->id, $payment->instrument_id);
        $entry = JournalEntry::query()->with('lines.account')->findOrFail($payment->journal_entry_id);
        $this->assertSame('100.000', $entry->lines->firstWhere('account.code', '413')?->debit);
        $this->assertDatabaseCount('repository_movements', 0);
        $this->assertSame('0.000', $this->bank->fresh()?->balance);
        $this->assertSame(DocumentStatus::Paid, $invoice->fresh()?->status);
    }

    public function test_cheque_excess_posts_both_payment_and_advance_debits_to_portfolio(): void
    {
        $invoice = $this->invoice('100.000');
        $method = $this->method(InstrumentKind::Cheque);
        $response = $this->pay($method, '110.000', $invoice, [
            'instrument' => ['reference' => 'CH-110'],
        ])->assertCreated();
        $paymentId = (string) $response->json('data.id');

        $debits = JournalEntry::query()->whereIn('source_type', ['customer_payment', 'advance'])
            ->where('source_id', $paymentId)->with('lines.account')->get()
            ->flatMap->lines->where('account.code', '5312')
            ->reduce(fn (string $sum, $line): string => bcadd($sum, $line->debit, 3), '0');
        $this->assertSame('110.000', $debits);
        $this->assertDatabaseCount('repository_movements', 0);
        $this->assertSame('110.000', PaymentInstrument::query()->where('payment_id', $paymentId)->sole()->amount);
    }

    public function test_deferred_customer_posts_portfolio_entries_with_unledgered_custody_repository(): void
    {
        $this->bank = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'safe',
            'currency' => 'TND',
            'balance' => '0.000',
            'gl_account_id' => null,
        ]);
        $invoice = $this->invoice('100.000');
        $method = $this->method(InstrumentKind::Cheque);

        $response = $this->pay($method, '110.000', $invoice, [
            'instrument' => ['reference' => 'CH-CUSTODY-110'],
        ])->assertCreated();
        $paymentId = (string) $response->json('data.id');

        $debits = JournalEntry::query()->whereIn('source_type', ['customer_payment', 'advance'])
            ->where('source_id', $paymentId)->with('lines.account')->get()
            ->flatMap->lines->where('account.code', '5312')
            ->reduce(fn (string $sum, $line): string => bcadd($sum, $line->debit, 3), '0');

        $this->assertSame('110.000', $debits);
        $this->assertDatabaseCount('repository_movements', 0);
        $this->assertSame('0.000', $this->bank->fresh()?->balance);
        $this->assertSame(DocumentStatus::Paid, $invoice->fresh()?->status);
    }

    public function test_deferred_payment_requires_repository_and_rejects_withholding(): void
    {
        $invoice = $this->invoice('20.000');
        $method = $this->method(InstrumentKind::Cheque);
        $payload = $this->payload($method, '20.000', $invoice, ['instrument' => ['reference' => 'CH-20']]);
        unset($payload['repository_id']);
        $this->actingAs($this->user)->postJson('/api/v1/payments', $payload)->assertUnprocessable();

        $this->pay($method, '20.000', $invoice, [
            'instrument' => ['reference' => 'CH-WHT'],
            'withholding_enabled' => true,
        ])->assertUnprocessable();
    }

    public function test_supplied_instrument_must_be_received_unlinked_and_partner_matching(): void
    {
        $invoice = $this->invoice('25.000');
        $method = $this->method(InstrumentKind::Cheque);
        $instrument = PaymentInstrument::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $method->id,
            'partner_id' => $this->partner->id,
            'reference' => 'ALREADY-DEPOSITED',
            'amount' => '25.000',
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => InstrumentStatus::Deposited,
            'kind' => InstrumentKind::Cheque,
            'direction' => InstrumentDirection::Inbound,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $this->bank->id,
        ]);

        $this->pay($method, '25.000', $invoice, ['instrument_id' => $instrument->id])
            ->assertUnprocessable();
    }

    public function test_immediate_cash_payment_keeps_bank_je_and_one_movement(): void
    {
        $invoice = $this->invoice('30.000');
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => false,
            'instrument_kind' => null,
        ]);
        $response = $this->pay($method, '30.000', $invoice)->assertCreated();
        $payment = Payment::query()->whereKey((string) $response->json('data.id'))->firstOrFail();
        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);

        $this->assertSame('30.000', $entry->lines->firstWhere('account_id', $this->bank->gl_account_id)?->debit);
        $this->assertDatabaseCount('repository_movements', 1);
        $this->assertNull($payment->instrument_id);
    }

    public function test_maturity_method_with_other_kind_keeps_immediate_cash_path(): void
    {
        $invoice = $this->invoice('15.000');
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Other,
        ]);

        $response = $this->pay($method, '15.000', $invoice)->assertCreated();
        $payment = Payment::query()->whereKey((string) $response->json('data.id'))->firstOrFail();
        $this->assertNull($payment->instrument_id);
        $this->assertDatabaseCount('payment_instruments', 0);
        $this->assertDatabaseCount('repository_movements', 1);
    }

    public function test_gl_failure_rolls_back_instrument_payment_allocation_and_links(): void
    {
        $invoice = $this->invoice('40.000');
        $method = $this->method(InstrumentKind::Cheque);
        Account::query()->whereKey(Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::CustomerReceivable,
        )->id)->delete();

        try {
            $this->withoutExceptionHandling();
            $this->pay($method, '40.000', $invoice, ['instrument' => ['reference' => 'ROLLBACK-CH']]);
            $this->fail('missing receivable account must abort the payment transaction');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_instruments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertSame('40.000', $invoice->fresh()?->balance_due);
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

    private function invoice(string $amount): Document
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

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function pay(PaymentMethod $method, string $amount, Document $invoice, array $extra = []): TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/v1/payments', $this->payload($method, $amount, $invoice, $extra));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(PaymentMethod $method, string $amount, Document $invoice, array $extra = []): array
    {
        return array_merge([
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $this->bank->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [['document_id' => $invoice->id, 'amount' => $invoice->balance_due]],
        ], $extra);
    }
}
