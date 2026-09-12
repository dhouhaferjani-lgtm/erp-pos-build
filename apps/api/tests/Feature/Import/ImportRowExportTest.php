<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportRowExportService;
use App\Modules\Import\Services\ImportService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Application\Services\UnitsProvisioningService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ImportRowExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-import-warnings',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'warnings@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(UnitsProvisioningService::class)->provisionForCompany($this->company);

        Storage::fake('local');
    }

    public function test_csv_exports_only_errors_and_warnings_in_source_layout_with_stable_bytes(): void
    {
        $job = $this->exportJob();
        $service = app(ImportRowExportService::class);
        $path = $service->generate($job, 'csv');
        $this->assertNotNull($path);
        $csv = Storage::disk('local')->get($path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\r\n", trim(substr($csv, 3))));
        $this->assertSame(['Produit', 'Référence', 'Prix', '_status', '_code', '_message'], $lines[0]);
        $this->assertCount(3, $lines);
        $this->assertSame(['Bad', '001', 'invalid', 'error', 'validation_failed', 'Price must be numeric'], $lines[1]);
        $this->assertSame(['Warn', '002', '12.500', 'warning', 'price_conflict', 'Check price'], $lines[2]);
        $this->assertSame($csv, Storage::disk('local')->get($service->generate($job, 'csv')));
    }

    public function test_xlsx_uses_same_selection_and_preserves_text_cells(): void
    {
        $path = app(ImportRowExportService::class)->generate($this->exportJob(), 'xlsx');
        $this->assertNotNull($path);
        $sheet = IOFactory::load(Storage::disk('local')->path($path))->getActiveSheet();
        $this->assertSame(3, $sheet->getHighestRow());
        $this->assertSame('001', $sheet->getCell('B2')->getValue());
        $this->assertSame('12.500', $sheet->getCell('C3')->getValue());
        $this->assertSame('warning', $sheet->getCell('D3')->getValue());
    }

    public function test_async_export_route_and_second_company_isolation(): void
    {
        $job = $this->exportJob();
        $job->update(['status' => ImportStatus::Completed, 'total_rows' => 100]);
        $this->actingAs($this->user, 'sanctum')->get('/api/v1/imports/'.$job->id.'/failed-rows.csv')->assertOk();
        $this->get('/api/v1/imports/'.$job->id.'/failed-rows.xlsx')->assertOk();
        $other = $this->company->replicate();
        $other->name = 'Second company';
        $other->tax_id = 'SECOND-TAX';
        $other->save();
        UserCompanyMembership::create(['user_id' => $this->user->id, 'company_id' => $other->id, 'role' => 'admin']);
        $this->withHeader('X-Company-Id', $other->id)->getJson('/api/v1/imports/'.$job->id.'/failed-rows.csv')->assertStatus(409);
    }

    public function test_duplicate_mapping_is_refused_on_upload_and_options(): void
    {
        $mapping = ['Name' => 'name', 'Other' => 'name'];
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'file' => UploadedFile::fake()->createWithContent('mapped.csv', "Name,Other\nOne,Two\n"),
            'type' => 'products', 'column_mapping' => json_encode($mapping),
        ])->assertStatus(422)->assertJsonPath('error.code', 'mapping_not_injective');
        $job = $this->exportJob();
        $this->patchJson('/api/v1/imports/'.$job->id.'/options', ['options' => ['duplicate_policy' => 'skip'], 'column_mapping' => $mapping])
            ->assertStatus(422)->assertJsonPath('error.code', 'mapping_not_injective');
    }

    public function test_corrected_export_round_trip_preserves_mapping_and_is_idempotent(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $mapping = ['Produit' => 'name', 'Référence' => 'sku', 'Prix' => 'sale_price'];
        $response = $this->postJson('/api/v1/imports', [
            'type' => 'products', 'column_mapping' => json_encode($mapping),
            'file' => UploadedFile::fake()->createWithContent('original.csv', "Produit,Référence,Prix\nGood,ROUND-GOOD,12.500\nBad,ROUND-BAD,invalid\n"),
        ])->assertCreated();
        $job = ImportJob::findOrFail($response->json('data.id'));
        $this->postJson('/api/v1/imports/'.$job->id.'/execute')->assertOk();
        $path = app(ImportRowExportService::class)->generate($job->refresh());
        $this->assertNotNull($path);
        $corrected = str_replace('invalid', '10.250', Storage::disk('local')->get($path));
        for ($run = 0; $run < 2; $run++) {
            $reimport = $this->postJson('/api/v1/imports', [
                'type' => 'products', 'reimport_of' => $job->id,
                'file' => UploadedFile::fake()->createWithContent('corrected.csv', $corrected),
            ])->assertCreated();
            $next = ImportJob::findOrFail($reimport->json('data.id'));
            $this->assertEquals($mapping, $next->column_mapping);
            $this->assertSame('10.250', $next->rows()->sole()->data['sale_price']);
            $this->postJson('/api/v1/imports/'.$next->id.'/execute')->assertOk();
        }
        $this->assertDatabaseCount('products', 2);
    }

    public function test_headers_are_unioned_from_all_rows_and_unmapped_columns_are_omitted(): void
    {
        $job = $this->exportJob();
        $job->rows()->where('row_number', 1)->update(['data' => ['name' => 'Bad', 'sku' => '001']]);
        $csv = Storage::disk('local')->get(app(ImportRowExportService::class)->generate($job));
        $this->assertStringContainsString('Prix', $csv);
        $this->assertStringContainsString('12.500', $csv);
        $this->assertStringNotContainsString('_provided', $csv);
    }

    public function test_reimport_refuses_another_company_and_missing_headers_return_a_notice(): void
    {
        $job = $this->exportJob();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'type' => 'products', 'reimport_of' => $job->id,
            'file' => UploadedFile::fake()->createWithContent('renamed.csv', "name,sku\nChanged,CHANGED\n"),
        ])->assertCreated()->assertJsonPath('reimport_notice', 'reimport_headers_changed');
        $other = $this->company->replicate();
        $other->name = 'Second company';
        $other->tax_id = 'SECOND-TAX';
        $other->save();
        UserCompanyMembership::create(['user_id' => $this->user->id, 'company_id' => $other->id, 'role' => 'admin']);
        $this->withHeader('X-Company-Id', $other->id)->postJson('/api/v1/imports', [
            'type' => 'products', 'reimport_of' => $job->id,
            'file' => UploadedFile::fake()->createWithContent('other.csv', "name\nOther\n"),
        ])->assertStatus(409)->assertJsonPath('error.code', 'IMPORT_COMPANY_MISMATCH');
    }

    public function test_automatic_mapping_preserves_original_header_spelling(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'type' => 'products',
            'file' => UploadedFile::fake()->createWithContent('source.csv', "Name,SKU,Sale_Price\nBad,CASE-1,invalid\n"),
        ])->assertCreated();
        $job = ImportJob::findOrFail($response->json('data.id'));
        $path = app(ImportRowExportService::class)->generate($job);
        $this->assertNotNull($path);
        $this->assertStringContainsString('Name,SKU,Sale_Price,_status,_code,_message', Storage::disk('local')->get($path));
    }

    public function test_historical_job_with_committed_rows_and_finalize_error_reads_as_partial(): void
    {
        $job = $this->exportJob();
        $job->update([
            'status' => ImportStatus::Failed, 'successful_rows' => 2, 'failed_rows' => 1,
            'error_code' => ImportErrorCode::InternalError, 'error_message' => 'Finalize failed',
        ]);
        // error_code is the operator-facing channel: the screens translate it and never
        // render error_message, which carries raw class names and SQLSTATE text.
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/imports/'.$job->id)
            ->assertOk()->assertJsonPath('data.status', 'partially_completed')
            ->assertJsonPath('data.error_code', 'internal_error')
            ->assertJsonPath('data.error_message', 'Finalize failed');
        $this->getJson('/api/v1/imports')->assertOk()->assertJsonPath('data.0.error_code', 'internal_error');
        $this->getJson('/api/v1/imports?status=partially_completed')->assertOk()->assertJsonPath('data.0.id', $job->id);
        $this->getJson('/api/v1/imports?status=failed')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unit_error_export_includes_accepted_codes(): void
    {
        $job = $this->exportJob();
        $job->rows()->where('row_number', 1)->firstOrFail()->update([
            'import_error_code' => ImportErrorCode::UnitUnknown,
            'import_error_detail' => ['accepted' => ['pc', 'kg']],
        ]);
        $path = app(ImportRowExportService::class)->generate($job);
        $this->assertStringContainsString('Accepted unit codes: pc, kg.', Storage::disk('local')->get($path));
    }

    public function test_correction_export_never_outlives_the_download(): void
    {
        // The artefact holds the operator's raw rows (partner names, tax ids,
        // balances). Spec 4.10 rules it ephemeral, so nothing is left to purge.
        $job = $this->exportJob();
        $job->update(['status' => ImportStatus::Completed, 'total_rows' => 100]);

        $this->actingAs($this->user, 'sanctum')->get('/api/v1/imports/'.$job->id.'/failed-rows.csv')->assertOk();
        $this->assertSame([], Storage::disk('local')->files('imports/rows'));

        $this->get('/api/v1/imports/'.$job->id.'/failed-rows.xlsx')->assertOk();
        $this->assertSame([], Storage::disk('local')->files('imports/rows'));
    }

    public function test_discarding_a_job_removes_its_correction_exports(): void
    {
        $job = $this->exportJob();
        $service = app(ImportRowExportService::class);
        $service->generate($job, 'csv');
        $service->generate($job, 'xlsx');
        $this->assertCount(2, Storage::disk('local')->files('imports/rows'));

        $this->actingAs($this->user, 'sanctum')->deleteJson('/api/v1/imports/'.$job->id)->assertNoContent();

        $this->assertSame([], Storage::disk('local')->files('imports/rows'));
    }

    private function exportJob(): ImportJob
    {
        $job = app(ImportService::class)->createJob(
            tenantId: $this->tenant->id, companyId: $this->company->id, userId: $this->user->id,
            type: ImportType::Products, filename: 'mapped.csv', filePath: 'imports/test.csv', totalRows: 3,
            columnMapping: ['Produit' => 'name', 'Référence' => 'sku', 'Prix' => 'sale_price'],
            options: ['source_headers' => ['Produit', 'Référence', 'Prix']],
        );
        $job->rows()->create(['row_number' => 1, 'data' => ['name' => 'Bad', 'sku' => '001', 'sale_price' => 'invalid'], 'is_valid' => false, 'outcome' => ImportRowOutcome::Failed,
            'errors' => ['sale_price' => ['Price must be numeric', 'Second message']],
            'warnings' => [['code' => 'price_conflict', 'detail' => 'Error must outrank warning']]]);
        $job->rows()->create(['row_number' => 2, 'data' => ['name' => 'Warn', 'sku' => '002', 'sale_price' => '12.500'], 'is_valid' => true, 'outcome' => ImportRowOutcome::Imported,
            'warnings' => [['code' => 'price_conflict', 'detail' => 'Check price'], ['code' => 'sku_generated', 'detail' => 'Second warning']]]);
        $job->rows()->create(['row_number' => 3, 'data' => ['name' => 'Good', 'sku' => '003'], 'is_valid' => true, 'outcome' => ImportRowOutcome::Imported]);

        return $job;
    }
}
