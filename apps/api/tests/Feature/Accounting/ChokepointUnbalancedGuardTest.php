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
     * Round-1 findings 3 and 4: the `\RuntimeException` parent is load-bearing and
     * this test exists to keep it that way.
     *
     * An unbalanced entry must surface as an unmapped 500 + alert and must never be
     * reportable as a client validation error. Re-parenting this type under
     * `\InvalidArgumentException` (i.e. under `\LogicException`) exposes it to the
     * ~30 `catch (\InvalidArgumentException)` blocks in `app/` that render
     * 400/422 `VALIDATION_ERROR` responses, and simultaneously drops it out of
     * `catch (\RuntimeException)` blocks that deliberately map it to a detailed 500
     * (`CreditNoteController::post()`). M1 made that mistake and reverted it.
     */
    public function test_the_house_unbalanced_exception_is_never_a_logic_exception(): void
    {
        $this->assertTrue(
            is_subclass_of(UnbalancedJournalEntryException::class, \RuntimeException::class),
            'UnbalancedJournalEntryException must extend \RuntimeException so it maps to an '
            .'unmapped 500 + alert, never a 4xx validation response.'
        );
        $this->assertFalse(
            is_subclass_of(UnbalancedJournalEntryException::class, \LogicException::class),
            'UnbalancedJournalEntryException must NOT be a \LogicException: that exposes a fiscal '
            .'refusal to the catch (\InvalidArgumentException) blocks that render 400/422.'
        );
    }
}
