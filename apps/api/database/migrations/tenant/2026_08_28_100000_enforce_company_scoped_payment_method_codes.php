<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Session E I-1 follow-up — normalize payment-method code uniqueness to the
 * company boundary used by every production code resolver.
 *
 * The original table declared `unique(tenant_id, code)`, while
 * `2025_12_30_195300_fix_multi_company_unique_constraints` changed healthy
 * databases to `unique(company_id, code)`. This migration is the repair for a
 * partially applied or drifted tenant: it discovers unique indexes by their
 * columns rather than trusting one historical name, refuses with an explicit
 * collision census before adding the company unique, removes the tenant-wide
 * unique, and no-ops when the correct shape is already present.
 *
 * PostgreSQL unique constraints and standalone unique indexes need different
 * DROP syntax. SQLite represents both through its index catalog. Both paths
 * are exercised by PaymentMethodCompanyCodeUniqueMigrationTest.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array COMPANY_COLUMNS = ['company_id', 'code'];

    private const string COMPANY_UNIQUE = 'payment_methods_company_id_code_unique';

    /** @var list<string> */
    private const array TENANT_COLUMNS = ['tenant_id', 'code'];

    private const string TAG = '[SESSION-E-I1]';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            $this->emit(sprintf('%s SKIPPED: unsupported driver %s.', self::TAG, $driver));

            return;
        }

        if (! Schema::hasTable('payment_methods')) {
            $this->emit(self::TAG.' SKIPPED: no payment_methods table.');

            return;
        }

        foreach (['tenant_id', 'company_id', 'code'] as $column) {
            if (! Schema::hasColumn('payment_methods', $column)) {
                $this->emit(sprintf('%s SKIPPED: payment_methods.%s is absent.', self::TAG, $column));

                return;
            }
        }

        $this->assertNoCompanyCodeCollisions();
        $this->dropTenantWideUniques($driver);

        if (! Schema::hasIndex('payment_methods', self::COMPANY_COLUMNS, 'unique')) {
            Schema::table('payment_methods', function (Blueprint $table): void {
                $table->unique(self::COMPANY_COLUMNS, self::COMPANY_UNIQUE);
            });
        }

        $this->emit(self::TAG.' payment_methods unique scope: (company_id, code).');
    }

    /**
     * Forward-only state normalization. A healthy database already carried the
     * company unique before this migration; down() cannot distinguish that
     * pre-existing constraint from one repaired here. Restoring tenant-wide
     * uniqueness would also reject legitimate sibling-company codes written
     * after this migration.
     */
    public function down(): void
    {
        Log::info('payment_method_company_code_unique: forward-only migration, down() is a no-op.');
    }

    private function assertNoCompanyCodeCollisions(): void
    {
        $collisions = DB::table('payment_methods')
            ->select(['company_id', 'code'])
            ->selectRaw('COUNT(*) AS row_count')
            ->groupBy('company_id', 'code')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('company_id')
            ->orderBy('code')
            ->get();

        $this->emit(sprintf(
            '%s payment-method company/code collision groups found: %d',
            self::TAG,
            $collisions->count(),
        ));

        if ($collisions->isEmpty()) {
            return;
        }

        $details = [];
        foreach ($collisions as $collision) {
            /** @var object{company_id: string, code: string, row_count: int|string} $collision */
            $details[] = sprintf(
                'company=%s code=%s (%d row(s))',
                $collision->company_id,
                $collision->code,
                (int) $collision->row_count,
            );
        }

        Log::error('payment_method_company_code_unique.collision_census', [
            'collision_groups' => $collisions->count(),
            'collisions' => $details,
        ]);

        throw new RuntimeException(
            'Cannot enforce unique(company_id, code) on payment_methods; resolve '
            .$collisions->count().' collision group(s): '.implode('; ', $details)
        );
    }

    private function dropTenantWideUniques(string $driver): void
    {
        foreach (Schema::getIndexes('payment_methods') as $index) {
            if (! $index['unique'] || $index['primary'] || $index['columns'] !== self::TENANT_COLUMNS) {
                continue;
            }

            $name = $index['name'];

            if ($driver === 'pgsql') {
                $this->dropPostgresUnique($name);

                continue;
            }

            Schema::table('payment_methods', function (Blueprint $table) use ($name): void {
                $table->dropUnique($name);
            });
        }
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
                  AND t.relname = 'payment_methods'
                  AND c.conname = ?
                  AND c.contype = 'u'
                SQL,
            [$name],
        );

        $quotedName = '"'.str_replace('"', '""', $name).'"';
        if ($constraint !== null) {
            DB::statement('ALTER TABLE payment_methods DROP CONSTRAINT IF EXISTS '.$quotedName);

            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.$quotedName);
    }

    private function emit(string $message): void
    {
        echo $message.PHP_EOL;
        Log::info($message);
    }
};
