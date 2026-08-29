<?php

declare(strict_types=1);

use App\Modules\Document\Domain\DocumentIndexNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Align persisted document-number uniqueness with the per-company allocator.
 * The census runs before the legacy unique is removed, includes lifetime rows,
 * and refuses ambiguous data rather than risking a legal-sequence rewrite.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array COMPANY_COLUMNS = ['company_id', 'type', 'document_number'];

    private const string LOOKUP_INDEX = 'documents_tenant_id_type_document_number_index';

    /** @var list<string> */
    private const array REQUIRED_COLUMNS = ['tenant_id', 'company_id', 'type', 'document_number'];

    /** @var list<string> */
    private const array TENANT_COLUMNS = ['tenant_id', 'type', 'document_number'];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! $this->supportsMigration($driver)) {
            return;
        }

        $hasTenantUnique = $this->hasNamedUnique(DocumentIndexNames::TENANT_TYPE_NUMBER_UNIQUE);
        $hasCompanyUnique = $this->hasNamedUnique(DocumentIndexNames::COMPANY_TYPE_NUMBER_UNIQUE);

        // A healthy, already-migrated tenant can legitimately contain the same
        // type/number in several companies. Re-entry must not reinterpret those
        // legal rows as pre-migration damage.
        if ($hasCompanyUnique && ! $hasTenantUnique) {
            $this->ensureLookupIndex();
            $this->emitInfo('documents.number_scope_census state=already_company_scoped');

            return;
        }

        $this->assertNoCrossCompanyNumberCollisions();
        $this->dropNamedUnique($driver, DocumentIndexNames::TENANT_TYPE_NUMBER_UNIQUE);

        if (! $this->hasNamedUnique(DocumentIndexNames::COMPANY_TYPE_NUMBER_UNIQUE)) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->unique(self::COMPANY_COLUMNS, DocumentIndexNames::COMPANY_TYPE_NUMBER_UNIQUE);
            });
        }

        $this->ensureLookupIndex();
        $this->emitInfo('documents.number_scope unique=(company_id,type,document_number)');
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! $this->supportsMigration($driver)) {
            return;
        }

        if ($this->hasNamedUnique(DocumentIndexNames::TENANT_TYPE_NUMBER_UNIQUE)
            && ! $this->hasNamedUnique(DocumentIndexNames::COMPANY_TYPE_NUMBER_UNIQUE)) {
            $this->emitInfo('documents.number_scope_census state=already_tenant_scoped');

            return;
        }

        $this->assertNoCrossCompanyNumberCollisions();
        $this->dropNamedUnique($driver, DocumentIndexNames::COMPANY_TYPE_NUMBER_UNIQUE);

        if (! $this->hasNamedUnique(DocumentIndexNames::TENANT_TYPE_NUMBER_UNIQUE)) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->unique(self::TENANT_COLUMNS, DocumentIndexNames::TENANT_TYPE_NUMBER_UNIQUE);
            });
        }

        $this->emitInfo('documents.number_scope unique=(tenant_id,type,document_number)');
    }

    private function supportsMigration(string $driver): bool
    {
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            $this->emitInfo('documents.number_scope SKIPPED unsupported_driver='.$driver);

            return false;
        }

        if (! Schema::hasTable('documents')) {
            $this->emitInfo('documents.number_scope SKIPPED table_absent');

            return false;
        }

        foreach (self::REQUIRED_COLUMNS as $column) {
            if (! Schema::hasColumn('documents', $column)) {
                $this->emitInfo('documents.number_scope SKIPPED column_absent='.$column);

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
                SELECT type,
                       document_number,
                       COUNT(DISTINCT company_id) AS companies,
                       {$aggregation} AS company_ids
                FROM documents
                WHERE document_number IS NOT NULL
                GROUP BY type, document_number
                HAVING COUNT(DISTINCT company_id) > 1
                ORDER BY type, document_number
                SQL,
        );

        $details = [];
        foreach ($collisions as $collision) {
            /** @var object{type: string, document_number: string, companies: int|string, company_ids: string} $collision */
            $details[] = sprintf(
                'type=%s number=%s companies=%d company_ids=%s',
                $collision->type,
                $collision->document_number,
                (int) $collision->companies,
                $collision->company_ids,
            );
        }

        $message = sprintf('documents.number_scope_census collisions=%d', count($collisions));
        if ($details !== []) {
            $message .= ' '.implode('; ', $details);
            $this->emitError($message);

            throw new RuntimeException(
                'Cannot change documents number scope; resolve '.count($collisions)
                .' collision group(s): '.implode('; ', $details),
            );
        }

        $this->emitInfo($message);
    }

    private function ensureLookupIndex(): void
    {
        foreach (Schema::getIndexes('documents') as $index) {
            if (! $index['unique'] && $index['columns'] === self::TENANT_COLUMNS) {
                return;
            }
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->index(self::TENANT_COLUMNS, self::LOOKUP_INDEX);
        });
    }

    private function hasNamedUnique(string $name): bool
    {
        foreach (Schema::getIndexes('documents') as $index) {
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

        Schema::table('documents', function (Blueprint $table) use ($name): void {
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
                  AND t.relname = 'documents'
                  AND c.conname = ?
                  AND c.contype = 'u'
                SQL,
            [$name],
        );

        $quotedName = '"'.str_replace('"', '""', $name).'"';
        if ($constraint !== null) {
            DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS '.$quotedName);

            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.$quotedName);
    }

    private function emitInfo(string $message): void
    {
        echo $message.PHP_EOL;
        Log::info($message);
    }

    private function emitError(string $message): void
    {
        echo $message.PHP_EOL;
        Log::error($message);
    }
};
