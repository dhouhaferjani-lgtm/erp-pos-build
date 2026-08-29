<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ProductIndexNames;
use App\Shared\Database\MigrationOutput;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Normalize product SKU uniqueness to the company boundary used by imports and
 * catalogue consumers. The migration discovers drifted uniques by columns,
 * refuses damaged data before dropping anything, and is safe to re-run.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array COMPANY_COLUMNS = ['company_id', 'sku'];

    /** @var list<string> */
    private const array TENANT_COLUMNS = ['tenant_id', 'sku'];

    private const string TAG = '[G-3A M1]';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            $this->emit(sprintf('%s SKIPPED: unsupported driver %s.', self::TAG, $driver));

            return;
        }

        if (! Schema::hasTable('products')) {
            $this->emit(self::TAG.' SKIPPED: no products table.');

            return;
        }

        foreach (['tenant_id', 'company_id', 'sku'] as $column) {
            if (! Schema::hasColumn('products', $column)) {
                $this->emit(sprintf('%s SKIPPED: products.%s is absent.', self::TAG, $column));

                return;
            }
        }

        $this->assertNoCompanySkuCollisions();
        $this->dropTenantWideUniques($driver);

        if (! Schema::hasIndex('products', self::COMPANY_COLUMNS, 'unique')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->unique(self::COMPANY_COLUMNS, ProductIndexNames::COMPANY_SKU_UNIQUE);
            });
        }

        $this->emit(self::TAG.' products unique scope: (company_id, sku).');
    }

    /**
     * Forward-only state normalization. A pre-existing company unique is
     * indistinguishable from one repaired here, and restoring tenant-wide
     * uniqueness would reject legitimate sibling-company SKUs written later.
     */
    public function down(): void
    {
        Log::info('product_company_sku_unique: forward-only migration, down() is a no-op.');
    }

    private function assertNoCompanySkuCollisions(): void
    {
        $collisions = DB::table('products')
            ->select(['company_id', 'sku'])
            ->selectRaw('COUNT(*) AS row_count')
            ->groupBy('company_id', 'sku')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('company_id')
            ->orderBy('sku')
            ->get();

        $this->emit(sprintf(
            '%s product company/SKU collision groups found: %d',
            self::TAG,
            $collisions->count(),
        ));

        if ($collisions->isEmpty()) {
            return;
        }

        $details = [];
        foreach ($collisions as $collision) {
            /** @var object{company_id: string, sku: string, row_count: int|string} $collision */
            $details[] = sprintf(
                'company=%s sku=%s (%d row(s))',
                $collision->company_id,
                $collision->sku,
                (int) $collision->row_count,
            );
        }

        Log::error('product_company_sku_unique.collision_census', [
            'collision_groups' => $collisions->count(),
            'collisions' => $details,
        ]);

        throw new RuntimeException(
            'Cannot enforce unique(company_id, sku) on products; resolve '
            .$collisions->count().' collision group(s): '.implode('; ', $details)
        );
    }

    private function dropTenantWideUniques(string $driver): void
    {
        foreach (Schema::getIndexes('products') as $index) {
            if (! $index['unique'] || $index['primary'] || $index['columns'] !== self::TENANT_COLUMNS) {
                continue;
            }

            $name = $index['name'];

            if ($driver === 'pgsql') {
                $this->dropPostgresUnique($name);

                continue;
            }

            Schema::table('products', function (Blueprint $table) use ($name): void {
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
                  AND t.relname = 'products'
                  AND c.conname = ?
                  AND c.contype = 'u'
                SQL,
            [$name],
        );

        $quotedName = '"'.str_replace('"', '""', $name).'"';
        if ($constraint !== null) {
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS '.$quotedName);

            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.$quotedName);
    }

    private function emit(string $message): void
    {
        MigrationOutput::info($message);
    }
};
