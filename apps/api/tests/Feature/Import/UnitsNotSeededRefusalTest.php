<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Application\Jobs\ProcessImportJob;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Application\Services\UnitsProvisioningService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class UnitsNotSeededRefusalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Units Refusal Tenant',
            'slug' => 'units-refusal-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Units Refusal Company',
            'legal_name' => 'Units Refusal Company LLC',
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
            'name' => 'Units Refusal User',
            'email' => 'units-refusal@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        Storage::fake('local');
    }

    public function test_products_upload_refuses_empty_visible_units_before_creating_a_job(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => UploadedFile::fake()->createWithContent('products.csv', "name\nBrake Pad"),
                'type' => 'products',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'units_not_seeded')
            ->assertJsonPath('error.details.company_id', $this->company->id);
        $this->assertSame(0, ImportJob::query()->count());
    }

    public function test_products_upload_is_created_after_units_are_seeded(): void
    {
        (new UomSeeder)->run();

        $response = $this->upload('products', "name\nBrake Pad");

        $response->assertCreated();
        $this->assertSame(1, ImportJob::query()->count());
    }

    public function test_type_without_a_unit_column_is_not_refused_when_units_are_empty(): void
    {
        $response = $this->upload('parties', "name,type\nAcme,customer");

        $response->assertCreated();
        $this->assertSame(1, ImportJob::query()->count());
    }

    public function test_worker_fails_if_visible_units_are_emptied_after_upload(): void
    {
        (new UomSeeder)->run();
        $response = $this->upload('products', "name,sku,type\nWorker Product,WORKER-1,part")
            ->assertCreated();
        $job = ImportJob::query()->findOrFail((string) $response->json('data.id'));

        DB::table('units')->delete();
        DB::table('unit_categories')->delete();
        app(CompanyContext::class)->clear();

        (new ProcessImportJob($job->id, $this->company->id, $this->tenant->id))->handle(
            app(ImportService::class),
            app(UnitsProvisioningService::class),
        );

        $job->refresh();
        $this->assertSame(ImportStatus::Failed, $job->status);
        $this->assertStringStartsWith('units_not_seeded:', (string) $job->error_message);
        $this->assertSame(0, $job->rows()->where('is_imported', true)->count());
        $this->assertSame(0, Product::query()->count());
    }

    /**
     * @return TestResponse<Response>
     */
    private function upload(string $type, string $contents): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => UploadedFile::fake()->createWithContent($type.'.csv', $contents),
                'type' => $type,
            ]);
    }
}
