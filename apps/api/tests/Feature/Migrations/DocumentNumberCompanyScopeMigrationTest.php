<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentIndexNames;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\ReversibleTenantMigration;

final class DocumentNumberCompanyScopeMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const array COMPANY_COLUMNS = ['company_id', 'type', 'document_number'];

    private const string LOOKUP_INDEX = 'documents_tenant_id_type_document_number_index';

    private const string MIGRATION_FILE = '2026_08_30_100500_enforce_company_scoped_document_numbers.php';

    /** @var list<string> */
    private const array TENANT_COLUMNS = ['tenant_id', 'type', 'document_number'];

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

    public function test_migration_replaces_the_tenant_unique_and_reapplies_idempotently(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $this->restoreTenantUnique();
        $this->createInvoice($tenant, $company, $partner, 'INV-2026-0001');

        $this->migration->up();
        $this->migration->up();

        $this->assertFalse($this->hasNamedIndex(DocumentIndexNames::TENANT_TYPE_NUMBER_UNIQUE));
        $this->assertTrue($this->hasNamedUnique(DocumentIndexNames::COMPANY_TYPE_NUMBER_UNIQUE));
        $this->assertTrue($this->hasNamedNonUnique(self::LOOKUP_INDEX));
    }

    public function test_migration_refuses_lifetime_cross_company_collisions_before_dropping_the_old_unique(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $companyA = Company::factory()->for($tenantA)->create();
        $companyB = Company::factory()->for($tenantB)->create();
        $partnerA = Partner::factory()->create([
            'tenant_id' => $tenantA->id,
            'company_id' => $companyA->id,
        ]);
        $partnerB = Partner::factory()->create([
            'tenant_id' => $tenantB->id,
            'company_id' => $companyB->id,
        ]);

        $this->restoreTenantUnique();

        $deletedHolder = $this->createInvoice($tenantA, $companyA, $partnerA, 'INV-2026-LIFETIME');
        $deletedHolder->delete();
        $this->createInvoice($tenantB, $companyB, $partnerB, 'INV-2026-LIFETIME');

        try {
            $this->migration->up();
            $this->fail('The migration must refuse lifetime cross-company number collisions.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('1 collision group(s)', $exception->getMessage());
            $this->assertStringContainsString(DocumentType::Invoice->value, $exception->getMessage());
            $this->assertStringContainsString('INV-2026-LIFETIME', $exception->getMessage());
            $this->assertStringContainsString((string) $companyA->id, $exception->getMessage());
            $this->assertStringContainsString((string) $companyB->id, $exception->getMessage());
        }

        $this->assertTrue($this->hasNamedUnique(DocumentIndexNames::TENANT_TYPE_NUMBER_UNIQUE));
        $this->assertFalse($this->hasNamedIndex(DocumentIndexNames::COMPANY_TYPE_NUMBER_UNIQUE));
    }

    public function test_company_scoped_numbers_allow_sibling_companies_and_reject_same_company_duplicates(): void
    {
        $tenant = Tenant::factory()->create();
        $companyA = Company::factory()->for($tenant)->create();
        $companyB = Company::factory()->for($tenant)->create();
        $partnerA = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyA->id,
        ]);
        $partnerB = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyB->id,
        ]);

        $this->restoreTenantUnique();
        $this->migration->up();

        $this->createInvoice($tenant, $companyA, $partnerA, 'INV-2026-0001');
        $this->createInvoice($tenant, $companyB, $partnerB, 'INV-2026-0001');

        $this->migration->up();

        try {
            $this->createInvoice($tenant, $companyA, $partnerA, 'INV-2026-0001');
            $this->fail('The company-scoped unique must reject a duplicate number in one company.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->migration->down();
            $this->fail('Rollback must refuse cross-company duplicates that violate tenant uniqueness.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('1 collision group(s)', $exception->getMessage());
        }

        $this->assertTrue($this->hasNamedUnique(DocumentIndexNames::COMPANY_TYPE_NUMBER_UNIQUE));
        $this->assertFalse($this->hasNamedIndex(DocumentIndexNames::TENANT_TYPE_NUMBER_UNIQUE));
    }

    private function createInvoice(
        Tenant $tenant,
        Company $company,
        Partner $partner,
        string $number,
    ): Document {
        return Document::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'document_number' => $number,
        ]);
    }

    private function restoreTenantUnique(): void
    {
        $this->dropUniqueForColumns(self::COMPANY_COLUMNS);
        $this->dropUniqueForColumns(self::TENANT_COLUMNS);

        Schema::table('documents', function (Blueprint $table): void {
            $table->unique(self::TENANT_COLUMNS, DocumentIndexNames::TENANT_TYPE_NUMBER_UNIQUE);
        });
    }

    /** @param list<string> $columns */
    private function dropUniqueForColumns(array $columns): void
    {
        foreach (Schema::getIndexes('documents') as $index) {
            if (! $index['unique'] || $index['primary'] || $index['columns'] !== $columns) {
                continue;
            }

            Schema::table('documents', function (Blueprint $table) use ($index): void {
                $table->dropUnique($index['name']);
            });
        }
    }

    private function hasNamedIndex(string $name): bool
    {
        foreach (Schema::getIndexes('documents') as $index) {
            if ($index['name'] === $name) {
                return true;
            }
        }

        return false;
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

    private function hasNamedNonUnique(string $name): bool
    {
        foreach (Schema::getIndexes('documents') as $index) {
            if ($index['name'] === $name && ! $index['unique']) {
                return true;
            }
        }

        return false;
    }
}
