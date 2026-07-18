<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Domain\Enums\ExpenseKind;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\InstrumentAccountResolver;
use App\Modules\Treasury\Application\Services\OutboundInstrumentService;
use App\Modules\Treasury\Domain\Bank;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentEventType;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ExpensePayByInstrumentTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{tenant: Tenant, company: Company, user: User, partner: Partner, bank: Bank, repository: PaymentRepository, method: PaymentMethod} */
    private array $context;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $user->givePermissionTo(['expenses.pay', 'expenses.view']);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'accountant',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $bank = Bank::create([
            'tenant_id' => $tenant->id,
            'country_code' => 'TN',
            'name' => 'Banque de test',
            'is_active' => true,
            'is_custom' => true,
            'position' => 0,
        ]);
        $repository = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => RepositoryType::BankAccount,
            'bank_id' => $bank->id,
            'gl_account_id' => Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Bank)->id,
            'currency' => 'TND',
            'balance' => '500.000',
        ]);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'EXP-CHEQUE-'.Str::upper(Str::random(6)),
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);

        $this->context = compact('tenant', 'company', 'user', 'partner', 'bank', 'repository', 'method');
    }

    public function test_instrument_settlement_posts_issue_entry_without_moving_cash_and_links_unpaid_expense(): void
    {
        $expense = $this->expense();

        $this->payByInstrument($expense)->assertOk()
            ->assertJsonPath('data.metadata.is_paid', false)
            ->assertJsonPath('data.metadata.payment_instrument_id', fn (mixed $id): bool => is_string($id));

        $metadata = ExpenseMetadata::query()->where('document_id', $expense->id)->firstOrFail();
        self::assertFalse($metadata->is_paid);
        self::assertNull($metadata->paid_at);
        self::assertNotNull($metadata->payment_instrument_id);

        $instrument = PaymentInstrument::query()->findOrFail($metadata->payment_instrument_id);
        self::assertSame(InstrumentDirection::Outbound, $instrument->direction);
        self::assertSame(InstrumentStatus::Received, $instrument->status);
        self::assertSame(InstrumentKind::Cheque, $instrument->kind);
        self::assertSame($this->context['repository']->id, $instrument->repository_id);
        self::assertSame("expense:{$expense->id}:settlement:instrument:1", $instrument->idempotency_key);
        self::assertSame(0, RepositoryMovement::query()->where('source_id', $instrument->id)->count());
        self::assertSame('500.000', $this->context['repository']->fresh()?->balance);

        $issue = JournalEntry::query()->with('lines')
            ->where('source_type', 'instrument')
            ->where('source_id', $instrument->id)
            ->sole();
        self::assertCount(2, $issue->lines);
        $supplierPayable = Account::findByPurposeOrFail($this->context['company']->id, SystemAccountPurpose::SupplierPayable);
        $checksToPay = app(InstrumentAccountResolver::class)->resolveOrFail(
            InstrumentAccountPurpose::ChecksToPay,
            $this->context['company']->id,
        );
        self::assertSame('125.000', $issue->lines->firstWhere('account_id', $supplierPayable->id)?->debit);
        self::assertSame('125.000', $issue->lines->firstWhere('account_id', $checksToPay)?->credit);
        self::assertSame(1, InstrumentEvent::query()->where('action_key', "instrument:{$instrument->id}:issue")->count());
    }

    public function test_effet_settlement_uses_effets_payable_and_preserves_maturity(): void
    {
        $expense = $this->expense();
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->context['tenant']->id,
            'company_id' => $this->context['company']->id,
            'code' => 'EXP-EFFET-'.Str::upper(Str::random(6)),
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Effet,
        ]);

        $this->actingAs($this->context['user'], 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/pay", [
                'mode' => 'instrument',
                'payment_repository_id' => $this->context['repository']->id,
                'payment_method_id' => $method->id,
                'payment_date' => '2026-07-18',
                'instrument' => [
                    'kind' => InstrumentKind::Effet->value,
                    'reference' => 'EFF-EXP-001',
                    'bank_id' => $this->context['bank']->id,
                    'maturity_date' => '2026-08-18',
                ],
            ])
            ->assertOk();

        $instrument = $this->linkedInstrument($expense);
        self::assertSame(InstrumentKind::Effet, $instrument->kind);
        self::assertSame('2026-08-18', $instrument->maturity_date?->toDateString());
        $effetsPayable = app(InstrumentAccountResolver::class)->resolveOrFail(
            InstrumentAccountPurpose::EffetsPayable,
            $this->context['company']->id,
        );
        $issue = JournalEntry::query()->with('lines')
            ->where('source_type', 'instrument')
            ->where('source_id', $instrument->id)
            ->sole();
        self::assertSame('125.000', $issue->lines->firstWhere('account_id', $effetsPayable)?->credit);
        self::assertSame(0, RepositoryMovement::query()->where('source_id', $instrument->id)->count());
    }

    public function test_effet_settlement_rejects_a_missing_maturity_date_before_mutation(): void
    {
        $expense = $this->expense();
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->context['tenant']->id,
            'company_id' => $this->context['company']->id,
            'code' => 'EXP-EFFET-NODATE-'.Str::upper(Str::random(6)),
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Effet,
        ]);

        $this->actingAs($this->context['user'], 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/pay", [
                'mode' => 'instrument',
                'payment_repository_id' => $this->context['repository']->id,
                'payment_method_id' => $method->id,
                'payment_date' => '2026-07-18',
                'instrument' => [
                    'kind' => InstrumentKind::Effet->value,
                    'reference' => 'EFF-NO-DATE',
                    'bank_id' => $this->context['bank']->id,
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['errors' => ['instrument.maturity_date']]]);

        self::assertSame(0, PaymentInstrument::query()->count());
        self::assertSame(0, JournalEntry::query()->where('source_type', 'instrument')->count());
    }

    public function test_retry_and_cash_settlement_are_rejected_while_linked_instrument_is_pending(): void
    {
        $expense = $this->expense();
        $this->payByInstrument($expense)->assertOk();

        $this->payByInstrument($expense)->assertStatus(422);
        $this->actingAs($this->context['user'], 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/pay", $this->cashPayload())
            ->assertStatus(422);

        $instrumentId = ExpenseMetadata::query()->where('document_id', $expense->id)->value('payment_instrument_id');
        self::assertIsString($instrumentId);
        self::assertSame(1, JournalEntry::query()->where('source_type', 'instrument')->where('source_id', $instrumentId)->count());
        self::assertSame(0, RepositoryMovement::query()->where('source_id', $expense->id)->count());
        self::assertSame('500.000', $this->context['repository']->fresh()?->balance);
    }

    public function test_clear_event_marks_expense_paid_from_the_instrument_and_clear_movement(): void
    {
        $expense = $this->expense();
        $this->payByInstrument($expense)->assertOk();
        $instrument = $this->linkedInstrument($expense);

        app(OutboundInstrumentService::class)->clear(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            '2026-07-18',
        );

        $metadata = ExpenseMetadata::query()->where('document_id', $expense->id)->firstOrFail();
        self::assertTrue($metadata->is_paid);
        self::assertSame('2026-07-18', $metadata->paid_at?->toDateString());
        self::assertSame('2026-07-18', $metadata->payment_date?->toDateString());
        self::assertSame($this->context['repository']->id, $metadata->payment_repository_id);
        self::assertSame($this->context['method']->id, $metadata->payment_method_id);
        self::assertSame(1, RepositoryMovement::query()->where('source_id', $instrument->id)->count());
        self::assertSame('375.000', $this->context['repository']->fresh()?->balance);
    }

    public function test_cancel_event_unlinks_instrument_and_resets_payment_fields(): void
    {
        $expense = $this->expense();
        $this->payByInstrument($expense)->assertOk();
        $instrument = $this->linkedInstrument($expense);

        app(OutboundInstrumentService::class)->cancel(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            'Cheque voided',
        );

        $metadata = ExpenseMetadata::query()->where('document_id', $expense->id)->firstOrFail();
        self::assertFalse($metadata->is_paid);
        self::assertNull($metadata->paid_at);
        self::assertNull($metadata->payment_date);
        self::assertNull($metadata->payment_repository_id);
        self::assertNull($metadata->payment_method_id);
        self::assertNull($metadata->payment_instrument_id);
    }

    public function test_cancelled_instrument_can_be_replaced_without_reusing_its_row_key(): void
    {
        $expense = $this->expense();
        $this->payByInstrument($expense)->assertOk();
        $cancelled = $this->linkedInstrument($expense);

        app(OutboundInstrumentService::class)->cancel(
            $cancelled->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            'Replace damaged cheque',
        );

        $this->payByInstrument($expense, 'CHK-EXP-REPLACEMENT')->assertOk();
        $replacement = $this->linkedInstrument($expense);

        self::assertNotSame($cancelled->id, $replacement->id);
        self::assertSame(InstrumentStatus::Cancelled, $cancelled->fresh()?->status);
        self::assertSame(InstrumentStatus::Received, $replacement->status);
        self::assertSame("expense:{$expense->id}:settlement:instrument:1", $cancelled->idempotency_key);
        self::assertSame("expense:{$expense->id}:settlement:instrument:2", $replacement->idempotency_key);
        self::assertSame(2, InstrumentEvent::query()->where('event_type', InstrumentEventType::Issued)->count());
    }

    public function test_cash_mode_regression_still_records_one_settlement_movement(): void
    {
        $expense = $this->expense();

        $this->actingAs($this->context['user'], 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/pay", $this->cashPayload())
            ->assertOk()
            ->assertJsonPath('data.metadata.is_paid', true);

        self::assertSame(1, RepositoryMovement::query()
            ->where('source_id', $expense->id)
            ->where('idempotency_key', "expense:{$expense->id}:settlement")
            ->count());
    }

    public function test_linked_cost_stays_rejected_in_instrument_mode(): void
    {
        $expense = $this->expense(ExpenseKind::LinkedCost);

        $this->payByInstrument($expense)->assertStatus(422);

        self::assertSame(0, PaymentInstrument::query()->count());
        self::assertSame(0, JournalEntry::query()->where('source_type', 'instrument')->count());
        self::assertSame('500.000', $this->context['repository']->fresh()?->balance);
    }

    private function expense(ExpenseKind $kind = ExpenseKind::Generic): Document
    {
        $expense = Document::create([
            'tenant_id' => $this->context['tenant']->id,
            'company_id' => $this->context['company']->id,
            'partner_id' => $this->context['partner']->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Posted,
            'document_number' => 'EXP-'.Str::upper(Str::random(8)),
            'document_date' => '2026-07-18',
            'currency' => 'TND',
            'subtotal' => '125.000',
            'tax_amount' => '0.000',
            'total' => '125.000',
            'balance_due' => '125.000',
        ]);
        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'is_paid' => false,
            'expense_kind' => $kind,
        ]);

        return $expense;
    }

    /** @return TestResponse<Response> */
    private function payByInstrument(Document $expense, string $reference = 'CHK-EXP-001'): TestResponse
    {
        return $this->actingAs($this->context['user'], 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/pay", [
                'mode' => 'instrument',
                'payment_repository_id' => $this->context['repository']->id,
                'payment_method_id' => $this->context['method']->id,
                'payment_date' => '2026-07-18',
                'instrument' => [
                    'kind' => InstrumentKind::Cheque->value,
                    'reference' => $reference,
                    'bank_id' => $this->context['bank']->id,
                    'maturity_date' => '2026-07-25',
                    'drawer_name' => 'Test Vendor',
                ],
            ]);
    }

    /** @return array<string, string> */
    private function cashPayload(): array
    {
        return [
            'payment_repository_id' => $this->context['repository']->id,
            'payment_date' => '2026-07-18',
        ];
    }

    private function linkedInstrument(Document $expense): PaymentInstrument
    {
        $instrumentId = ExpenseMetadata::query()->where('document_id', $expense->id)->value('payment_instrument_id');
        self::assertIsString($instrumentId);

        return PaymentInstrument::query()->findOrFail($instrumentId);
    }
}
