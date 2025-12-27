<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\ImmutableJournalEntryException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * JournalEntryImmutabilityTest - TDD RED Phase for P1-B Milestone 4
 *
 * Tests that journal entries in the hash chain are immutable and cannot be
 * modified or deleted after creation.
 *
 * Expected behavior:
 * 1. Entries WITHOUT fiscal_hash (drafts) can be updated and deleted
 * 2. Entries WITH fiscal_hash (chained) CANNOT be updated
 * 3. Entries WITH fiscal_hash (chained) CANNOT be deleted
 * 4. Lines of chained entries CANNOT be modified
 * 5. Lines of chained entries CANNOT be deleted
 * 6. Mass updates of chained entries fail
 * 7. Soft deletes of chained entries are prevented
 * 8. Force deletes of chained entries are prevented
 * 9. Clear exception messages guide users to use reversals
 *
 * CRITICAL: These tests MUST fail initially (RED phase).
 * Agent 4B will implement immutability enforcement to make them pass (GREEN phase).
 */
final class JournalEntryImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Account $receivableAccount;

    private Account $revenueAccount;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Immutability Test Tenant',
            'slug' => 'immutability-test-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Create company
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Immutability Test Company',
            'legal_name' => 'Immutability Test Company LLC',
            'tax_id' => 'TAX-IMMUT-'.uniqid(),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        // Create test accounts
        $this->receivableAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Accounts Receivable',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);

        $this->revenueAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '707',
            'name' => 'Product Revenue',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: Journal entry without hash can be updated
     *
     * Entries that are not yet part of the hash chain (drafts)
     * should allow updates without throwing exceptions.
     */
    public function test_journal_entry_without_hash_can_be_updated(): void
    {
        // Arrange: Create entry WITHOUT fiscal_hash (draft/unposted)
        $entry = $this->createJournalEntry([
            'fiscal_hash' => null,
            'entry_number' => 'GL-DRAFT-001',
            'description' => 'Original description',
        ]);

        // Act: Update entry
        $entry->update(['description' => 'Updated description']);

        // Assert: Update succeeded
        $this->assertEquals('Updated description', $entry->fresh()->description);
    }

    /**
     * Test 2: Journal entry with hash cannot be updated
     *
     * CRITICAL: This test MUST fail until immutability is implemented.
     *
     * Entries that are part of the hash chain should throw
     * ImmutableJournalEntryException when attempting to update.
     */
    public function test_journal_entry_with_hash_cannot_be_updated(): void
    {
        // Arrange: Create entry WITH fiscal_hash (simulating posted entry)
        $entry = $this->createJournalEntry([
            'fiscal_hash' => hash('sha256', 'test-data-'.uniqid()),
            'chain_sequence' => 1,
            'previous_hash' => null,
            'entry_number' => 'GL-2025-0001',
        ]);

        // Act & Assert: Attempt to update should throw exception
        $this->expectException(ImmutableJournalEntryException::class);
        $this->expectExceptionMessage('immutable');
        $this->expectExceptionMessage($entry->entry_number);

        $entry->update(['description' => 'Attempted modification']);
    }

    /**
     * Test 3: Journal entry without hash can be deleted
     *
     * Draft entries should allow deletion without exceptions.
     */
    public function test_journal_entry_without_hash_can_be_deleted(): void
    {
        // Arrange: Create entry WITHOUT fiscal_hash
        $entry = $this->createJournalEntry([
            'fiscal_hash' => null,
            'entry_number' => 'GL-DRAFT-002',
        ]);

        $entryId = $entry->id;

        // Act: Delete entry
        $entry->delete();

        // Assert: Deletion succeeded
        $this->assertDatabaseMissing('journal_entries', ['id' => $entryId]);
    }

    /**
     * Test 4: Journal entry with hash cannot be deleted
     *
     * CRITICAL: This test MUST fail until immutability is implemented.
     *
     * Entries in the hash chain should throw ImmutableJournalEntryException
     * when attempting to delete.
     */
    public function test_journal_entry_with_hash_cannot_be_deleted(): void
    {
        // Arrange: Create entry WITH fiscal_hash
        $entry = $this->createJournalEntry([
            'fiscal_hash' => hash('sha256', 'test-data-'.uniqid()),
            'chain_sequence' => 1,
            'entry_number' => 'GL-2025-0002',
        ]);

        $entryId = $entry->id;

        // Act & Assert: Attempt to delete should throw exception
        $this->expectException(ImmutableJournalEntryException::class);
        $this->expectExceptionMessage('Cannot delete');
        $this->expectExceptionMessage($entry->entry_number);

        $entry->delete();

        // Verify entry still exists (if exception handling doesn't stop execution)
        $this->assertDatabaseHas('journal_entries', ['id' => $entryId]);
    }

    /**
     * Test 5: Journal lines cannot be modified if entry has hash
     *
     * CRITICAL: This test MUST fail until immutability is implemented.
     *
     * Lines belonging to a chained entry should not be modifiable.
     */
    public function test_journal_lines_cannot_be_modified_if_entry_has_hash(): void
    {
        // Arrange: Create entry WITH fiscal_hash and lines
        $entry = $this->createJournalEntry([
            'fiscal_hash' => hash('sha256', 'test-data-'.uniqid()),
            'chain_sequence' => 1,
            'entry_number' => 'GL-2025-0003',
        ]);

        $line = JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->receivableAccount->id,
            'debit' => '100.00',
            'credit' => '0.00',
            'description' => 'Original line description',
            'line_order' => 1,
        ]);

        // Act & Assert: Attempt to update line should throw exception
        $this->expectException(ImmutableJournalEntryException::class);
        $this->expectExceptionMessage('Cannot update journal lines');

        $line->update(['description' => 'Modified line description']);
    }

    /**
     * Test 6: Journal lines cannot be deleted if entry has hash
     *
     * CRITICAL: This test MUST fail until immutability is implemented.
     *
     * Lines belonging to a chained entry should not be deletable.
     */
    public function test_journal_lines_cannot_be_deleted_if_entry_has_hash(): void
    {
        // Arrange: Create entry WITH fiscal_hash and lines
        $entry = $this->createJournalEntry([
            'fiscal_hash' => hash('sha256', 'test-data-'.uniqid()),
            'chain_sequence' => 1,
            'entry_number' => 'GL-2025-0004',
        ]);

        $line = JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->receivableAccount->id,
            'debit' => '100.00',
            'credit' => '0.00',
            'description' => 'Line to be deleted',
            'line_order' => 1,
        ]);

        $lineId = $line->id;

        // Act & Assert: Attempt to delete line should throw exception
        $this->expectException(ImmutableJournalEntryException::class);
        $this->expectExceptionMessage('Cannot delete journal lines');

        $line->delete();

        // Verify line still exists
        $this->assertDatabaseHas('journal_lines', ['id' => $lineId]);
    }

    /**
     * Test 7: Exception message is clear and helpful
     *
     * CRITICAL: This test MUST fail until immutability is implemented.
     *
     * Exception messages should mention:
     * - "immutable" or "hash chain"
     * - The entry number for debugging
     * - Suggestion to use reversals
     */
    public function test_exception_message_is_clear(): void
    {
        // Arrange: Create entry WITH fiscal_hash
        $entry = $this->createJournalEntry([
            'fiscal_hash' => hash('sha256', 'test-data-'.uniqid()),
            'chain_sequence' => 1,
            'entry_number' => 'GL-2025-0005',
        ]);

        try {
            // Act: Attempt to update
            $entry->update(['description' => 'Should fail']);

            // If no exception thrown, fail test
            $this->fail('Expected ImmutableJournalEntryException was not thrown');
        } catch (ImmutableJournalEntryException $e) {
            // Assert: Exception message is helpful
            $message = $e->getMessage();

            $this->assertStringContainsString('immutable', strtolower($message), 'Message should mention immutability');
            $this->assertStringContainsString($entry->entry_number, $message, 'Message should include entry number');
            $this->assertStringContainsString('hash chain', strtolower($message), 'Message should mention hash chain');

            // Bonus: Should mention fiscal compliance or reversals
            $hasGuidance = str_contains(strtolower($message), 'fiscal')
                || str_contains(strtolower($message), 'reversal')
                || str_contains(strtolower($message), 'compliance');

            $this->assertTrue($hasGuidance, 'Message should mention fiscal compliance or reversals');
        }
    }

    /**
     * Test 8: Mass update of chained entries fails
     *
     * CRITICAL: This test MUST fail until immutability is implemented.
     *
     * Attempting to mass-update a chained entry via query builder should fail.
     */
    public function test_mass_update_of_chained_entries_fails(): void
    {
        // Arrange: Create entry with hash chain
        $entry = $this->createJournalEntry([
            'fiscal_hash' => hash('sha256', 'entry1-'.uniqid()),
            'chain_sequence' => 1,
            'previous_hash' => null,
            'entry_number' => 'GL-2025-0006',
            'description' => 'Original description',
        ]);

        // Act & Assert: Attempt to update via query builder should throw exception
        $this->expectException(ImmutableJournalEntryException::class);

        JournalEntry::where('id', $entry->id)
            ->update(['description' => 'Mass update attempt']);
    }

    /**
     * Test 9: Updating specific fields throws exception
     *
     * CRITICAL: This test MUST fail until immutability is implemented.
     *
     * Even updating non-critical fields should fail for chained entries.
     */
    public function test_updating_any_field_of_chained_entry_throws_exception(): void
    {
        // Arrange: Create entry WITH fiscal_hash
        $entry = $this->createJournalEntry([
            'fiscal_hash' => hash('sha256', 'test-data-'.uniqid()),
            'chain_sequence' => 1,
            'entry_number' => 'GL-2025-0009',
            'description' => 'Original',
        ]);

        // Test updating description field (most common field)
        $this->expectException(ImmutableJournalEntryException::class);

        $entry->update(['description' => 'Modified description']);
    }

    /**
     * Test 10: Creating new lines on chained entry fails
     *
     * CRITICAL: This test MUST fail until immutability is implemented.
     *
     * Adding new lines to a chained entry should fail.
     */
    public function test_creating_new_lines_on_chained_entry_fails(): void
    {
        // Arrange: Create entry WITH fiscal_hash
        $entry = $this->createJournalEntry([
            'fiscal_hash' => hash('sha256', 'test-data-'.uniqid()),
            'chain_sequence' => 1,
            'entry_number' => 'GL-2025-0010',
        ]);

        // Create initial line
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->receivableAccount->id,
            'debit' => '100.00',
            'credit' => '0.00',
            'description' => 'Initial line',
            'line_order' => 1,
        ]);

        // Act & Assert: Attempt to add new line should throw exception
        $this->expectException(ImmutableJournalEntryException::class);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '100.00',
            'description' => 'Attempting to add new line',
            'line_order' => 2,
        ]);
    }

    /**
     * Test 11: Chained entry detection via isChained() method
     *
     * The JournalEntry model should have an isChained() method that
     * correctly identifies when an entry is part of the hash chain.
     */
    public function test_is_chained_method_correctly_identifies_chained_entries(): void
    {
        // Arrange: Create entry without hash
        $draftEntry = $this->createJournalEntry([
            'fiscal_hash' => null,
            'entry_number' => 'GL-DRAFT-003',
        ]);

        // Arrange: Create entry with hash
        $chainedEntry = $this->createJournalEntry([
            'fiscal_hash' => hash('sha256', 'test-data-'.uniqid()),
            'chain_sequence' => 1,
            'entry_number' => 'GL-2025-0011',
        ]);

        // Assert: isChained() method works correctly
        $this->assertFalse($draftEntry->isChained(), 'Draft entry should not be chained');
        $this->assertTrue($chainedEntry->isChained(), 'Entry with fiscal_hash should be chained');
    }

    /**
     * Test 12: Multiple field updates in single call throw exception
     *
     * CRITICAL: This test MUST fail until immutability is implemented.
     *
     * Updating multiple fields at once should still throw exception.
     */
    public function test_multiple_field_updates_throw_exception(): void
    {
        // Arrange: Create entry WITH fiscal_hash
        $entry = $this->createJournalEntry([
            'fiscal_hash' => hash('sha256', 'test-data-'.uniqid()),
            'chain_sequence' => 1,
            'entry_number' => 'GL-2025-0012',
        ]);

        // Act & Assert: Attempt to update multiple fields
        $this->expectException(ImmutableJournalEntryException::class);

        $entry->update([
            'description' => 'Modified description',
            'entry_number' => 'GL-2025-9999',
            'entry_date' => now()->addDay(),
        ]);
    }

    /**
     * Test 13: Direct SQL updates are prevented (via database trigger if implemented)
     *
     * Note: This test may need database trigger support.
     * For now, it tests model-level protection.
     */
    public function test_entry_with_hash_cannot_be_updated_via_query_builder(): void
    {
        // Arrange: Create entry WITH fiscal_hash
        $entry = $this->createJournalEntry([
            'fiscal_hash' => hash('sha256', 'test-data-'.uniqid()),
            'chain_sequence' => 1,
            'entry_number' => 'GL-2025-0013',
            'description' => 'Original description',
        ]);

        // Act & Assert: Direct query builder update should also be prevented
        $this->expectException(ImmutableJournalEntryException::class);

        JournalEntry::where('id', $entry->id)
            ->update(['description' => 'Direct SQL update attempt']);
    }

    /**
     * Test 14: Chain sequence immutability
     *
     * CRITICAL: This test MUST fail until immutability is implemented.
     *
     * The chain_sequence field is critical and must not be modified.
     */
    public function test_chain_sequence_cannot_be_modified(): void
    {
        // Arrange: Create entry WITH fiscal_hash
        $entry = $this->createJournalEntry([
            'fiscal_hash' => hash('sha256', 'test-data-'.uniqid()),
            'chain_sequence' => 5,
            'entry_number' => 'GL-2025-0014',
        ]);

        // Act & Assert: Attempt to modify chain_sequence
        $this->expectException(ImmutableJournalEntryException::class);

        $entry->update(['chain_sequence' => 999]);
    }

    /**
     * Test 15: Hash fields immutability
     *
     * CRITICAL: This test MUST fail until immutability is implemented.
     *
     * The fiscal_hash and previous_hash fields must not be modified.
     */
    public function test_hash_fields_cannot_be_modified(): void
    {
        // Arrange: Create entry WITH fiscal_hash
        $originalHash = hash('sha256', 'test-data-'.uniqid());
        $entry = $this->createJournalEntry([
            'fiscal_hash' => $originalHash,
            'chain_sequence' => 1,
            'entry_number' => 'GL-2025-0015',
        ]);

        // Act & Assert: Attempt to modify fiscal_hash
        $this->expectException(ImmutableJournalEntryException::class);

        $entry->update(['fiscal_hash' => hash('sha256', 'tampered-'.uniqid())]);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Create a journal entry with lines for testing
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createJournalEntry(array $attributes = []): JournalEntry
    {
        $defaults = [
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-TEST-'.uniqid(),
            'entry_date' => now(),
            'description' => 'Test journal entry',
            'status' => JournalEntryStatus::Posted,
            'is_historical' => false,
        ];

        return JournalEntry::create(array_merge($defaults, $attributes));
    }
}
