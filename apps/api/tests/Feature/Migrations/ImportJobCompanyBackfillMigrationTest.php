<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class ImportJobCompanyBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_FILE = '2026_08_30_100200_add_company_to_import_jobs.php';

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $path = database_path('migrations/tenant/'.self::MIGRATION_FILE);
        $this->assertFileExists($path, 'Tenant migration file missing: '.self::MIGRATION_FILE);

        /** @var Migration $migration */
        $migration = require $path;
        $this->migration = $migration;

        foreach (['import_jobs_company_id_created_at_index', 'import_jobs_company_id_index', 'import_jobs_source_hash_index'] as $index) {
            if (Schema::hasIndex('import_jobs', $index)) {
                Schema::table('import_jobs', function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }
        }

        Schema::table('import_jobs', function (Blueprint $table): void {
            if (Schema::hasColumn('import_jobs', 'company_id')) {
                $table->dropColumn('company_id');
            }
            if (Schema::hasColumn('import_jobs', 'source_hash')) {
                $table->dropColumn('source_hash');
            }
        });
    }

    public function test_agreeing_product_evidence_pins_the_job_and_reruns_idempotently(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $productA = Product::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id]);
        $productB = Product::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id]);
        $jobId = $this->insertJob((string) $tenant->id, 'products');
        $this->insertRow($jobId, 1, (string) $productA->id);
        $this->insertRow($jobId, 2, (string) $productB->id);

        ob_start();
        $this->runMigration();
        $this->runMigration();
        $output = (string) ob_get_clean();

        $this->assertSame((string) $company->id, DB::table('import_jobs')->where('id', $jobId)->value('company_id'));
        $this->assertSame(2, substr_count($output, 'import-job-company-attributed'));
        $this->assertSame(2, substr_count($output, 'import-job-company-ambiguous'));
        $this->assertSame(2, substr_count($output, 'import-job-company-none'));
        $this->assertTrue(Schema::hasIndex('import_jobs', 'import_jobs_company_id_index'));
        $this->assertTrue(Schema::hasIndex('import_jobs', 'import_jobs_company_id_created_at_index'));
        $this->assertTrue(Schema::hasIndex('import_jobs', 'import_jobs_source_hash_index'));
    }

    public function test_mixed_product_evidence_stays_unattributed_and_logs_each_company_count(): void
    {
        $tenant = Tenant::factory()->create();
        $companyA = Company::factory()->create(['tenant_id' => $tenant->id]);
        $companyB = Company::factory()->create(['tenant_id' => $tenant->id]);
        $productA = Product::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $companyA->id]);
        $productB = Product::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $companyB->id]);
        $jobId = $this->insertJob((string) $tenant->id, 'products');
        $this->insertRow($jobId, 1, (string) $productA->id);
        $this->insertRow($jobId, 2, (string) $productB->id);
        $logSpy = Log::spy();

        $this->runMigration();

        $this->assertNull(DB::table('import_jobs')->where('id', $jobId)->value('company_id'));
        $logSpy->shouldHaveReceived('warning', [
            Mockery::on(static fn (string $message): bool => $message === 'import-job-company-ambiguous'),
            Mockery::on(function (array $context) use ($jobId, $companyA, $companyB): bool {
                return $context['jobs'] === 1
                    && $context['per_job'][$jobId][(string) $companyA->id] === 1
                    && $context['per_job'][$jobId][(string) $companyB->id] === 1;
            }),
        ]);
    }

    public function test_single_company_without_entity_evidence_stays_unattributed(): void
    {
        $tenant = Tenant::factory()->create();
        Company::factory()->create(['tenant_id' => $tenant->id]);
        $jobId = $this->insertJob((string) $tenant->id, 'products');
        $this->insertRow($jobId, 1, null);

        $this->runMigration();

        $this->assertNull(DB::table('import_jobs')->where('id', $jobId)->value('company_id'));
    }

    public function test_columns_and_indexes_have_the_forward_only_shape(): void
    {
        $this->runMigration();

        $columns = collect(Schema::getColumns('import_jobs'))->keyBy('name');

        $this->assertTrue($columns->has('company_id'));
        $this->assertTrue($columns->has('source_hash'));
        $this->assertTrue($columns->get('company_id')['nullable']);
        $this->assertTrue($columns->get('source_hash')['nullable']);
        $this->assertTrue(Schema::hasIndex('import_jobs', 'import_jobs_company_id_index'));
        $this->assertTrue(Schema::hasIndex('import_jobs', 'import_jobs_company_id_created_at_index'));
        $this->assertTrue(Schema::hasIndex('import_jobs', 'import_jobs_source_hash_index'));
    }

    private function insertJob(string $tenantId, string $type): string
    {
        $id = Str::uuid()->toString();
        $now = now();

        DB::table('import_jobs')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'user_id' => Str::uuid()->toString(),
            'type' => $type,
            'status' => 'completed',
            'original_filename' => 'fixture.csv',
            'file_path' => 'imports/fixture.csv',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function runMigration(): void
    {
        if (! method_exists($this->migration, 'up')) {
            $this->fail('Tenant migration must expose up().');
        }

        $this->migration->up();
    }

    private function insertRow(string $jobId, int $rowNumber, ?string $entityId): void
    {
        DB::table('import_rows')->insert([
            'id' => Str::uuid()->toString(),
            'import_job_id' => $jobId,
            'row_number' => $rowNumber,
            'data' => '{}',
            'is_valid' => true,
            'is_imported' => $entityId !== null,
            'imported_entity_id' => $entityId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
