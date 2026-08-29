<?php

declare(strict_types=1);

use App\Modules\Partner\Domain\PartnerIndexNames;
use App\Shared\Database\MigrationOutput;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Normalize partner VAT-number uniqueness to company scope while retaining
 * lifetime ownership. NULL VAT numbers remain outside the collision census.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array COMPANY_COLUMNS = ['company_id', 'vat_number'];

    /** @var list<string> */
    private const array TENANT_COLUMNS = ['tenant_id', 'vat_number'];

    private const string TAG = '[G-3A M3]';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            $this->emit(sprintf('%s SKIPPED: unsupported driver %s.', self::TAG, $driver));

            return;
        }

        if (! Schema::hasTable('partners')) {
            $this->emit(self::TAG.' SKIPPED: no partners table.');

            return;
        }

        foreach (['tenant_id', 'company_id', 'vat_number'] as $column) {
            if (! Schema::hasColumn('partners', $column)) {
                $this->emit(sprintf('%s SKIPPED: partners.%s is absent.', self::TAG, $column));

                return;
            }
        }

        $this->assertNoCompanyVatCollisions();
        $this->dropTenantWideUniques($driver);

        if (! Schema::hasIndex('partners', self::COMPANY_COLUMNS, 'unique')) {
            Schema::table('partners', function (Blueprint $table): void {
                $table->unique(self::COMPANY_COLUMNS, PartnerIndexNames::COMPANY_VAT_UNIQUE);
            });
        }

        $this->emit(self::TAG.' partners unique scope: (company_id, vat_number).');
    }

    /**
     * Forward-only state normalization. A pre-existing company unique is
     * indistinguishable from one repaired here, and restoring tenant-wide VAT
     * uniqueness would reject legitimate sibling-company values written later.
     */
    public function down(): void
    {
        Log::info('partner_company_vat_unique: forward-only migration, down() is a no-op.');
    }

    private function assertNoCompanyVatCollisions(): void
    {
        $collisions = DB::table('partners')
            ->select(['company_id', 'vat_number'])
            ->selectRaw('COUNT(*) AS row_count')
            ->whereNotNull('vat_number')
            ->groupBy('company_id', 'vat_number')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('company_id')
            ->orderBy('vat_number')
            ->get();

        $this->emit(sprintf(
            '%s partner company/VAT collision groups found: %d',
            self::TAG,
            $collisions->count(),
        ));

        if ($collisions->isEmpty()) {
            return;
        }

        $details = [];
        foreach ($collisions as $collision) {
            /** @var object{company_id: string, vat_number: string, row_count: int|string} $collision */
            $details[] = sprintf(
                'company=%s vat_number=%s (%d row(s))',
                $collision->company_id,
                $collision->vat_number,
                (int) $collision->row_count,
            );
        }

        Log::error('partner_company_vat_unique.collision_census', [
            'collision_groups' => $collisions->count(),
            'collisions' => $details,
        ]);

        throw new RuntimeException(
            'Cannot enforce unique(company_id, vat_number) on partners; resolve '
            .$collisions->count().' collision group(s): '.implode('; ', $details)
        );
    }

    private function dropTenantWideUniques(string $driver): void
    {
        foreach (Schema::getIndexes('partners') as $index) {
            if (! $index['unique'] || $index['primary'] || $index['columns'] !== self::TENANT_COLUMNS) {
                continue;
            }

            $name = $index['name'];

            if ($driver === 'pgsql') {
                $this->dropPostgresUnique($name);

                continue;
            }

            Schema::table('partners', function (Blueprint $table) use ($name): void {
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
                  AND t.relname = 'partners'
                  AND c.conname = ?
                  AND c.contype = 'u'
                SQL,
            [$name],
        );

        $quotedName = '"'.str_replace('"', '""', $name).'"';
        if ($constraint !== null) {
            DB::statement('ALTER TABLE partners DROP CONSTRAINT IF EXISTS '.$quotedName);

            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.$quotedName);
    }

    private function emit(string $message): void
    {
        MigrationOutput::info($message);
    }
};
