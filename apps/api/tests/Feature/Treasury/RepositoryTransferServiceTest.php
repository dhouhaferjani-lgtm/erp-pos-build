<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create([
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
}
