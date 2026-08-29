<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Modules\Company\Domain\Company;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Partner\Domain\PartnerIndexNames;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\ReversibleTenantMigration;

final class PartnerVatCompanyScopeMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const array COMPANY_COLUMNS = ['company_id', 'vat_number'];

    private const string MIGRATION_FILE = '2026_08_30_100200_enforce_company_scoped_partner_vat_numbers.php';

    /** @var list<string> */
    private const array TENANT_COLUMNS = ['tenant_id', 'vat_number'];

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

    public function test_migration_repairs_scope_allows_sibling_vat_and_reapplies_idempotently(): void
    {
        $tenant = Tenant::factory()->create();
        $companyA = Company::factory()->for($tenant)->create();
        $companyB = Company::factory()->for($tenant)->create();

        $this->dropUniqueForColumns(self::COMPANY_COLUMNS);
        $this->dropUniqueForColumns(self::TENANT_COLUMNS);
        Schema::table('partners', function (Blueprint $table): void {
            $table->unique(self::TENANT_COLUMNS, PartnerIndexNames::TENANT_VAT_UNIQUE);
        });

        Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyA->id,
            'vat_number' => 'TN-COMPANY-VAT',
        ]);
        Partner::factory()->count(2)->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyA->id,
            'vat_number' => null,
        ]);

        $this->migration->up();
        $this->migration->up();

        $this->assertFalse(Schema::hasIndex('partners', self::TENANT_COLUMNS, 'unique'));
        $this->assertTrue(Schema::hasIndex('partners', self::COMPANY_COLUMNS, 'unique'));

        Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyB->id,
            'vat_number' => 'TN-COMPANY-VAT',
        ]);

        $deletedHolder = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyA->id,
            'vat_number' => 'TN-LIFETIME-VAT',
        ]);
        $deletedHolder->delete();

        try {
            Partner::factory()->create([
                'tenant_id' => $tenant->id,
                'company_id' => $companyA->id,
                'vat_number' => 'TN-LIFETIME-VAT',
            ]);
            $this->fail('A soft-deleted partner must retain its company VAT key.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_migration_refuses_lifetime_collisions_before_dropping_the_old_unique(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $company = Company::factory()->for($tenantA)->create();

        $this->dropUniqueForColumns(self::COMPANY_COLUMNS);
        $this->dropUniqueForColumns(self::TENANT_COLUMNS);
        Schema::table('partners', function (Blueprint $table): void {
            $table->unique(self::TENANT_COLUMNS, PartnerIndexNames::TENANT_VAT_UNIQUE);
        });

        $deletedHolder = Partner::factory()->create([
            'tenant_id' => $tenantA->id,
            'company_id' => $company->id,
            'vat_number' => 'TN-DAMAGED-VAT',
        ]);
        $deletedHolder->delete();

        Partner::factory()->create([
            'tenant_id' => $tenantB->id,
            'company_id' => $company->id,
            'vat_number' => 'TN-DAMAGED-VAT',
        ]);

        try {
            $this->migration->up();
            $this->fail('The migration must refuse company/VAT collisions before dropping the old unique.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('1 collision group(s)', $exception->getMessage());
            $this->assertStringContainsString('company='.$company->id, $exception->getMessage());
            $this->assertStringContainsString('vat_number=TN-DAMAGED-VAT', $exception->getMessage());
            $this->assertStringContainsString('2 row(s)', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasIndex('partners', self::TENANT_COLUMNS, 'unique'));
        $this->assertFalse(Schema::hasIndex('partners', self::COMPANY_COLUMNS, 'unique'));
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropUniqueForColumns(array $columns): void
    {
        foreach (Schema::getIndexes('partners') as $index) {
            if (! $index['unique'] || $index['primary'] || $index['columns'] !== $columns) {
                continue;
            }

            Schema::table('partners', function (Blueprint $table) use ($index): void {
                $table->dropUnique($index['name']);
            });
        }
    }
}
