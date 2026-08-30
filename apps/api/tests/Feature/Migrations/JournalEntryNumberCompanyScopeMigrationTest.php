<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalEntryIndexNames;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\ReversibleTenantMigration;

final class JournalEntryNumberCompanyScopeMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const array COMPANY_COLUMNS = ['company_id', 'entry_number'];

    private const string LOOKUP_INDEX = 'journal_entries_tenant_id_entry_number_index';

    private const string MIGRATION_FILE = '2026_08_30_100900_enforce_company_scoped_journal_entry_numbers.php';

    /** @var list<string> */
    private const array TENANT_COLUMNS = ['tenant_id', 'entry_number'];

    /** @var Migration&ReversibleTenantMigration */
    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $path = database_path('migrations/tenant/'.self::MIGRATION_FILE);
        $this->assertFileExists($path, 'Tenant migration file missing: '.self::MIGRATION_FILE);

        /** @var Migration&ReversibleTenantMigration $migration */
        $migration = require $path;
        $this->migration = $migration;
    }

    public function test_migration_replaces_the_tenant_unique_and_reapplies_idempotently(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();

        $this->restoreTenantUnique();
        $this->createEntry($tenant, $company, 'OB-2026-000001');

        $this->migration->up();
        $this->migration->up();

        $this->assertFalse($this->hasNamedIndex(JournalEntryIndexNames::TENANT_ENTRY_NUMBER_UNIQUE));
        $this->assertTrue($this->hasNamedUnique(JournalEntryIndexNames::COMPANY_ENTRY_NUMBER_UNIQUE));
        $this->assertTrue($this->hasNamedNonUnique(self::LOOKUP_INDEX));
    }

    public function test_migration_refuses_lifetime_cross_company_collisions_before_dropping_the_old_unique(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $companyA = Company::factory()->for($tenantA)->create();
        $companyB = Company::factory()->for($tenantB)->create();

        $this->restoreTenantUnique();
        $this->createEntry($tenantA, $companyA, 'OB-2026-LIFETIME');
        $this->createEntry($tenantB, $companyB, 'OB-2026-LIFETIME');

        try {
            $this->migration->up();
            $this->fail('The migration must refuse lifetime cross-company number collisions.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('1 collision group(s)', $exception->getMessage());
            $this->assertStringContainsString('OB-2026-LIFETIME', $exception->getMessage());
            $this->assertStringContainsString((string) $companyA->id, $exception->getMessage());
            $this->assertStringContainsString((string) $companyB->id, $exception->getMessage());
        }

        $this->assertTrue($this->hasNamedUnique(JournalEntryIndexNames::TENANT_ENTRY_NUMBER_UNIQUE));
        $this->assertFalse($this->hasNamedIndex(JournalEntryIndexNames::COMPANY_ENTRY_NUMBER_UNIQUE));
    }

    public function test_company_scoped_numbers_allow_sibling_companies_and_reject_same_company_duplicates(): void
    {
        $tenant = Tenant::factory()->create();
        $companyA = Company::factory()->for($tenant)->create();
        $companyB = Company::factory()->for($tenant)->create();

        $this->restoreTenantUnique();
        $this->migration->up();

        $this->createEntry($tenant, $companyA, 'OB-2026-000001');
        $this->createEntry($tenant, $companyB, 'OB-2026-000001');

        $this->migration->up();

        try {
            DB::transaction(fn (): JournalEntry => $this->createEntry(
                $tenant,
                $companyA,
                'OB-2026-000001',
            ));
            $this->fail('The company-scoped unique must reject a duplicate number in one company.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->migration->down();
            $this->fail('Rollback must refuse cross-company duplicates that violate tenant uniqueness.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('1 collision group(s)', $exception->getMessage());
        }

        $this->assertTrue($this->hasNamedUnique(JournalEntryIndexNames::COMPANY_ENTRY_NUMBER_UNIQUE));
        $this->assertFalse($this->hasNamedIndex(JournalEntryIndexNames::TENANT_ENTRY_NUMBER_UNIQUE));
    }

    private function createEntry(Tenant $tenant, Company $company, string $number): JournalEntry
    {
        return JournalEntry::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'entry_number' => $number,
            'entry_date' => '2026-01-01',
            'description' => 'Company-scope migration fixture',
            'status' => JournalEntryStatus::Draft,
            'is_historical' => true,
        ]);
    }

    private function restoreTenantUnique(): void
    {
        $this->dropUniqueForColumns(self::COMPANY_COLUMNS);
        $this->dropUniqueForColumns(self::TENANT_COLUMNS);

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->unique(self::TENANT_COLUMNS, JournalEntryIndexNames::TENANT_ENTRY_NUMBER_UNIQUE);
        });
    }

    /** @param list<string> $columns */
    private function dropUniqueForColumns(array $columns): void
    {
        foreach (Schema::getIndexes('journal_entries') as $index) {
            if (! $index['unique'] || $index['primary'] || $index['columns'] !== $columns) {
                continue;
            }

            Schema::table('journal_entries', function (Blueprint $table) use ($index): void {
                $table->dropUnique($index['name']);
            });
        }
    }

    private function hasNamedIndex(string $name): bool
    {
        foreach (Schema::getIndexes('journal_entries') as $index) {
            if ($index['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    private function hasNamedUnique(string $name): bool
    {
        foreach (Schema::getIndexes('journal_entries') as $index) {
            if ($index['name'] === $name && $index['unique']) {
                return true;
            }
        }

        return false;
    }

    private function hasNamedNonUnique(string $name): bool
    {
        foreach (Schema::getIndexes('journal_entries') as $index) {
            if ($index['name'] === $name && ! $index['unique']) {
                return true;
            }
        }

        return false;
    }
}
