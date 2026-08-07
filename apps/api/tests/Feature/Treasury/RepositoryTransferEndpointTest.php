<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\Services\RepositoryTransferService;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

final class RepositoryTransferEndpointTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // The permission is created explicitly so the test suite reaches the
        // deliberately missing route during RED, before A4 updates the seeders.
        Permission::findOrCreate('treasury.transfer', 'sanctum');

        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->user->givePermissionTo('treasury.transfer');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'accountant',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_transfer_requires_permission(): void
    {
        [$account] = $this->seedCashAndBankAccounts();
        $from = $this->seedRepository('CASH-01', RepositoryType::CashRegister, $account->id, '100.000');
        $to = $this->seedRepository('SAFE-01', RepositoryType::Safe, $account->id);
        $this->user->revokePermissionTo('treasury.transfer');

        $this->postTransfer($from, $to, '10.000')->assertForbidden();
    }

    public function test_transfer_happy_path_returns_contract_shape_and_moves_balances(): void
    {
        [$cashAccount, $bankAccount] = $this->seedCashAndBankAccounts();
        $from = $this->seedRepository('CASH-01', RepositoryType::CashRegister, $cashAccount->id, '500.000');
        $to = $this->seedRepository('BANK-01', RepositoryType::BankAccount, $bankAccount->id);

        $response = $this->postTransfer($from, $to, '125.000', ['notes' => 'Daily bank deposit'])
            ->assertCreated()
            ->assertJsonStructure([
                'message',
                'data' => [
                    'transfer_group_id',
                    'journal_entry_id',
                    'idempotent_replay',
                    'out' => ['movement_id', 'balance_after', 'repository_id'],
                    'in' => ['movement_id', 'balance_after', 'repository_id'],
                ],
            ])
            ->assertJsonPath('data.idempotent_replay', false)
            ->assertJsonPath('data.out.repository_id', $from->id)
            ->assertJsonPath('data.out.balance_after', '375.000')
            ->assertJsonPath('data.in.repository_id', $to->id)
            ->assertJsonPath('data.in.balance_after', '125.000');

        $this->assertTrue(Str::isUuid((string) $response->json('data.transfer_group_id')));
        $this->assertTrue(Str::isUuid((string) $response->json('data.journal_entry_id')));
        $this->assertSame('375.000', $from->fresh()?->balance);
        $this->assertSame('125.000', $to->fresh()?->balance);
    }

    public function test_validation_rejects_same_repository_bad_amounts_and_missing_fields(): void
    {
        [$account] = $this->seedCashAndBankAccounts();
        $from = $this->seedRepository('CASH-01', RepositoryType::CashRegister, $account->id, '100.000');
        $to = $this->seedRepository('SAFE-01', RepositoryType::Safe, $account->id);

        $this->assertApiValidationErrors(
            $this->postTransfer($from, $from, '10.000'),
            ['to_repository_id'],
        );

        $this->assertApiValidationErrors(
            $this->postTransfer($from, $to, '10.0005'),
            ['amount'],
        );

        $this->assertApiValidationErrors(
            $this->postTransfer($from, $to, '-5'),
            ['amount'],
        );

        $this->assertApiValidationErrors(
            $this->actingAs($this->user, 'sanctum')
                ->withHeader('X-Company-Id', $this->company->id)
                ->postJson('/api/v1/payment-repositories/transfers', []),
            ['from_repository_id', 'to_repository_id', 'amount'],
        );
    }

    public function test_cross_company_repository_is_not_found(): void
    {
        [$account] = $this->seedCashAndBankAccounts();
        $from = $this->seedRepository('CASH-01', RepositoryType::CashRegister, $account->id, '100.000');
        $otherCompany = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        $otherRepository = PaymentRepository::factory()->for($otherCompany)->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SAFE-OTHER',
            'type' => RepositoryType::Safe,
            'gl_account_id' => $account->id,
            'currency' => 'TND',
        ]);

        $this->postTransfer($from, $otherRepository, '10.000')->assertNotFound();
    }

    public function test_client_transfer_group_id_makes_double_submit_idempotent(): void
    {
        [$cashAccount, $bankAccount] = $this->seedCashAndBankAccounts();
        $from = $this->seedRepository('CASH-01', RepositoryType::CashRegister, $cashAccount->id, '500.000');
        $to = $this->seedRepository('BANK-01', RepositoryType::BankAccount, $bankAccount->id);
        $groupId = (string) Str::uuid();

        $first = $this->postTransfer($from, $to, '125.000', ['transfer_group_id' => $groupId])
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', false);
        $second = $this->postTransfer($from, $to, '125.000', ['transfer_group_id' => $groupId])
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', true);

        $originalJournalEntryId = $first->json('data.journal_entry_id');
        $this->assertIsString($originalJournalEntryId);
        $this->assertSame($originalJournalEntryId, $second->json('data.journal_entry_id'));
        $this->assertSame($first->json('data.out.movement_id'), $second->json('data.out.movement_id'));
        $this->assertSame($first->json('data.in.movement_id'), $second->json('data.in.movement_id'));
        $this->assertSame(1, $this->transferEntries($groupId)->where('status', JournalEntryStatus::Posted->value)->count());
        $this->assertSame(0, $this->transferEntries($groupId)->where('status', JournalEntryStatus::Draft->value)->count());
        $this->assertSame('375.000', $from->fresh()?->balance);
        $this->assertSame('125.000', $to->fresh()?->balance);
    }

    public function test_race_shape_replay_via_preexisting_posted_journal_entry_and_legs(): void
    {
        [$cashAccount, $bankAccount] = $this->seedCashAndBankAccounts();
        $from = $this->seedRepository('CASH-01', RepositoryType::CashRegister, $cashAccount->id, '500.000');
        $to = $this->seedRepository('BANK-01', RepositoryType::BankAccount, $bankAccount->id);
        $groupId = (string) Str::uuid();

        $original = app(RepositoryTransferService::class)->transfer(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            fromRepositoryId: $from->id,
            toRepositoryId: $to->id,
            amount: '125.000',
            notes: 'pre-existing committed transfer',
            transferGroupId: $groupId,
            userId: $this->user->id,
        );
        $this->assertNotNull($original->journalEntryId);

        $response = $this->postTransfer($from, $to, '125.000', [
            'notes' => 'pre-existing committed transfer',
            'transfer_group_id' => $groupId,
        ])->assertCreated()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.journal_entry_id', $original->journalEntryId);

        $this->assertSame($original->out->movementId, $response->json('data.out.movement_id'));
        $this->assertSame(1, $this->transferEntries($groupId)->where('status', JournalEntryStatus::Posted->value)->count());
        $this->assertSame(0, $this->transferEntries($groupId)->where('status', JournalEntryStatus::Draft->value)->count());
    }

    public function test_reconcile_stays_green_after_mixed_transfers(): void
    {
        [$cashAccount, $bankAccount] = $this->seedCashAndBankAccounts();
        $cash = $this->seedRepository('CASH-01', RepositoryType::CashRegister, $cashAccount->id);
        $bank = $this->seedRepository('BANK-01', RepositoryType::BankAccount, $bankAccount->id);
        $safe = $this->seedRepository('SAFE-01', RepositoryType::Safe, $cashAccount->id);
        // W-5b Option B: $cash is the OUT-leg source for both transfers below
        // (125.000 + 50.000 = 175.000) — fund it through the movement port
        // (NOT the factory's direct-balance bracket, which would leave the
        // cached balance with no backing movement and trip
        // treasury:reconcile's drift check) so the new guard doesn't refuse
        // the first transfer.
        $this->fundRepository($cash, '500.000');
        $crossGlGroupId = (string) Str::uuid();

        $this->postTransfer($cash, $bank, '125.000', ['transfer_group_id' => $crossGlGroupId])
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', false);
        $this->postTransfer($cash, $safe, '50.000')
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', false);
        $this->postTransfer($cash, $bank, '125.000', ['transfer_group_id' => $crossGlGroupId])
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', true);

        app(CompanyContext::class)->clear();
        $exitCode = Artisan::call('treasury:reconcile', ['--tenant' => $this->tenant->id]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            0,
            PaymentRepository::query()->whereNotNull('frozen_at')->count(),
            'reconcile froze a repository after spec-shaped transfers',
        );
        $this->assertSame(
            0,
            DB::table('audit_events')->where('event_type', 'treasury.reconcile.drift')->count(),
        );
    }

    public function test_domain_failures_use_canonical_envelope_for_frozen_virtual_and_inactive_repositories(): void
    {
        [$account] = $this->seedCashAndBankAccounts();
        $source = $this->seedRepository('CASH-01', RepositoryType::CashRegister, $account->id, '100.000');
        $target = $this->seedRepository('SAFE-01', RepositoryType::Safe, $account->id);

        $source->forceFill([
            'frozen_at' => now(),
            'frozen_reason' => 'reconcile drift',
        ])->save();
        $this->assertBusinessError($this->postTransfer($source, $target, '10.000'));

        $source->forceFill(['frozen_at' => null, 'frozen_reason' => null])->save();
        $virtual = $this->seedRepository('VIRT-01', RepositoryType::Virtual, $account->id);
        $this->assertBusinessError($this->postTransfer($source, $virtual, '10.000'));

        $inactive = $this->seedRepository('SAFE-OFF', RepositoryType::Safe, $account->id, active: false);
        $this->assertBusinessError($this->postTransfer($source, $inactive, '10.000'));
    }

    /**
     * W-5b Option B (gate CRITICAL fix, 2026-08-07) — end-to-end through the
     * real HTTP route: a register→safe transfer for more than the register
     * holds is refused with the typed 422 envelope, not a silent negative
     * balance. This is the exact scenario the gate's throwaway probe proved
     * unguarded before the fix (cash_register 10.000 -> transfer 1000.000 ->
     * would have landed at -990.000 with no exception).
     */
    public function test_transfer_over_balance_returns_typed_422_and_moves_nothing(): void
    {
        [$account] = $this->seedCashAndBankAccounts();
        $register = $this->seedRepository('CASH-01', RepositoryType::CashRegister, $account->id, '10.000');
        $safe = $this->seedRepository('SAFE-01', RepositoryType::Safe, $account->id);

        $response = $this->postTransfer($register, $safe, '1000.000');

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'INSUFFICIENT_REPOSITORY_BALANCE')
            ->assertJsonPath('error.repository_id', $register->id)
            ->assertJsonPath('error.available', '10.000')
            ->assertJsonPath('error.requested', '1000.000')
            ->assertJsonPath('error.resulting_balance', '-990.000');

        $register->refresh();
        $safe->refresh();
        $this->assertSame('10.000', $register->balance);
        $this->assertSame('0.000', $safe->balance);
        $this->assertSame(0, DB::table('repository_movements')->count());
    }

    /**
     * Fund a repository through the movement port (mirrors
     * PaymentRepositorySeeder::recordOpeningBalance()) so the balance is
     * backed by a real movement — required for treasury:reconcile's drift
     * check to stay green, unlike the factory's direct-balance bracket.
     */
    private function fundRepository(PaymentRepository $repository, string $amount): void
    {
        DB::transaction(fn () => app(TreasuryMovementServiceInterface::class)->record(new MovementIntent(
            repositoryId: $repository->id,
            tenantId: $repository->tenant_id,
            companyId: $repository->company_id,
            direction: MovementDirection::In,
            amount: $amount,
            currency: $repository->currency,
            sourceType: MovementSourceType::OpeningBalance,
            sourceId: $repository->id,
            idempotencyLeg: 'opening',
            journalEntryId: null,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: 'Test fixture opening balance',
            allowWhileFrozen: false,
        )));
        $repository->refresh();
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
        bool $active = true,
    ): PaymentRepository {
        return PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'code' => $code,
            'type' => $type,
            'gl_account_id' => $glAccountId,
            'balance' => $balance,
            'currency' => 'TND',
            'is_active' => $active,
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return TestResponse<array<string, mixed>>
     */
    private function postTransfer(
        PaymentRepository $from,
        PaymentRepository $to,
        string $amount,
        array $overrides = [],
    ): TestResponse {
        return $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/payment-repositories/transfers', array_merge([
                'from_repository_id' => $from->id,
                'to_repository_id' => $to->id,
                'amount' => $amount,
            ], $overrides));
    }

    private function transferEntries(string $groupId): Builder
    {
        return JournalEntry::query()
            ->where('source_type', 'treasury_transfer')
            ->where('source_id', $groupId);
    }

    /** @param TestResponse<array<string, mixed>> $response */
    private function assertBusinessError(TestResponse $response): void
    {
        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'BUSINESS_ERROR')
            ->assertJsonStructure(['error' => ['code', 'message']]);
        $this->assertIsArray($response->json('error'));
    }
}
