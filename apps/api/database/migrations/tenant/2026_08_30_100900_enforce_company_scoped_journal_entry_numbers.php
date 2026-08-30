<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\JournalEntryIndexNames;
use App\Shared\Database\MigrationOutput;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align persisted journal-entry number uniqueness with the company-scoped
 * opening allocators while leaving ordinary tenant-wide JE allocation unchanged.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array COMPANY_COLUMNS = ['company_id', 'entry_number'];

    private const string LOOKUP_INDEX = 'journal_entries_tenant_id_entry_number_index';

    /** @var list<string> */
    private const array REQUIRED_COLUMNS = ['tenant_id', 'company_id', 'entry_number'];

    /** @var list<string> */
    private const array TENANT_COLUMNS = ['tenant_id', 'entry_number'];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! $this->supportsMigration($driver)) {
            return;
        }

        $hasTenantUnique = $this->hasNamedUnique(JournalEntryIndexNames::TENANT_ENTRY_NUMBER_UNIQUE);
        $hasCompanyUnique = $this->hasNamedUnique(JournalEntryIndexNames::COMPANY_ENTRY_NUMBER_UNIQUE);

        // Once company-scoped, sibling companies may legally share an entry
        // number. Re-entry must not classify those rows as pre-migration damage.
        if ($hasCompanyUnique && ! $hasTenantUnique) {
            $this->ensureLookupIndex();
            $this->emitInfo('journal_entries.number_scope_census state=already_company_scoped');

            return;
        }

        $this->assertNoCrossCompanyNumberCollisions();
        $this->dropNamedUnique($driver, JournalEntryIndexNames::TENANT_ENTRY_NUMBER_UNIQUE);

        if (! $this->hasNamedUnique(JournalEntryIndexNames::COMPANY_ENTRY_NUMBER_UNIQUE)) {
            Schema::table('journal_entries', function (Blueprint $table): void {
                $table->unique(self::COMPANY_COLUMNS, JournalEntryIndexNames::COMPANY_ENTRY_NUMBER_UNIQUE);
            });
        }

        $this->ensureLookupIndex();
        $this->emitInfo('journal_entries.number_scope unique=(company_id,entry_number)');
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! $this->supportsMigration($driver)) {
            return;
        }

        if ($this->hasNamedUnique(JournalEntryIndexNames::TENANT_ENTRY_NUMBER_UNIQUE)
            && ! $this->hasNamedUnique(JournalEntryIndexNames::COMPANY_ENTRY_NUMBER_UNIQUE)) {
            $this->emitInfo('journal_entries.number_scope_census state=already_tenant_scoped');

            return;
        }

        $this->assertNoCrossCompanyNumberCollisions();
        $this->dropNamedUnique($driver, JournalEntryIndexNames::COMPANY_ENTRY_NUMBER_UNIQUE);

        if (! $this->hasNamedUnique(JournalEntryIndexNames::TENANT_ENTRY_NUMBER_UNIQUE)) {
            Schema::table('journal_entries', function (Blueprint $table): void {
                $table->unique(self::TENANT_COLUMNS, JournalEntryIndexNames::TENANT_ENTRY_NUMBER_UNIQUE);
            });
        }

        $this->emitInfo('journal_entries.number_scope unique=(tenant_id,entry_number)');
    }

    private function supportsMigration(string $driver): bool
    {
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            $this->emitInfo('journal_entries.number_scope SKIPPED unsupported_driver='.$driver);

            return false;
        }

        if (! Schema::hasTable('journal_entries')) {
            $this->emitInfo('journal_entries.number_scope SKIPPED table_absent');

            return false;
        }

        foreach (self::REQUIRED_COLUMNS as $column) {
            if (! Schema::hasColumn('journal_entries', $column)) {
                $this->emitInfo('journal_entries.number_scope SKIPPED column_absent='.$column);

                return false;
            }
        }

        return true;
    }

    private function assertNoCrossCompanyNumberCollisions(): void
    {
        $aggregation = DB::connection()->getDriverName() === 'pgsql'
            ? 'array_agg(DISTINCT company_id)'
            : 'group_concat(DISTINCT company_id)';

        $collisions = DB::select(
            <<<SQL
                SELECT entry_number,
                       COUNT(DISTINCT company_id) AS companies,
                       {$aggregation} AS company_ids
                FROM journal_entries
                GROUP BY entry_number
                HAVING COUNT(DISTINCT company_id) > 1
                ORDER BY entry_number
                SQL,
        );

        $details = [];
        foreach ($collisions as $collision) {
            /** @var object{entry_number: string, companies: int|string, company_ids: string} $collision */
            $details[] = sprintf(
                'number=%s companies=%d company_ids=%s',
                $collision->entry_number,
                (int) $collision->companies,
                $collision->company_ids,
            );
        }

        $message = sprintf('journal_entries.number_scope_census collisions=%d', count($collisions));
        if ($details !== []) {
            $message .= ' '.implode('; ', $details);
            $this->emitError($message);

            throw new RuntimeException(
                'Cannot change journal_entries number scope; resolve '.count($collisions)
                .' collision group(s): '.implode('; ', $details),
            );
        }

        $this->emitInfo($message);
    }

    private function ensureLookupIndex(): void
    {
        foreach (Schema::getIndexes('journal_entries') as $index) {
            if (! $index['unique'] && $index['columns'] === self::TENANT_COLUMNS) {
                return;
            }
        }

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->index(self::TENANT_COLUMNS, self::LOOKUP_INDEX);
        });
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

    private function dropNamedUnique(string $driver, string $name): void
    {
        if (! $this->hasNamedUnique($name)) {
            return;
        }

        if ($driver === 'pgsql') {
            $this->dropPostgresUnique($name);

            return;
        }

        Schema::table('journal_entries', function (Blueprint $table) use ($name): void {
            $table->dropUnique($name);
        });
    }

    private function dropPostgresUnique(string $name): void
    {
        $constraint = DB::selectOne(
            <<<'SQL'
                SELECT 1
                FROM pg_constraint c
                JOIN pg_class t ON t.oid = c.conrelid
                JOIN pg_namespace n ON n.oid = t.relnamespace
                WHERE n.nspname = current_schema()
                  AND t.relname = 'journal_entries'
                  AND c.conname = ?
                  AND c.contype = 'u'
                SQL,
            [$name],
        );

        $quotedName = '"'.str_replace('"', '""', $name).'"';
        if ($constraint !== null) {
            DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS '.$quotedName);

            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.$quotedName);
    }

    private function emitInfo(string $message): void
    {
        MigrationOutput::info($message);
    }

    private function emitError(string $message): void
    {
        MigrationOutput::error($message);
    }
};
