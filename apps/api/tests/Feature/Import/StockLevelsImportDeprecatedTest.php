<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\MigrationWizardService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Owner ruling D4 (document-per-action remediation, lane V6): the `stock_levels`
 * import type is DEPRECATED. It was the first import implemented and wrote an
 * absolute stock quantity through `InventoryService::upsertStockLevel` — a bare
 * `StockLevel::updateOrCreate` with no stock movement, no justifying document,
 * no WAC/GL posting and `reserved` silently zeroed. Opening stock now flows
 * through the Products import (`ProductOpeningStockPhase` →
 * `OpeningBalancePostingService`), which posts a real Opening movement.
 *
 * The enum CASE survives on purpose — `import_jobs.type` is a plain
 * `string(50)` column cast to `ImportType::class`, so deleting the case would
 * make `ImportType::from('stock_levels')` throw a `ValueError` inside Eloquent's
 * enum cast and 500 every historical read (the whole import-history LIST, not
 * just one row). This test pins BOTH halves of that contract: creation and
 * execution are refused with a clean validation error, and historical reads
 * still work.
 */
final class StockLevelsImportDeprecatedTest extends TestCase
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
            'slug' => 'test-tenant',
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
            'email' => 'user@example.com',
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

        Storage::fake('local');
    }

    public function test_creating_a_stock_levels_import_is_refused_with_a_validation_error(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'stock.csv',
            "product_sku,location_code,quantity\nTEST-001,WH-MAIN,100"
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'stock_levels',
            ]);

        // The app wraps validation failures in its own envelope
        // (bootstrap/app.php: VALIDATION_ERROR) rather than Laravel's default.
        $response->assertUnprocessable();
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $message = $response->json('error.errors.type.0');
        $this->assertIsString($message);
        $this->assertStringContainsString('no longer supported', $message);
        $this->assertStringContainsString('Products import', $message);

        // No job may be created, and no file may be stored.
        $this->assertDatabaseCount('import_jobs', 0);
    }

    public function test_refused_stock_levels_import_does_not_500(): void
    {
        $file = UploadedFile::fake()->createWithContent('stock.csv', "product_sku\nTEST-001");

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'stock_levels',
            ]);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_executing_a_legacy_stock_levels_job_is_refused(): void
    {
        // A job persisted BEFORE the deprecation. Written straight to the table
        // because the API can no longer create one.
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ImportType::StockLevels,
            'status' => ImportStatus::Validated,
            'original_filename' => 'stock.csv',
            'file_path' => 'imports/stock.csv',
            'total_rows' => 1,
            'successful_rows' => 1,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute");

        $response->assertUnprocessable();
        $this->assertStringContainsString(
            'no longer supported',
            (string) $response->json('error.message')
        );
    }

    public function test_historical_stock_levels_jobs_remain_readable(): void
    {
        // The whole reason the enum case survives: an existing row must not
        // blow up Eloquent's enum cast on read.
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ImportType::StockLevels,
            'status' => ImportStatus::Completed,
            'original_filename' => 'legacy-stock.csv',
            'file_path' => 'imports/legacy-stock.csv',
            'total_rows' => 3,
            'processed_rows' => 3,
            'successful_rows' => 3,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/imports')
            ->assertOk()
            ->assertJsonFragment(['type' => 'stock_levels']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.type', 'stock_levels');
    }

    public function test_stock_levels_is_flagged_deprecated_and_excluded_from_selectable_types(): void
    {
        $this->assertTrue(ImportType::StockLevels->isDeprecated());
        $this->assertFalse(ImportType::Products->isDeprecated());

        $this->assertNotContains(ImportType::StockLevels, ImportType::selectable());
        $this->assertContains(ImportType::Products, ImportType::selectable());
    }

    public function test_migration_wizard_no_longer_offers_stock_levels(): void
    {
        /** @var MigrationWizardService $wizard */
        $wizard = app(MigrationWizardService::class);

        $this->assertNotContains(ImportType::StockLevels, $wizard->getRecommendedImportOrder());

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/migration-wizard/order')
            ->assertOk()
            ->assertJsonMissing(['type' => 'stock_levels']);
    }

    public function test_stock_levels_template_download_is_refused(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/migration-wizard/template/stock_levels')
            ->assertUnprocessable();

        $this->actingAs($this->user, 'sanctum')
            ->get('/api/v1/migration-wizard/template/products')
            ->assertOk();
    }
}
