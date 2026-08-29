<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Domain\ProductIndexNames;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\ReversibleTenantMigration;

final class ProductSkuCompanyScopeMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const array COMPANY_COLUMNS = ['company_id', 'sku'];

    private const string MIGRATION_FILE = '2026_08_30_100000_enforce_company_scoped_product_skus.php';

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

    public function test_migration_replaces_tenant_unique_and_reapplies_idempotently(): void
    {
        $tenant = Tenant::factory()->create();
        $companyA = Company::factory()->for($tenant)->create();
        $companyB = Company::factory()->for($tenant)->create();

        $this->dropUniqueForColumns(self::COMPANY_COLUMNS);
        $this->dropUniqueForColumns(self::TENANT_COLUMNS);
        Schema::table('products', function (Blueprint $table): void {
            $table->unique(self::TENANT_COLUMNS, ProductIndexNames::TENANT_SKU_UNIQUE);
        });

        Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyA->id,
            'sku' => 'COMPANY-SKU',
        ]);

        $this->migration->up();
        $this->migration->up();

        $this->assertFalse(Schema::hasIndex('products', self::TENANT_COLUMNS, 'unique'));
        $this->assertTrue(Schema::hasIndex('products', self::COMPANY_COLUMNS, 'unique'));

        Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyB->id,
            'sku' => 'COMPANY-SKU',
        ]);

        $this->expectException(QueryException::class);
        Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyA->id,
            'sku' => 'COMPANY-SKU',
        ]);
    }

    public function test_migration_refuses_lifetime_collisions_before_dropping_the_old_unique(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $company = Company::factory()->for($tenantA)->create();

        $this->dropUniqueForColumns(self::COMPANY_COLUMNS);
        $this->dropUniqueForColumns(self::TENANT_COLUMNS);
        Schema::table('products', function (Blueprint $table): void {
            $table->unique(self::TENANT_COLUMNS, ProductIndexNames::TENANT_SKU_UNIQUE);
        });

        $deletedHolder = Product::factory()->create([
            'tenant_id' => $tenantA->id,
            'company_id' => $company->id,
            'sku' => 'LIFETIME-COLLISION',
        ]);
        $deletedHolder->delete();

        Product::factory()->create([
            'tenant_id' => $tenantB->id,
            'company_id' => $company->id,
            'sku' => 'LIFETIME-COLLISION',
        ]);

        try {
            $this->migration->up();
            $this->fail('The migration must refuse company/SKU collisions before dropping the old unique.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('1 collision group(s)', $exception->getMessage());
            $this->assertStringContainsString('company='.$company->id, $exception->getMessage());
            $this->assertStringContainsString('sku=LIFETIME-COLLISION', $exception->getMessage());
            $this->assertStringContainsString('2 row(s)', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasIndex('products', self::TENANT_COLUMNS, 'unique'));
        $this->assertFalse(Schema::hasIndex('products', self::COMPANY_COLUMNS, 'unique'));
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropUniqueForColumns(array $columns): void
    {
        foreach (Schema::getIndexes('products') as $index) {
            if (! $index['unique'] || $index['primary'] || $index['columns'] !== $columns) {
                continue;
            }

            Schema::table('products', function (Blueprint $table) use ($index): void {
                $table->dropUnique($index['name']);
            });
        }
    }
}
