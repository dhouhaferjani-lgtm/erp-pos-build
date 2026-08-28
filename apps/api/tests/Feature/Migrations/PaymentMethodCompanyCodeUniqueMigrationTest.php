<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\ReversibleTenantMigration;

final class PaymentMethodCompanyCodeUniqueMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_UNIQUE_COLUMNS = ['company_id', 'code'];

    private const MIGRATION_FILE = '2026_08_28_100000_enforce_company_scoped_payment_method_codes.php';

    private const TENANT_UNIQUE = 'payment_methods_tenant_id_code_unique';

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
        $companyA = Company::factory()->create(['tenant_id' => $tenant->id]);
        $companyB = Company::factory()->create(['tenant_id' => $tenant->id]);

        $this->dropUniqueForColumns(self::COMPANY_UNIQUE_COLUMNS);
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'code'], self::TENANT_UNIQUE);
        });

        PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyA->id,
            'code' => 'CARD',
        ]);

        $this->migration->up();
        $this->migration->up();

        $this->assertFalse(Schema::hasIndex('payment_methods', ['tenant_id', 'code'], 'unique'));
        $this->assertTrue(Schema::hasIndex('payment_methods', self::COMPANY_UNIQUE_COLUMNS, 'unique'));

        PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyB->id,
            'code' => 'CARD',
        ]);

        try {
            PaymentMethod::factory()->create([
                'tenant_id' => $tenant->id,
                'company_id' => $companyA->id,
                'code' => 'CARD',
            ]);
            $this->fail('The company-scoped unique must reject a duplicate code inside one company.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_migration_refuses_to_add_the_company_unique_when_census_finds_collisions(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $this->dropUniqueForColumns(self::COMPANY_UNIQUE_COLUMNS);

        PaymentMethod::factory()->count(2)->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'COLLISION',
        ]);

        try {
            $this->migration->up();
            $this->fail('The migration must refuse an opaque unique-index failure when collisions exist.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString((string) $company->id, $exception->getMessage());
            $this->assertStringContainsString('COLLISION', $exception->getMessage());
            $this->assertStringContainsString('2 row(s)', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasIndex('payment_methods', self::COMPANY_UNIQUE_COLUMNS, 'unique'));
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropUniqueForColumns(array $columns): void
    {
        foreach (Schema::getIndexes('payment_methods') as $index) {
            if (! $index['unique'] || $index['primary'] || $index['columns'] !== $columns) {
                continue;
            }

            Schema::table('payment_methods', function (Blueprint $table) use ($index): void {
                $table->dropUnique($index['name']);
            });
        }
    }
}
