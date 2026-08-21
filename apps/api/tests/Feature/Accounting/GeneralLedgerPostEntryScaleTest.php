<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Events\JournalEntryPosted;
use App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class GeneralLedgerPostEntryScaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_entry_rejects_subunit_imbalance_for_scale_zero_currency(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'country_code' => 'JP',
            'currency' => 'JPY',
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Scale Zero Poster',
            'email' => 'scale-zero-poster@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $account = Account::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '999999',
            'name' => 'Scale Test Suspense',
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);
        $entry = JournalEntry::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'entry_number' => 'JPY-IMBALANCE-001',
            'entry_date' => now(),
            'description' => 'JPY storage-scale imbalance',
            'status' => JournalEntryStatus::Draft,
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => '100.500',
            'credit' => '0',
            'description' => 'Debit with subunit storage precision',
            'line_order' => 0,
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => '0',
            'credit' => '100.000',
            'description' => 'Credit at display precision',
            'line_order' => 1,
        ]);

        $this->expectException(UnbalancedJournalEntryException::class);
        $this->expectExceptionMessage('Cannot post unbalanced journal entry');

        app(GeneralLedgerService::class)->postEntry($entry, $user, 'JPY');
    }

    public function test_post_entry_event_totals_keep_currency_display_scale(): void
    {
        Event::fake([JournalEntryPosted::class]);

        [$company, $user, $account] = $this->createPostingFixture('JPY', 'JP');
        $entry = $this->createDraftEntry($company, 'JPY-BALANCED-001');
        $this->createLine($entry, $account, '100.000', '0', 0);
        $this->createLine($entry, $account, '0', '100.000', 1);

        app(GeneralLedgerService::class)->postEntry($entry, $user, 'JPY');

        Event::assertDispatched(
            JournalEntryPosted::class,
            fn (JournalEntryPosted $event): bool => $event->totalDebit === '100'
                && $event->totalCredit === '100'
        );
    }

    /**
     * @return array{0: Company, 1: User, 2: Account}
     */
    private function createPostingFixture(string $currency, string $countryCode): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'country_code' => $countryCode,
            'currency' => $currency,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Scale Zero Poster',
            'email' => 'scale-zero-poster-'.strtolower($currency).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $account = Account::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '999999',
            'name' => 'Scale Test Suspense',
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);

        return [$company, $user, $account];
    }

    private function createDraftEntry(Company $company, string $entryNumber): JournalEntry
    {
        return JournalEntry::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'entry_number' => $entryNumber,
            'entry_date' => now(),
            'description' => 'Storage-scale test entry',
            'status' => JournalEntryStatus::Draft,
        ]);
    }

    private function createLine(
        JournalEntry $entry,
        Account $account,
        string $debit,
        string $credit,
        int $lineOrder,
    ): void {
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
            'description' => 'Storage-scale line',
            'line_order' => $lineOrder,
        ]);
    }
}
