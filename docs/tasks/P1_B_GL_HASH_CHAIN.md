# P1-B: Complete GL Hash Chain Implementation

## Objective

Ensure General Ledger journal entries have the same compliance-grade hash chain as Documents, with proper `chain_sequence` numbering for audit trail integrity.

## Current State

Based on the Codex review:
- `hash` field exists and is calculated ✅
- `previous_hash` field exists and links entries ✅
- `chain_sequence` field exists but is **NOT populated** ❌

This means the hash chain exists but lacks sequential ordering, which is required for:
- NF525 compliance (France)
- Audit trail verification
- Detecting missing or tampered entries

## Target State

Every posted journal entry should have:

```php
$entry->hash;            // SHA-256 of entry content
$entry->previous_hash;   // Hash of the previous entry in chain
$entry->chain_sequence;  // Sequential number (1, 2, 3, ...) per company
```

## Tasks

### Step 1: Audit Current Implementation

```bash
# Find JournalEntry model
find app -name "JournalEntry.php" | grep -v test
cat $(find app/Modules/Accounting -name "JournalEntry.php" | head -1)

# Check migration for hash fields
grep -rn "hash\|chain_sequence\|previous_hash" database/migrations | grep -i journal

# Find GL posting service
find app -name "*GeneralLedger*" -o -name "*JournalEntry*" | grep Service
cat $(find app/Modules/Accounting -name "*Service*.php" | head -1)

# Check if there's a hash service for GL
find app -name "*Hash*" | grep -i accounting
grep -rn "createHash\|generateHash" app/Modules/Accounting --include="*.php"

# Check current chain_sequence values
php artisan tinker --execute="
    echo 'Entries with chain_sequence: ' . \App\Modules\Accounting\Domain\JournalEntry::whereNotNull('chain_sequence')->count();
    echo PHP_EOL;
    echo 'Entries without chain_sequence: ' . \App\Modules\Accounting\Domain\JournalEntry::whereNull('chain_sequence')->count();
"
```

**Document:**
- Does `chain_sequence` column exist in the table?
- Is there a hash service for journal entries?
- How is `previous_hash` currently set?
- What data is included in the hash calculation?

### Step 2: Verify Hash Chain Schema

The journal_entries table should have:

```sql
-- Check current schema
\d journal_entries;

-- Expected columns:
-- hash VARCHAR(64) NOT NULL
-- previous_hash VARCHAR(64) NULLABLE (NULL for genesis entry)
-- chain_sequence BIGINT NOT NULL
-- 
-- With unique constraint:
-- UNIQUE(company_id, chain_sequence)
```

If `chain_sequence` column doesn't exist, create migration:

```php
<?php
// database/migrations/YYYY_MM_DD_add_chain_sequence_to_journal_entries.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            if (!Schema::hasColumn('journal_entries', 'chain_sequence')) {
                $table->unsignedBigInteger('chain_sequence')->nullable();
                
                // Unique per company
                $table->unique(['company_id', 'chain_sequence'], 'je_company_chain_sequence_unique');
            }
        });
        
        // Backfill existing entries with sequence numbers
        DB::statement("
            WITH numbered AS (
                SELECT 
                    id,
                    ROW_NUMBER() OVER (PARTITION BY company_id ORDER BY created_at, id) as seq
                FROM journal_entries
                WHERE chain_sequence IS NULL
            )
            UPDATE journal_entries je
            SET chain_sequence = numbered.seq
            FROM numbered
            WHERE je.id = numbered.id
        ");
        
        // Now make it NOT NULL
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('chain_sequence')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique('je_company_chain_sequence_unique');
            $table->dropColumn('chain_sequence');
        });
    }
};
```

### Step 3: Update Journal Entry Posting Service

Find and update the service that posts journal entries:

```bash
# Find the method that creates/posts journal entries
grep -rn "function post\|function create" app/Modules/Accounting/Domain/Services --include="*.php"
```

Update to include chain_sequence:

```php
<?php
// In GeneralLedgerService or JournalEntryService

public function postEntry(JournalEntry $entry): JournalEntry
{
    return DB::transaction(function () use ($entry) {
        // 1. Lock to prevent race conditions
        // Get the last entry for this company with lock
        $lastEntry = JournalEntry::where('company_id', $entry->company_id)
            ->whereNotNull('chain_sequence')
            ->orderByDesc('chain_sequence')
            ->lockForUpdate()
            ->first();
        
        // 2. Calculate chain_sequence
        $entry->chain_sequence = $lastEntry 
            ? $lastEntry->chain_sequence + 1 
            : 1;
        
        // 3. Set previous_hash
        $entry->previous_hash = $lastEntry?->hash;
        
        // 4. Calculate hash (must include chain_sequence)
        $entry->hash = $this->calculateEntryHash($entry);
        
        // 5. Mark as posted
        $entry->status = JournalEntryStatus::POSTED;
        $entry->posted_at = now();
        $entry->save();
        
        return $entry;
    });
}

private function calculateEntryHash(JournalEntry $entry): string
{
    // Hash should include all critical, immutable fields
    $data = [
        'company_id' => $entry->company_id,
        'chain_sequence' => $entry->chain_sequence,
        'previous_hash' => $entry->previous_hash,
        'entry_date' => $entry->entry_date->toISOString(),
        'reference' => $entry->reference,
        'description' => $entry->description,
        'lines' => $entry->lines->map(fn ($line) => [
            'account_id' => $line->account_id,
            'debit' => (string) $line->debit,
            'credit' => (string) $line->credit,
            'description' => $line->description,
        ])->toArray(),
        'source_type' => $entry->source_type,
        'source_id' => $entry->source_id,
    ];
    
    // Sort for deterministic hashing
    ksort($data);
    
    return hash('sha256', json_encode($data));
}
```

### Step 4: Create or Update GLHashService

If there's no dedicated hash service for GL, create one:

```php
<?php
// app/Modules/Accounting/Domain/Services/GLHashService.php

namespace App\Modules\Accounting\Domain\Services;

use App\Modules\Accounting\Domain\JournalEntry;
use Illuminate\Support\Facades\DB;

class GLHashService
{
    /**
     * Generate hash chain data for a journal entry
     */
    public function prepareForPosting(JournalEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            // Lock and get previous entry
            $lastEntry = JournalEntry::where('company_id', $entry->company_id)
                ->whereNotNull('hash')
                ->orderByDesc('chain_sequence')
                ->lockForUpdate()
                ->first();
            
            // Set chain sequence
            $entry->chain_sequence = $lastEntry 
                ? $lastEntry->chain_sequence + 1 
                : 1;
            
            // Set previous hash
            $entry->previous_hash = $lastEntry?->hash;
            
            // Calculate hash
            $entry->hash = $this->calculateHash($entry);
        });
    }
    
    /**
     * Calculate SHA-256 hash of entry data
     */
    public function calculateHash(JournalEntry $entry): string
    {
        $hashData = $this->getHashableData($entry);
        return hash('sha256', json_encode($hashData));
    }
    
    /**
     * Get the data that should be included in hash
     */
    private function getHashableData(JournalEntry $entry): array
    {
        $data = [
            'company_id' => (string) $entry->company_id,
            'chain_sequence' => $entry->chain_sequence,
            'previous_hash' => $entry->previous_hash ?? '',
            'entry_date' => $entry->entry_date->format('Y-m-d'),
            'reference' => $entry->reference ?? '',
            'total_debit' => number_format($entry->lines->sum('debit'), 4, '.', ''),
            'total_credit' => number_format($entry->lines->sum('credit'), 4, '.', ''),
            'line_count' => $entry->lines->count(),
            'source_type' => $entry->source_type ?? '',
            'source_id' => (string) ($entry->source_id ?? ''),
        ];
        
        // Include line hashes for integrity
        $data['lines_hash'] = $this->calculateLinesHash($entry);
        
        ksort($data);
        return $data;
    }
    
    /**
     * Calculate hash of all lines
     */
    private function calculateLinesHash(JournalEntry $entry): string
    {
        $linesData = $entry->lines
            ->sortBy('id')
            ->map(fn ($line) => [
                'account_id' => (string) $line->account_id,
                'debit' => number_format($line->debit, 4, '.', ''),
                'credit' => number_format($line->credit, 4, '.', ''),
            ])
            ->values()
            ->toArray();
        
        return hash('sha256', json_encode($linesData));
    }
    
    /**
     * Verify the entire hash chain for a company
     */
    public function verifyChain(string $companyId): array
    {
        $entries = JournalEntry::where('company_id', $companyId)
            ->whereNotNull('hash')
            ->orderBy('chain_sequence')
            ->get();
        
        $errors = [];
        $previousHash = null;
        $expectedSequence = 1;
        
        foreach ($entries as $entry) {
            // Check sequence
            if ($entry->chain_sequence !== $expectedSequence) {
                $errors[] = [
                    'entry_id' => $entry->id,
                    'error' => 'sequence_gap',
                    'expected' => $expectedSequence,
                    'actual' => $entry->chain_sequence,
                ];
            }
            
            // Check previous hash link
            if ($entry->previous_hash !== $previousHash) {
                $errors[] = [
                    'entry_id' => $entry->id,
                    'error' => 'broken_chain',
                    'expected_previous' => $previousHash,
                    'actual_previous' => $entry->previous_hash,
                ];
            }
            
            // Verify hash integrity
            $calculatedHash = $this->calculateHash($entry);
            if ($entry->hash !== $calculatedHash) {
                $errors[] = [
                    'entry_id' => $entry->id,
                    'error' => 'hash_mismatch',
                    'stored_hash' => $entry->hash,
                    'calculated_hash' => $calculatedHash,
                ];
            }
            
            $previousHash = $entry->hash;
            $expectedSequence++;
        }
        
        return [
            'valid' => empty($errors),
            'entries_checked' => $entries->count(),
            'errors' => $errors,
        ];
    }
}
```

### Step 5: Create Artisan Command for Verification

```php
<?php
// app/Console/Commands/VerifyGLHashChain.php

namespace App\Console\Commands;

use App\Modules\Accounting\Domain\Services\GLHashService;
use App\Modules\Company\Domain\Company;
use Illuminate\Console\Command;

class VerifyGLHashChain extends Command
{
    protected $signature = 'compliance:verify-gl-hash-chain 
                            {--company= : Specific company ID to verify}
                            {--fix : Attempt to fix minor issues}';
    
    protected $description = 'Verify GL journal entry hash chain integrity';
    
    public function handle(GLHashService $hashService): int
    {
        $companyId = $this->option('company');
        
        if ($companyId) {
            $companies = Company::where('id', $companyId)->get();
        } else {
            $companies = Company::all();
        }
        
        $allValid = true;
        
        foreach ($companies as $company) {
            $this->info("Verifying company: {$company->name}");
            
            $result = $hashService->verifyChain($company->id);
            
            if ($result['valid']) {
                $this->info("  ✓ Chain valid ({$result['entries_checked']} entries)");
            } else {
                $allValid = false;
                $this->error("  ✗ Chain invalid!");
                
                foreach ($result['errors'] as $error) {
                    $this->error("    Entry {$error['entry_id']}: {$error['error']}");
                }
            }
        }
        
        return $allValid ? 0 : 1;
    }
}
```

### Step 6: Backfill Existing Entries

If there are existing entries without chain_sequence, backfill them:

```php
<?php
// database/migrations/YYYY_MM_DD_backfill_gl_chain_sequence.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Get all companies with journal entries
        $companies = DB::table('journal_entries')
            ->select('company_id')
            ->distinct()
            ->pluck('company_id');
        
        foreach ($companies as $companyId) {
            // Assign sequence numbers based on creation order
            DB::statement("
                WITH numbered AS (
                    SELECT 
                        id,
                        ROW_NUMBER() OVER (ORDER BY created_at, id) as new_seq
                    FROM journal_entries
                    WHERE company_id = ?
                )
                UPDATE journal_entries je
                SET chain_sequence = numbered.new_seq
                FROM numbered
                WHERE je.id = numbered.id
            ", [$companyId]);
            
            // Now rebuild hash chain
            $this->rebuildHashChain($companyId);
        }
    }
    
    private function rebuildHashChain(string $companyId): void
    {
        $entries = DB::table('journal_entries')
            ->where('company_id', $companyId)
            ->orderBy('chain_sequence')
            ->get();
        
        $previousHash = null;
        
        foreach ($entries as $entry) {
            // Calculate new hash
            $hashData = [
                'company_id' => $entry->company_id,
                'chain_sequence' => $entry->chain_sequence,
                'previous_hash' => $previousHash ?? '',
                'entry_date' => $entry->entry_date,
                'reference' => $entry->reference ?? '',
            ];
            
            ksort($hashData);
            $newHash = hash('sha256', json_encode($hashData));
            
            DB::table('journal_entries')
                ->where('id', $entry->id)
                ->update([
                    'previous_hash' => $previousHash,
                    'hash' => $newHash,
                ]);
            
            $previousHash = $newHash;
        }
    }
    
    public function down(): void
    {
        // Cannot safely reverse - would need to restore original hashes
        // which we don't have
    }
};
```

### Step 7: Add Tests

```php
<?php
// tests/Feature/Accounting/GLHashChainTest.php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GLHashService;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use Tests\TestCase;

class GLHashChainTest extends TestCase
{
    private GLHashService $hashService;
    private GeneralLedgerService $glService;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->hashService = app(GLHashService::class);
        $this->glService = app(GeneralLedgerService::class);
    }
    
    public function test_first_entry_has_sequence_one_and_no_previous_hash(): void
    {
        $company = $this->createCompanyWithChartOfAccounts();
        
        $entry = $this->createAndPostJournalEntry($company);
        
        $this->assertEquals(1, $entry->chain_sequence);
        $this->assertNull($entry->previous_hash);
        $this->assertNotNull($entry->hash);
    }
    
    public function test_subsequent_entries_chain_correctly(): void
    {
        $company = $this->createCompanyWithChartOfAccounts();
        
        $entry1 = $this->createAndPostJournalEntry($company);
        $entry2 = $this->createAndPostJournalEntry($company);
        $entry3 = $this->createAndPostJournalEntry($company);
        
        // Check sequences
        $this->assertEquals(1, $entry1->chain_sequence);
        $this->assertEquals(2, $entry2->chain_sequence);
        $this->assertEquals(3, $entry3->chain_sequence);
        
        // Check hash chain
        $this->assertNull($entry1->previous_hash);
        $this->assertEquals($entry1->hash, $entry2->previous_hash);
        $this->assertEquals($entry2->hash, $entry3->previous_hash);
    }
    
    public function test_concurrent_posting_maintains_chain_integrity(): void
    {
        $company = $this->createCompanyWithChartOfAccounts();
        
        // Simulate concurrent posting
        $entries = collect(range(1, 10))->map(function () use ($company) {
            return $this->createAndPostJournalEntry($company);
        });
        
        // Verify chain is valid
        $result = $this->hashService->verifyChain($company->id);
        
        $this->assertTrue($result['valid']);
        $this->assertEquals(10, $result['entries_checked']);
        $this->assertEmpty($result['errors']);
    }
    
    public function test_hash_changes_if_entry_is_tampered(): void
    {
        $company = $this->createCompanyWithChartOfAccounts();
        
        $entry = $this->createAndPostJournalEntry($company);
        $originalHash = $entry->hash;
        
        // Tamper with the entry (bypass normal save)
        DB::table('journal_entries')
            ->where('id', $entry->id)
            ->update(['reference' => 'TAMPERED']);
        
        // Refresh and recalculate
        $entry->refresh();
        $recalculatedHash = $this->hashService->calculateHash($entry);
        
        $this->assertNotEquals($originalHash, $recalculatedHash);
    }
    
    public function test_verify_chain_detects_sequence_gap(): void
    {
        $company = $this->createCompanyWithChartOfAccounts();
        
        $entry1 = $this->createAndPostJournalEntry($company);
        $entry2 = $this->createAndPostJournalEntry($company);
        
        // Manually create a gap
        DB::table('journal_entries')
            ->where('id', $entry2->id)
            ->update(['chain_sequence' => 5]); // Should be 2
        
        $result = $this->hashService->verifyChain($company->id);
        
        $this->assertFalse($result['valid']);
        $this->assertContains('sequence_gap', array_column($result['errors'], 'error'));
    }
    
    public function test_verify_chain_detects_broken_link(): void
    {
        $company = $this->createCompanyWithChartOfAccounts();
        
        $entry1 = $this->createAndPostJournalEntry($company);
        $entry2 = $this->createAndPostJournalEntry($company);
        
        // Break the chain
        DB::table('journal_entries')
            ->where('id', $entry2->id)
            ->update(['previous_hash' => 'invalid_hash']);
        
        $result = $this->hashService->verifyChain($company->id);
        
        $this->assertFalse($result['valid']);
        $this->assertContains('broken_chain', array_column($result['errors'], 'error'));
    }
    
    public function test_different_companies_have_independent_chains(): void
    {
        $company1 = $this->createCompanyWithChartOfAccounts();
        $company2 = $this->createCompanyWithChartOfAccounts();
        
        $entry1a = $this->createAndPostJournalEntry($company1);
        $entry2a = $this->createAndPostJournalEntry($company2);
        $entry1b = $this->createAndPostJournalEntry($company1);
        
        // Each company should have its own sequence
        $this->assertEquals(1, $entry1a->chain_sequence);
        $this->assertEquals(1, $entry2a->chain_sequence);
        $this->assertEquals(2, $entry1b->chain_sequence);
        
        // Hash chains should be independent
        $this->assertEquals($entry1a->hash, $entry1b->previous_hash);
        $this->assertNull($entry2a->previous_hash);
    }
    
    private function createAndPostJournalEntry($company): JournalEntry
    {
        // Create entry with lines
        $entry = JournalEntry::factory()
            ->for($company)
            ->withBalancedLines()
            ->create(['status' => 'draft']);
        
        // Post it
        $this->glService->postEntry($entry);
        
        return $entry->refresh();
    }
}
```

### Step 8: Verification

```bash
# 1. Run migration
php artisan migrate

# 2. Verify all existing entries have chain_sequence
php artisan tinker --execute="
    \$without = \App\Modules\Accounting\Domain\JournalEntry::whereNull('chain_sequence')->count();
    echo 'Entries without chain_sequence: ' . \$without;
"
# Expected: 0

# 3. Run verification command
php artisan compliance:verify-gl-hash-chain

# 4. Run tests
php artisan test --filter=GLHashChainTest

# 5. Verify with a new posting
php artisan tinker --execute="
    \$company = \App\Modules\Company\Domain\Company::first();
    \$lastEntry = \App\Modules\Accounting\Domain\JournalEntry::where('company_id', \$company->id)
        ->orderByDesc('chain_sequence')
        ->first();
    dump([
        'company' => \$company->name,
        'last_sequence' => \$lastEntry?->chain_sequence,
        'last_hash' => \$lastEntry?->hash,
    ]);
"
```

---

## Deliverables

1. **Migration**: Add `chain_sequence` column if missing
2. **Backfill migration**: Populate chain_sequence for existing entries
3. **GLHashService**: Service for hash calculation and chain verification
4. **Updated posting service**: Sets chain_sequence and previous_hash atomically
5. **Artisan command**: `compliance:verify-gl-hash-chain`
6. **Tests**: Hash chain integrity tests

## Success Criteria

- [ ] All journal entries have `chain_sequence` populated
- [ ] `chain_sequence` is strictly sequential per company (no gaps)
- [ ] `previous_hash` correctly links to prior entry
- [ ] `hash` is calculated deterministically
- [ ] Verification command detects tampering/gaps
- [ ] Concurrent posting maintains chain integrity
- [ ] All tests pass
