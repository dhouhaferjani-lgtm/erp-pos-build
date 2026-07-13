<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\RepositoryTransferResult;
use App\Modules\Treasury\Application\Services\RepositoryTransferService;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Exceptions\CurrencyMismatchException;
use App\Modules\Treasury\Domain\Exceptions\RepositoryFrozenException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RepositoryTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_transfer_journal_entry_is_created_as_draft_and_never_posted(): void
    {
        [$cashAccount, $bankAccount] = $this->seedCashAndBankAccounts();

        $entry = DB::transaction(fn () => app(GeneralLedgerService::class)->createRepositoryTransferJournalEntry(
            companyId: $this->company->id,
            tenantId: $this->tenant->id,
            transferGroupId: (string) Str::uuid(),
            fromGlAccountId: $cashAccount->id,
            toGlAccountId: $bankAccount->id,
            amount: '100.000',
            date: now(),
            description: 'Transfert CASH-01 → BANK-01',
        ));

        $this->assertSame(JournalEntryStatus::Draft, $entry->status);
        $this->assertSame('treasury_transfer', $entry->source_type);
        $this->assertSame(JournalCode::Misc, $entry->journal_code);
        $this->assertCount(2, $entry->lines);

        $debit = $entry->lines->firstWhere('account_id', $bankAccount->id);
        $credit = $entry->lines->firstWhere('account_id', $cashAccount->id);

        $this->assertNotNull($debit);
        $this->assertNotNull($credit);
        $this->assertSame('100.000', $debit->debit);
        $this->assertSame('0.000', $debit->credit);
        $this->assertSame('0.000', $credit->debit);
        $this->assertSame('100.000', $credit->credit);
    }

    public function test_transfer_journal_entry_requires_enclosing_transaction(): void
    {
        [$cashAccount, $bankAccount] = $this->seedCashAndBankAccounts();
        $transferGroupId = (string) Str::uuid();
        $date = now();

        // RefreshDatabase wraps each test in a transaction. Pop every level so
        // this exercises the factory's real level-zero guard, then restore one
        // wrapper for RefreshDatabase teardown.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $this->assertSame(0, DB::transactionLevel());

        try {
            $this->expectException(\LogicException::class);
            app(GeneralLedgerService::class)->createRepositoryTransferJournalEntry(
                companyId: $this->company->id,
                tenantId: $this->tenant->id,
                transferGroupId: $transferGroupId,
                fromGlAccountId: $cashAccount->id,
                toGlAccountId: $bankAccount->id,
                amount: '100.000',
                date: $date,
                description: 'Transfert CASH-01 → BANK-01',
            );
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_cross_gl_transfer_posts_one_je_and_two_netting_legs(): void
    {
        [$cashAccount, $bankAccount] = $this->seedCashAndBankAccounts();
        $cashRepository = $this->seedRepository('CASH-01', RepositoryType::CashRegister, $cashAccount->id, '500.000');
        $bankRepository = $this->seedRepository('BANK-01', RepositoryType::BankAccount, $bankAccount->id);

        $result = $this->transfer($cashRepository, $bankRepository, '250.000', notes: 'remise especes');

        $this->assertFalse($result->idempotentReplay);
        $this->assertNotNull($result->journalEntryId);
        $entry = JournalEntry::with('lines')->findOrFail($result->journalEntryId);
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame(1, JournalEntry::where('source_type', 'treasury_transfer')->count());

        $debit = $entry->lines->firstWhere('account_id', $bankAccount->id);
        $credit = $entry->lines->firstWhere('account_id', $cashAccount->id);
        $this->assertNotNull($debit);
        $this->assertNotNull($credit);
        $this->assertSame('250.000', $debit->debit);
        $this->assertSame('250.000', $credit->credit);

        $out = RepositoryMovement::findOrFail($result->out->movementId);
        $in = RepositoryMovement::findOrFail($result->in->movementId);
        $this->assertSame($entry->id, $out->journal_entry_id);
        $this->assertSame($entry->id, $in->journal_entry_id);
        $this->assertSame($out->transfer_group_id, $in->transfer_group_id);
        $this->assertSame('0.000', bcadd(bcmul($out->amount, '-1', 3), $in->amount, 3));
    }

    public function test_same_gl_transfer_posts_no_je(): void
    {
        [$cashAccount] = $this->seedCashAndBankAccounts();
        $cashRepository = $this->seedRepository('CASH-01', RepositoryType::CashRegister, $cashAccount->id, '100.000');
        $safeRepository = $this->seedRepository('SAFE-01', RepositoryType::Safe, $cashAccount->id);

        $result = $this->transfer($cashRepository, $safeRepository, '25.000');

        $this->assertNull($result->journalEntryId);
        $this->assertSame(0, JournalEntry::where('source_type', 'treasury_transfer')->count());
        $this->assertNull(RepositoryMovement::findOrFail($result->out->movementId)->journal_entry_id);
        $this->assertNull(RepositoryMovement::findOrFail($result->in->movementId)->journal_entry_id);
    }

    public function test_frozen_source_and_destination_are_rejected_by_the_service(): void
    {
        [$cashAccount, $bankAccount] = $this->seedCashAndBankAccounts();
        $cashRepository = $this->seedRepository('CASH-01', RepositoryType::CashRegister, $cashAccount->id, '100.000');
        $bankRepository = $this->seedRepository('BANK-01', RepositoryType::BankAccount, $bankAccount->id);
        $movementService = app(TreasuryMovementServiceInterface::class);

        $movementService->freeze($cashRepository->id, 'source drift');
        $this->assertRejectedWithoutJournal(
            RepositoryFrozenException::class,
            fn () => $this->transfer($cashRepository, $bankRepository, '10.000'),
        );

        $movementService->unfreeze($cashRepository->id);
        $movementService->freeze($bankRepository->id, 'destination drift');
        $this->assertRejectedWithoutJournal(
            RepositoryFrozenException::class,
            fn () => $this->transfer($cashRepository, $bankRepository, '10.000'),
        );
    }

    public function test_virtual_repository_is_rejected_both_directions(): void
    {
        [$cashAccount] = $this->seedCashAndBankAccounts();
        $cashRepository = $this->seedRepository('CASH-01', RepositoryType::CashRegister, $cashAccount->id, '100.000');
        $virtualRepository = $this->seedRepository('VIRT-01', RepositoryType::Virtual, $cashAccount->id);

        $this->assertRejectedWithoutJournal(
            \DomainException::class,
            fn () => $this->transfer($virtualRepository, $cashRepository, '10.000'),
        );
        $this->assertRejectedWithoutJournal(
            \DomainException::class,
            fn () => $this->transfer($cashRepository, $virtualRepository, '10.000'),
        );
    }

    public function test_currency_mismatch_leaves_no_orphan_draft(): void
    {
        [$cashAccount, $bankAccount] = $this->seedCashAndBankAccounts();
        $cashRepository = $this->seedRepository('CASH-01', RepositoryType::CashRegister, $cashAccount->id, '100.000');
        $euroBankRepository = $this->seedRepository('BANK-EUR', RepositoryType::BankAccount, $bankAccount->id, currency: 'EUR');

        $this->assertRejectedWithoutJournal(
            CurrencyMismatchException::class,
            fn () => $this->transfer($cashRepository, $euroBankRepository, '10.000'),
        );
        $this->assertSame(0, RepositoryMovement::count());
    }

    public function test_cross_gl_with_missing_gl_account_is_422_before_any_write(): void
    {
        [, $bankAccount] = $this->seedCashAndBankAccounts();
        $unlinkedRepository = $this->seedRepository('CASH-01', RepositoryType::CashRegister, null, '100.000');
        $bankRepository = $this->seedRepository('BANK-01', RepositoryType::BankAccount, $bankAccount->id);

        $this->assertRejectedWithoutJournal(
            \DomainException::class,
            fn () => $this->transfer($unlinkedRepository, $bankRepository, '10.000'),
        );
        $this->assertSame(0, RepositoryMovement::count());
    }

    public function test_inactive_repository_is_rejected_both_directions(): void
    {
        [$cashAccount] = $this->seedCashAndBankAccounts();
        $cashRepository = $this->seedRepository('CASH-01', RepositoryType::CashRegister, $cashAccount->id, '100.000');
        $inactiveRepository = $this->seedRepository('SAFE-01', RepositoryType::Safe, $cashAccount->id, active: false);

        $this->assertRejectedWithoutJournal(
            \DomainException::class,
            fn () => $this->transfer($inactiveRepository, $cashRepository, '10.000'),
        );
        $this->assertRejectedWithoutJournal(
            \DomainException::class,
            fn () => $this->transfer($cashRepository, $inactiveRepository, '10.000'),
        );
    }

    public function test_amount_is_normalized_at_source_currency_scale(): void
    {
        [$cashAccount] = $this->seedCashAndBankAccounts();
        $cashRepository = $this->seedRepository('CASH-EUR', RepositoryType::CashRegister, $cashAccount->id, '100.000', 'EUR');
        $safeRepository = $this->seedRepository('SAFE-EUR', RepositoryType::Safe, $cashAccount->id, currency: 'EUR');

        $result = $this->transfer($cashRepository, $safeRepository, '10.005');

        $out = RepositoryMovement::findOrFail($result->out->movementId);
        $in = RepositoryMovement::findOrFail($result->in->movementId);
        $this->assertSame(0, bccomp($out->amount, '10.00', 2));
        $this->assertSame(0, bccomp($in->amount, '10.00', 2));
    }

    /**
     * @return array{Account, Account}
     */
    private function seedCashAndBankAccounts(): array
    {
        $cashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '531001',
            'name' => 'Cash CASH-01',
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);

        $bankAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '512001',
            'name' => 'Bank BANK-01',
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);

        return [$cashAccount, $bankAccount];
    }

    private function seedRepository(
        string $code,
        RepositoryType $type,
        ?string $glAccountId,
        string $balance = '0.000',
        string $currency = 'TND',
        bool $active = true,
    ): PaymentRepository {
        return PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'code' => $code,
            'type' => $type,
            'gl_account_id' => $glAccountId,
            'balance' => $balance,
            'currency' => $currency,
            'is_active' => $active,
        ]);
    }

    private function service(): RepositoryTransferService
    {
        return app(RepositoryTransferService::class);
    }

    private function transfer(
        PaymentRepository $from,
        PaymentRepository $to,
        string $amount,
        ?string $notes = null,
    ): RepositoryTransferResult {
        return $this->service()->transfer(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            fromRepositoryId: $from->id,
            toRepositoryId: $to->id,
            amount: $amount,
            notes: $notes,
            transferGroupId: null,
            userId: $this->user->id,
        );
    }

    /**
     * @param  class-string<\Throwable>  $exception
     */
    private function assertRejectedWithoutJournal(string $exception, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected {$exception}.");
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);
        }

        $this->assertSame(0, JournalEntry::where('source_type', 'treasury_transfer')->count());
    }
}
