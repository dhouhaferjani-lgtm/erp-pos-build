<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\VariantIndexNames;
use App\Shared\Database\MigrationOutput;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Normalize only the PostgreSQL partial variant-SKU unique to company scope.
 * Barcode, default-variant, and price constraints remain untouched.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array COMPANY_COLUMNS = ['company_id', 'sku'];

    /** @var list<string> */
    private const array TENANT_COLUMNS = ['tenant_id', 'sku'];

    private const string TAG = '[G-3A M2]';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $this->emit(self::TAG.' SKIPPED: SQLite has no variant partial indexes; clean no-op.');

            return;
        }

        if ($driver !== 'pgsql') {
            $this->emit(sprintf('%s SKIPPED: unsupported driver %s.', self::TAG, $driver));

            return;
        }

        if (! Schema::hasTable('product_variants')) {
            $this->emit(self::TAG.' SKIPPED: no product_variants table.');

            return;
        }

        foreach (['tenant_id', 'company_id', 'sku', 'deleted_at'] as $column) {
            if (! Schema::hasColumn('product_variants', $column)) {
                $this->emit(sprintf('%s SKIPPED: product_variants.%s is absent.', self::TAG, $column));

                return;
            }
        }

        $this->assertNoLiveCompanySkuCollisions();
        $this->dropTenantWideSkuIndexes();

        if (! Schema::hasIndex('product_variants', self::COMPANY_COLUMNS, 'unique')) {
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON product_variants (company_id, sku) WHERE deleted_at IS NULL',
                VariantIndexNames::COMPANY_SKU_UNIQUE,
            ));
        }

        $this->emit(self::TAG.' product_variants unique scope: (company_id, sku) WHERE deleted_at IS NULL.');
    }

    /**
     * Forward-only state normalization. A pre-existing company partial unique
     * is indistinguishable from one repaired here, and restoring tenant scope
     * would reject legitimate sibling-company variant SKUs written later.
     */
    public function down(): void
    {
        Log::info('variant_company_sku_unique: forward-only migration, down() is a no-op.');
    }

    private function assertNoLiveCompanySkuCollisions(): void
    {
        $collisions = DB::table('product_variants')
            ->select(['company_id', 'sku'])
            ->selectRaw('COUNT(*) AS row_count')
            ->whereNull('deleted_at')
            ->groupBy('company_id', 'sku')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('company_id')
            ->orderBy('sku')
            ->get();

        $this->emit(sprintf(
            '%s live variant company/SKU collision groups found: %d',
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

        Log::error('variant_company_sku_unique.collision_census', [
            'collision_groups' => $collisions->count(),
            'collisions' => $details,
        ]);

        throw new RuntimeException(
            'Cannot enforce unique(company_id, sku) on live product_variants; resolve '
            .$collisions->count().' collision group(s): '.implode('; ', $details)
        );
    }

    private function dropTenantWideSkuIndexes(): void
    {
        foreach (Schema::getIndexes('product_variants') as $index) {
            if (! $index['unique'] || $index['primary'] || $index['columns'] !== self::TENANT_COLUMNS) {
                continue;
            }

            $quotedName = '"'.str_replace('"', '""', $index['name']).'"';
            DB::statement('DROP INDEX IF EXISTS '.$quotedName);
        }
    }

    private function emit(string $message): void
    {
        MigrationOutput::info($message);
    }
};
