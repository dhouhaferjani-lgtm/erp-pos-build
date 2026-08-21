<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryPostException;
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
 * FAILURE MODE: the refusal must be raised as the NAMED type
 * {@see UnbalancedJournalEntryPostException}, not as a bare
 * `\InvalidArgumentException` that production `catch` blocks cannot distinguish
 * from ordinary argument noise.
 *
 * That type is deliberately a SIBLING of {@see UnbalancedJournalEntryException}
 * (the post-seal, document-sourced refusal from `AccountingService`), not the same
 * class and not a subclass of it — see
 * `test_the_two_unbalanced_types_keep_their_load_bearing_parents` below, which pins
 * both parents and their non-relationship. The chokepoint's type stays under
 * `\InvalidArgumentException` — the hierarchy its bare throw already had — so
 * naming it changed no catch site anywhere.
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

        $this->expectException(UnbalancedJournalEntryPostException::class);
        $this->expectExceptionMessage('Cannot post unbalanced journal entry');

        app(GeneralLedgerService::class)->postEntry($entry, $user, 'TND');
    }

    /**
     * Round-1 findings 3 and 4: both halves of the SPLIT are load-bearing, and this
     * test exists to keep either from being "simplified" back into one type.
     *
     * M1 tried one shared type and measured the damage in both directions:
     *  - parenting it under `\InvalidArgumentException` dropped the post-seal refusal
     *    out of `CreditNoteController::post()`'s `catch (\RuntimeException)` (a
     *    structured `500 CONFIGURATION_ERROR` became a generic 500) and made it a
     *    `\LogicException` exposed to the `catch (\InvalidArgumentException)` blocks
     *    that render 400/422;
     *  - parenting it under `\RuntimeException` newly exposed the CHOKEPOINT's
     *    refusal to `catch (\RuntimeException)` blocks that render **422**
     *    (`DeliveryNoteController:587`, `DocumentConversionController:432`,
     *    `POS/ReceiptController:378`) — the exact downgrade "never a 422" forbids.
     *
     * So: the post-seal type stays a `\RuntimeException`, and the chokepoint type
     * stays an `\InvalidArgumentException` (which is what the chokepoint threw before
     * M1, keeping its blast radius byte-identical to base).
     */
    public function test_the_two_unbalanced_types_keep_their_load_bearing_parents(): void
    {
        // Post-seal, document-sourced: an unmapped 500 + alert, never a 4xx.
        $this->assertTrue(
            is_subclass_of(UnbalancedJournalEntryException::class, \RuntimeException::class),
            'UnbalancedJournalEntryException must extend \RuntimeException: CreditNoteController::post() '
            .'catches it to render a structured 500 CONFIGURATION_ERROR.'
        );
        $this->assertFalse(
            is_subclass_of(UnbalancedJournalEntryException::class, \LogicException::class),
            'UnbalancedJournalEntryException must NOT be a \LogicException: that exposes a fiscal '
            .'refusal to the catch (\InvalidArgumentException) blocks that render 400/422.'
        );

        // Chokepoint: same hierarchy the bare throw had before M1, so naming it
        // changed no catch site anywhere.
        $this->assertTrue(
            is_subclass_of(UnbalancedJournalEntryPostException::class, \InvalidArgumentException::class),
            'UnbalancedJournalEntryPostException must extend \InvalidArgumentException so the '
            .'chokepoint blast radius stays identical to the pre-M1 bare throw.'
        );
        $this->assertFalse(
            is_subclass_of(UnbalancedJournalEntryPostException::class, \RuntimeException::class),
            'UnbalancedJournalEntryPostException must NOT be a \RuntimeException: that newly exposes '
            .'the chokepoint refusal to catch (\RuntimeException) blocks rendering 422.'
        );

        // They must stay siblings — collapsing one into the other reintroduces the
        // trade-off the split removes.
        $this->assertFalse(
            is_subclass_of(UnbalancedJournalEntryPostException::class, UnbalancedJournalEntryException::class),
            'The chokepoint type must not inherit the post-seal type: it would inherit its '
            .'\RuntimeException parent and the 422 downgrade paths with it.'
        );
    }
}
