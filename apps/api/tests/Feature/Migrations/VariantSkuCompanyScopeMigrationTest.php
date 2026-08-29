<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Domain\VariantIndexNames;
use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\ReversibleTenantMigration;

final class VariantSkuCompanyScopeMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const array COMPANY_COLUMNS = ['company_id', 'sku'];

    private const string MIGRATION_FILE = '2026_08_30_100100_enforce_company_scoped_variant_skus.php';

    /** @var list<string> */
    private const array TENANT_COLUMNS = ['tenant_id', 'sku'];

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

    public function test_postgres_migration_repairs_scope_predicate_and_preserves_neighbours_idempotently(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only partial-index migration path.');
        }

        $tenant = Tenant::factory()->create();
        $companyA = Company::factory()->for($tenant)->create();
        $companyB = Company::factory()->for($tenant)->create();
        $productA = Product::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $companyA->id]);
        $productB = Product::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $companyB->id]);

        $this->dropSkuIndexes();
        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON product_variants (tenant_id, sku) WHERE deleted_at IS NULL',
            VariantIndexNames::TENANT_SKU_UNIQUE,
        ));

        ProductVariant::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyA->id,
            'product_id' => $productA->id,
            'sku' => 'COMPANY-VARIANT-SKU',
        ]);

        $barcodeBefore = $this->postgresIndexDefinition(VariantIndexNames::TENANT_BARCODE_UNIQUE);
        $defaultBefore = $this->postgresIndexDefinition('product_variants_default_unique');
        $priceBefore = $this->postgresConstraintDefinition('product_variants_price_nonneg');

        $this->migration->up();
        $companyDefinition = $this->postgresIndexDefinition(VariantIndexNames::COMPANY_SKU_UNIQUE);
        $this->migration->up();

        $this->assertFalse(Schema::hasIndex('product_variants', self::TENANT_COLUMNS, 'unique'));
        $this->assertTrue(Schema::hasIndex('product_variants', self::COMPANY_COLUMNS, 'unique'));
        $this->assertStringContainsString(
            'where (deleted_at is null)',
            strtolower($companyDefinition),
        );
        $this->assertSame($companyDefinition, $this->postgresIndexDefinition(VariantIndexNames::COMPANY_SKU_UNIQUE));
        $this->assertSame($barcodeBefore, $this->postgresIndexDefinition(VariantIndexNames::TENANT_BARCODE_UNIQUE));
        $this->assertSame($defaultBefore, $this->postgresIndexDefinition('product_variants_default_unique'));
        $this->assertSame($priceBefore, $this->postgresConstraintDefinition('product_variants_price_nonneg'));

        ProductVariant::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyB->id,
            'product_id' => $productB->id,
            'sku' => 'COMPANY-VARIANT-SKU',
        ]);

        $this->expectException(QueryException::class);
        ProductVariant::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyA->id,
            'product_id' => $productA->id,
            'sku' => 'COMPANY-VARIANT-SKU',
            'variant_code' => 'SECOND-COLLISION',
        ]);
    }

    public function test_postgres_migration_refuses_live_collisions_before_dropping_the_old_index(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only partial-index migration path.');
        }

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $company = Company::factory()->for($tenantA)->create();
        $productA = Product::factory()->create(['tenant_id' => $tenantA->id, 'company_id' => $company->id]);
        $productB = Product::factory()->create(['tenant_id' => $tenantB->id, 'company_id' => $company->id]);

        $this->dropSkuIndexes();
        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON product_variants (tenant_id, sku) WHERE deleted_at IS NULL',
            VariantIndexNames::TENANT_SKU_UNIQUE,
        ));

        ProductVariant::factory()->create([
            'tenant_id' => $tenantA->id,
            'company_id' => $company->id,
            'product_id' => $productA->id,
            'sku' => 'LIVE-COLLISION',
            'variant_code' => 'FIRST',
        ]);
        ProductVariant::factory()->create([
            'tenant_id' => $tenantB->id,
            'company_id' => $company->id,
            'product_id' => $productB->id,
            'sku' => 'LIVE-COLLISION',
            'variant_code' => 'SECOND',
        ]);

        try {
            $this->migration->up();
            $this->fail('The migration must refuse live company/SKU collisions before dropping the old index.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('1 collision group(s)', $exception->getMessage());
            $this->assertStringContainsString('company='.$company->id, $exception->getMessage());
            $this->assertStringContainsString('sku=LIVE-COLLISION', $exception->getMessage());
            $this->assertStringContainsString('2 row(s)', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasIndex('product_variants', self::TENANT_COLUMNS, 'unique'));
        $this->assertFalse(Schema::hasIndex('product_variants', self::COMPANY_COLUMNS, 'unique'));
    }

    public function test_sqlite_up_is_a_clean_noop(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-only no-op migration path.');
        }

        $before = Schema::getIndexes('product_variants');

        $this->migration->up();
        $this->migration->up();

        $this->assertSame($before, Schema::getIndexes('product_variants'));
        $this->assertFalse(Schema::hasIndex('product_variants', self::COMPANY_COLUMNS, 'unique'));
    }

    private function dropSkuIndexes(): void
    {
        foreach (Schema::getIndexes('product_variants') as $index) {
            if (! $index['unique'] || $index['primary']) {
                continue;
            }

            if ($index['columns'] !== self::TENANT_COLUMNS && $index['columns'] !== self::COMPANY_COLUMNS) {
                continue;
            }

            $quotedName = '"'.str_replace('"', '""', $index['name']).'"';
            DB::statement('DROP INDEX IF EXISTS '.$quotedName);
        }
    }

    private function postgresIndexDefinition(string $name): string
    {
        $definition = DB::table('pg_indexes')
            ->where('schemaname', DB::raw('current_schema()'))
            ->where('tablename', 'product_variants')
            ->where('indexname', $name)
            ->value('indexdef');

        $this->assertIsString($definition, 'Missing PostgreSQL index: '.$name);

        return $definition;
    }

    private function postgresConstraintDefinition(string $name): string
    {
        $row = DB::selectOne(
            <<<'SQL'
                SELECT pg_get_constraintdef(c.oid) AS definition
                FROM pg_constraint c
                JOIN pg_class t ON t.oid = c.conrelid
                JOIN pg_namespace n ON n.oid = t.relnamespace
                WHERE n.nspname = current_schema()
                  AND t.relname = 'product_variants'
                  AND c.conname = ?
                SQL,
            [$name],
        );

        $this->assertNotNull($row, 'Missing PostgreSQL constraint: '.$name);
        /** @var object{definition: string} $row */

        return $row->definition;
    }
}
