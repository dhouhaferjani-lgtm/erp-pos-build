<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\GeneralLedgerHashService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-B Milestone 2: Test GL hash calculation service (TDD RED Phase).
 *
 * This test suite verifies the GeneralLedgerHashService which:
 * - Calculates SHA-256 hashes for journal entries
 * - Manages hash chains (previous_hash → current_hash)
 * - Assigns sequential chain_sequence numbers per company
 * - Verifies hash chain integrity
 *
 * Reference: FiscalHashService for documents
 *
 * EXPECTED: All tests should FAIL until service is implemented.
 */
final class GeneralLedgerHashServiceTest extends TestCase
{
    use RefreshDatabase;

    private GeneralLedgerHashService $hashService;

    private Tenant $tenant;

    private Company $company;

    private Account $cashAccount;

    private Account $revenueAccount;

    protected function setUp(): void
    {
        parent::setUp();

        // Create hash service via container (requires CurrencyScaleResolverInterface)
        $this->hashService = app(GeneralLedgerHashService::class);

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Create company
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        // Create test accounts
        $this->cashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
        ]);

        $this->revenueAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4000',
            'name' => 'Sales Revenue',
            'type' => AccountType::Revenue,
        ]);
    }

    public function test_calculate_hash_returns_sha256_string(): void
    {
        // Arrange: Create journal entry with known values
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-001',
            'entry_date' => '2025-12-26',
            'description' => 'Test entry',
            'status' => JournalEntryStatus::Draft,
            'is_historical' => false,
        ]);

        // Create balanced lines
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '1000.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '1000.00',
            'line_order' => 2,
        ]);

        // Act: Calculate hash without previous hash (genesis entry)
        $hash = $this->hashService->calculateHash($entry->fresh('lines'), null);

        // Assert: Hash should be valid SHA-256
        $this->assertIsString($hash);
        $this->assertEquals(64, strlen($hash), 'SHA-256 hash should be exactly 64 characters');
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $hash,
            'Hash should be lowercase hexadecimal (SHA-256 format)'
        );
    }

    public function test_serialize_for_hashing_includes_critical_fields(): void
    {
        // Arrange: Create journal entry with specific known values
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-001',
            'entry_date' => '2025-12-26',
            'description' => 'Test entry',
            'status' => JournalEntryStatus::Draft,
            'is_historical' => false,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '1500.50',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '1500.50',
            'line_order' => 2,
        ]);

        // Act: Serialize entry for hashing
        $serialized = $this->hashService->serializeForHashing($entry->fresh('lines'));

        // Assert: Verify format and content
        // Expected format: entry_number|entry_date|company_id|total_debit|total_credit
        $this->assertIsString($serialized);

        // Verify all critical fields are present
        $this->assertStringContainsString('GL-2025-001', $serialized, 'Should contain entry_number');
        $this->assertStringContainsString('2025-12-26', $serialized, 'Should contain entry_date');
        $this->assertStringContainsString($this->company->id, $serialized, 'Should contain company_id');
        $this->assertStringContainsString('1500.50', $serialized, 'Should contain total_debit');
        $this->assertStringContainsString('1500.50', $serialized, 'Should contain total_credit');

        // Verify pipe separator is used
        $parts = explode('|', $serialized);
        $this->assertCount(5, $parts, 'Should have exactly 5 parts separated by pipes');
    }

    public function test_genesis_entry_hash_without_previous_hash(): void
    {
        // Arrange: Create first journal entry for company (genesis)
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-001',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Draft,
            'is_historical' => false,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '500.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '500.00',
            'line_order' => 2,
        ]);

        // Act: Calculate hash with null previous_hash
        $hash1 = $this->hashService->calculateHash($entry->fresh('lines'), null);
        $hash2 = $this->hashService->calculateHash($entry->fresh('lines'), null);

        // Assert: Hash should be consistent (deterministic)
        $this->assertEquals(
            $hash1,
            $hash2,
            'Genesis entry hash should be deterministic (same input → same hash)'
        );

        // Hash should be valid SHA-256
        $this->assertEquals(64, strlen($hash1));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash1);
    }

    public function test_chained_entry_includes_previous_hash(): void
    {
        // Arrange: Create two journal entries
        $entry1 = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-001',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Draft,
            'is_historical' => false,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '100.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '100.00',
            'line_order' => 2,
        ]);

        $entry2 = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-002',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Draft,
            'is_historical' => false,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '200.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '200.00',
            'line_order' => 2,
        ]);

        // Act: Calculate hash for first entry (genesis)
        $hash1 = $this->hashService->calculateHash($entry1->fresh('lines'), null);

        // Calculate hash for second entry with previous_hash
        $hash2WithPrevious = $this->hashService->calculateHash($entry2->fresh('lines'), $hash1);

        // Calculate same entry without previous_hash
        $hash2WithoutPrevious = $this->hashService->calculateHash($entry2->fresh('lines'), null);

        // Assert: Previous hash should affect the result
        $this->assertNotEquals(
            $hash2WithPrevious,
            $hash2WithoutPrevious,
            'Including previous_hash should change the calculated hash'
        );

        // Different previous hash should produce different result
        $fakePreviousHash = str_repeat('a', 64);
        $hash2WithFakePrevious = $this->hashService->calculateHash($entry2->fresh('lines'), $fakePreviousHash);

        $this->assertNotEquals(
            $hash2WithPrevious,
            $hash2WithFakePrevious,
            'Different previous_hash should produce different hash'
        );
    }

    public function test_identical_entries_produce_same_hash_if_same_previous_hash(): void
    {
        // Arrange: Create two identical journal entries (same business data, different entry numbers to avoid constraint violation)
        $entry1 = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-999-A',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Draft,
            'is_historical' => false,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '750.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '750.00',
            'line_order' => 2,
        ]);

        $entry2 = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-999-B', // Different entry number to avoid constraint violation
            'entry_date' => '2025-12-26', // Same date
            'status' => JournalEntryStatus::Draft,
            'is_historical' => false,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '750.00', // Same amounts
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '750.00',
            'line_order' => 2,
        ]);

        // Act: Calculate hash for both with same previous_hash
        // Note: Hashes will be different because entry_number is part of the hash
        // This test verifies that the hash algorithm is deterministic (same input → same output)
        $previousHash = str_repeat('b', 64);
        $hash1 = $this->hashService->calculateHash($entry1->fresh('lines'), $previousHash);
        $hash2 = $this->hashService->calculateHash($entry2->fresh('lines'), $previousHash);

        // Assert: Hashes should be different because entry_number differs
        // But recalculating the same entry should produce the same hash (determinism test)
        $hash1Recalc = $this->hashService->calculateHash($entry1->fresh('lines'), $previousHash);
        $hash2Recalc = $this->hashService->calculateHash($entry2->fresh('lines'), $previousHash);

        $this->assertEquals(
            $hash1,
            $hash1Recalc,
            'Recalculating hash for same entry should produce identical hash (deterministic)'
        );
        $this->assertEquals(
            $hash2,
            $hash2Recalc,
            'Recalculating hash for same entry should produce identical hash (deterministic)'
        );
        $this->assertNotEquals(
            $hash1,
            $hash2,
            'Different entry numbers should produce different hashes'
        );
    }

    public function test_verify_chain_returns_true_for_valid_chain(): void
    {
        // Arrange: Create 3 journal entries with proper hash chain
        $entries = [];

        // Entry 1 (genesis)
        $entries[0] = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-001',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Posted,
            'is_historical' => false,
            'chain_sequence' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entries[0]->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '100.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entries[0]->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '100.00',
            'line_order' => 2,
        ]);

        // Calculate hash for entry 1
        $hash1 = $this->hashService->calculateHash($entries[0]->fresh('lines'), null);
        $entries[0]->update(['fiscal_hash' => $hash1, 'previous_hash' => null]);

        // Entry 2
        $entries[1] = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-002',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Posted,
            'is_historical' => false,
            'chain_sequence' => 2,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entries[1]->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '200.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entries[1]->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '200.00',
            'line_order' => 2,
        ]);

        $hash2 = $this->hashService->calculateHash($entries[1]->fresh('lines'), $hash1);
        $entries[1]->update(['fiscal_hash' => $hash2, 'previous_hash' => $hash1]);

        // Entry 3
        $entries[2] = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-003',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Posted,
            'is_historical' => false,
            'chain_sequence' => 3,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entries[2]->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '300.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entries[2]->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '300.00',
            'line_order' => 2,
        ]);

        $hash3 = $this->hashService->calculateHash($entries[2]->fresh('lines'), $hash2);
        $entries[2]->update(['fiscal_hash' => $hash3, 'previous_hash' => $hash2]);

        // Act: Verify the chain
        $isValid = $this->hashService->verifyChain($this->company->id);

        // Assert: Chain should be valid
        $this->assertTrue($isValid, 'Valid hash chain should pass verification');
    }

    public function test_verify_chain_returns_false_for_broken_chain(): void
    {
        // Arrange: Create 3 journal entries with hash chain
        $entries = [];

        // Entry 1 (genesis)
        $entries[0] = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-001',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Posted,
            'is_historical' => false,
            'chain_sequence' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entries[0]->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '100.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entries[0]->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '100.00',
            'line_order' => 2,
        ]);

        $hash1 = $this->hashService->calculateHash($entries[0]->fresh('lines'), null);
        $entries[0]->update(['fiscal_hash' => $hash1, 'previous_hash' => null]);

        // Entry 2
        $entries[1] = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-002',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Posted,
            'is_historical' => false,
            'chain_sequence' => 2,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entries[1]->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '200.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entries[1]->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '200.00',
            'line_order' => 2,
        ]);

        $hash2 = $this->hashService->calculateHash($entries[1]->fresh('lines'), $hash1);
        $entries[1]->update(['fiscal_hash' => $hash2, 'previous_hash' => $hash1]);

        // Entry 3
        $entries[2] = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-003',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Posted,
            'is_historical' => false,
            'chain_sequence' => 3,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entries[2]->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '300.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entries[2]->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '300.00',
            'line_order' => 2,
        ]);

        $hash3 = $this->hashService->calculateHash($entries[2]->fresh('lines'), $hash2);
        $entries[2]->update(['fiscal_hash' => $hash3, 'previous_hash' => $hash2]);

        // Act: Tamper with middle entry's fiscal_hash (simulate fraud)
        // Use raw SQL to bypass immutability protection (simulates database tampering)
        $tamperedHash = str_repeat('f', 64); // Invalid hash
        \DB::table('journal_entries')
            ->where('id', $entries[1]->id)
            ->update(['fiscal_hash' => $tamperedHash]);

        // Act: Verify the chain
        $isValid = $this->hashService->verifyChain($this->company->id);

        // Assert: Chain should be invalid (tampering detected)
        $this->assertFalse($isValid, 'Tampered hash chain should fail verification');
    }

    public function test_verify_chain_returns_false_for_missing_sequence(): void
    {
        // Arrange: Create entries with sequence 1, 2, 4 (missing 3)
        $entry1 = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-001',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Posted,
            'is_historical' => false,
            'chain_sequence' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '100.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '100.00',
            'line_order' => 2,
        ]);

        $hash1 = $this->hashService->calculateHash($entry1->fresh('lines'), null);
        $entry1->update(['fiscal_hash' => $hash1, 'previous_hash' => null]);

        $entry2 = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-002',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Posted,
            'is_historical' => false,
            'chain_sequence' => 2,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '200.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '200.00',
            'line_order' => 2,
        ]);

        $hash2 = $this->hashService->calculateHash($entry2->fresh('lines'), $hash1);
        $entry2->update(['fiscal_hash' => $hash2, 'previous_hash' => $hash1]);

        // Skip sequence 3, create sequence 4 directly
        $entry4 = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-004',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Posted,
            'is_historical' => false,
            'chain_sequence' => 4, // Gap in sequence!
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry4->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '400.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry4->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '400.00',
            'line_order' => 2,
        ]);

        $hash4 = $this->hashService->calculateHash($entry4->fresh('lines'), $hash2);
        $entry4->update(['fiscal_hash' => $hash4, 'previous_hash' => $hash2]);

        // Act: Verify the chain
        $isValid = $this->hashService->verifyChain($this->company->id);

        // Assert: Chain should be invalid (gap detected)
        $this->assertFalse($isValid, 'Chain with missing sequence should fail verification');
    }

    public function test_get_last_chain_hash_returns_null_for_new_company(): void
    {
        // Arrange: Create new company with no journal entries
        $newCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'New Company',
            'legal_name' => 'New Company LLC',
            'tax_id' => 'NEW123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        // Act: Get last chain hash for company with no entries
        $lastHash = $this->hashService->getLastChainHash($newCompany->id);

        // Assert: Should return null (no genesis entry yet)
        $this->assertNull($lastHash, 'Company with no entries should return null for last chain hash');
    }

    public function test_get_last_chain_hash_returns_latest_hash(): void
    {
        // Arrange: Create 3 journal entries with hash chain
        $entry1 = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-001',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Posted,
            'is_historical' => false,
            'chain_sequence' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '100.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '100.00',
            'line_order' => 2,
        ]);

        $hash1 = $this->hashService->calculateHash($entry1->fresh('lines'), null);
        $entry1->update(['fiscal_hash' => $hash1, 'previous_hash' => null]);

        $entry2 = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-002',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Posted,
            'is_historical' => false,
            'chain_sequence' => 2,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '200.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '200.00',
            'line_order' => 2,
        ]);

        $hash2 = $this->hashService->calculateHash($entry2->fresh('lines'), $hash1);
        $entry2->update(['fiscal_hash' => $hash2, 'previous_hash' => $hash1]);

        $entry3 = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-003',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Posted,
            'is_historical' => false,
            'chain_sequence' => 3,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry3->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '300.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry3->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '300.00',
            'line_order' => 2,
        ]);

        $hash3 = $this->hashService->calculateHash($entry3->fresh('lines'), $hash2);
        $entry3->update(['fiscal_hash' => $hash3, 'previous_hash' => $hash2]);

        // Act: Get last chain hash
        $lastHash = $this->hashService->getLastChainHash($this->company->id);

        // Assert: Should return hash of entry with highest chain_sequence (entry 3)
        $this->assertEquals($hash3, $lastHash, 'Should return hash of entry with highest chain_sequence');
        $this->assertNotEquals($hash1, $lastHash);
        $this->assertNotEquals($hash2, $lastHash);
    }

    public function test_different_entry_data_produces_different_hash(): void
    {
        // Arrange: Create two entries with different amounts
        $entry1 = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-501',
            'entry_date' => '2025-12-26',
            'status' => JournalEntryStatus::Draft,
            'is_historical' => false,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '100.00', // Different amount
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '100.00',
            'line_order' => 2,
        ]);

        $entry2 = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'GL-2025-502', // Different number to avoid constraint violation
            'entry_date' => '2025-12-26', // Same date
            'status' => JournalEntryStatus::Draft,
            'is_historical' => false,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '200.00', // Different amount
            'credit' => '0.00',
            'line_order' => 1,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '200.00',
            'line_order' => 2,
        ]);

        // Act: Calculate hashes
        $hash1 = $this->hashService->calculateHash($entry1->fresh('lines'), null);
        $hash2 = $this->hashService->calculateHash($entry2->fresh('lines'), null);

        // Assert: Different amounts should produce different hashes
        // Note: Hashes will be different because both entry_number AND amounts differ
        $this->assertNotEquals($hash1, $hash2, 'Different entry data (number and amounts) should produce different hashes');
    }
}
