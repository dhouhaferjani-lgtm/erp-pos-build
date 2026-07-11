<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Treasury spine Wave B gate (Fix 1): the partial UNIQUE index
 * uniq_je_company_chain_sequence is the HARD DB backstop against a forked
 * fiscal hash chain. The Task 7 advisory lock is a no-op on autocommit posting
 * paths, so without this index two concurrent posts could allocate the same
 * (company_id, chain_sequence) and fork the chain.
 *
 * Drafts (chain_sequence NULL) are excluded from the unique constraint and must
 * remain freely insertable; only POSTED entries carry a chain_sequence and must
 * be unique per company.
 *
 * Partial UNIQUE indexes are enforced by both PostgreSQL and modern SQLite, so
 * the violation assertion runs on either driver.
 */
final class ChainSequenceUniqueIndexTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        app(CompanyContext::class)->clear();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
    }

    public function test_duplicate_posted_chain_sequence_for_same_company_is_rejected(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            $this->markTestSkipped("Partial UNIQUE index enforcement is validated on pgsql/sqlite only; driver is {$driver}.");
        }

        // First posted entry claims chain_sequence 1.
        JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'CHAIN-UNIQ-001',
            'entry_date' => '2025-05-01',
            'description' => 'First posted entry',
            'status' => JournalEntryStatus::Posted,
            'chain_sequence' => 1,
            'fiscal_hash' => str_repeat('a', 64),
        ]);

        // A second posted entry for the SAME company with the SAME chain_sequence
        // must violate uniq_je_company_chain_sequence.
        $this->expectException(QueryException::class);

        DB::table('journal_entries')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'CHAIN-UNIQ-002',
            'entry_date' => '2025-05-02',
            'description' => 'Duplicate chain_sequence (forked chain)',
            'status' => JournalEntryStatus::Posted->value,
            'chain_sequence' => 1,
            'fiscal_hash' => str_repeat('b', 64),
        ]);
    }

    public function test_multiple_drafts_with_null_chain_sequence_are_allowed(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            $this->markTestSkipped("Partial UNIQUE index enforcement is validated on pgsql/sqlite only; driver is {$driver}.");
        }

        // Drafts carry a NULL chain_sequence and must remain non-unique — the
        // partial index's WHERE chain_sequence IS NOT NULL predicate excludes them.
        $first = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'CHAIN-DRAFT-001',
            'entry_date' => '2025-05-01',
            'description' => 'First draft',
            'status' => JournalEntryStatus::Draft,
        ]);

        $second = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'CHAIN-DRAFT-002',
            'entry_date' => '2025-05-02',
            'description' => 'Second draft',
            'status' => JournalEntryStatus::Draft,
        ]);

        $this->assertNull($first->fresh()?->chain_sequence);
        $this->assertNull($second->fresh()?->chain_sequence);
    }
}
