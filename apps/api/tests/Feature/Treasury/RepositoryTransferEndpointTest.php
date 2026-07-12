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
use App\Modules\Treasury\Application\Services\RepositoryTransferService;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
