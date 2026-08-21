<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * enforcement-P3 M1 — deliverable D (chokepoint failure-mode normalization).
 *
 * The GL posting chokepoint (`GeneralLedgerService::sealAndPersistEntry`) already
 * refuses an unbalanced entry — that guard is NOT re-implemented here and this
 * test does not add a second balance algorithm. What this test pins is the
 * FAILURE MODE: the refusal must be raised as the house type
 * {@see UnbalancedJournalEntryException}, not as a bare `\InvalidArgumentException`
 * that production `catch` blocks cannot distinguish from ordinary argument noise.
 *
 * Census evidence: `docs/handoff/reviews/enforcement-p3/M1-census.md` §5.
 */
final class ChokepointUnbalancedGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_chokepoint_raises_the_house_unbalanced_exception_type(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Unbalanced Guard Poster',
            'email' => 'unbalanced-guard-poster@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $account = Account::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '999998',
            'name' => 'Unbalanced Guard Suspense',
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);
        $entry = JournalEntry::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'entry_number' => 'UNBALANCED-GUARD-001',
            'entry_date' => now(),
            'description' => 'Deliberately unbalanced post',
            'status' => JournalEntryStatus::Draft,
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => '100.000',
            'credit' => '0',
            'description' => 'Debit leg',
            'line_order' => 0,
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => '0',
            'credit' => '90.000',
            'description' => 'Short credit leg',
            'line_order' => 1,
        ]);

        $this->expectException(UnbalancedJournalEntryException::class);
        $this->expectExceptionMessage('Cannot post unbalanced journal entry');

        app(GeneralLedgerService::class)->postEntry($entry, $user, 'TND');
    }

    /**
     * The normalization must stay backward compatible: every pre-existing
     * `catch (\InvalidArgumentException)` around a GL post keeps working, because
     * the house type is a REFINEMENT of what the chokepoint already threw.
     */
    public function test_house_unbalanced_exception_remains_catchable_as_invalid_argument(): void
    {
        $this->assertTrue(
            is_subclass_of(UnbalancedJournalEntryException::class, \InvalidArgumentException::class),
            'UnbalancedJournalEntryException must extend \InvalidArgumentException so the chokepoint '
            .'normalization does not break existing catch blocks.'
        );
    }
}
