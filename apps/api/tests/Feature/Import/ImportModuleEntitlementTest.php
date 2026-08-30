<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Enums\Vertical;
use App\Models\SuperAdmin;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Application\Services\ModuleEntitlementCheck;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class ImportModuleEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_MESSAGE = "Module 'CompositeItems' is not enabled for this business type";

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private ImportJob $compositeJob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'vertical' => Vertical::Retail,
            'enabled_extras' => [],
        ]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('admin');
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        $this->compositeJob = $this->createJob(ImportType::CompositeItems);

        Storage::fake('local');
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->actingAs($this->user, 'sanctum');
        $this->withHeader('X-Company-Id', $this->company->id);
    }

    public function test_disabled_composite_items_module_refuses_all_twelve_existing_surfaces(): void
    {
        foreach ($this->allCompositeSurfaces() as $response) {
            $response->assertForbidden()
                ->assertJsonPath('message', self::FORBIDDEN_MESSAGE);
        }
    }

    public function test_enabled_composite_items_module_restores_each_surfaces_normal_status(): void
    {
        $this->setCompositeItemsEnabled(true);

        $expected = [
            'store' => 201,
            'template' => 200,
            'show' => 200,
            'preview' => 200,
            'errors' => 200,
            'update options' => 409,
            'error summary' => 200,
            'execute' => 422,
            'failed rows' => 404,
            'source file' => 404,
            'result workbook' => 200,
            'discard' => 204,
        ];

        foreach ($this->allCompositeSurfaces() as $surface => $response) {
            $response->assertStatus($expected[$surface]);
        }
    }

    public function test_products_are_unaffected_with_module_disabled_or_enabled(): void
    {
        $productJob = $this->createJob(ImportType::Products);

        foreach ([false, true] as $enabled) {
            $this->setCompositeItemsEnabled($enabled);
            $this->getJson('/api/v1/imports/'.$productJob->id)->assertOk();
            $this->getJson('/api/v1/migration-wizard/template/products')->assertOk();
        }
    }

    public function test_wizard_order_and_status_hide_composite_items_without_entitlement(): void
    {
        $this->getJson('/api/v1/migration-wizard/order')
            ->assertOk()
            ->assertJsonMissing(['type' => ImportType::CompositeItems->value]);
        $this->getJson('/api/v1/migration-wizard/status')
            ->assertOk()
            ->assertJsonMissingPath('data.composite_items');

        $this->setCompositeItemsEnabled(true);

        $this->getJson('/api/v1/migration-wizard/order')
            ->assertOk()
            ->assertJsonFragment(['type' => ImportType::CompositeItems->value]);
        $this->getJson('/api/v1/migration-wizard/status')
            ->assertOk()
            ->assertJsonPath('data.composite_items.count', 0)
            ->assertJsonPath('data.composite_items.has_data', false);
    }

    public function test_entitlement_check_reports_a_missing_authenticated_user(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User must be authenticated to check module access');

        app(ModuleEntitlementCheck::class)->ensure(ImportType::CompositeItems, null);
    }

    public function test_entitlement_check_reports_a_non_tenant_principal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Module access requires a tenant user, not a super admin');

        app(ModuleEntitlementCheck::class)->ensure(ImportType::CompositeItems, new SuperAdmin);
    }

    /** @return array<string, TestResponse<Response>> */
    private function allCompositeSurfaces(): array
    {
        $base = '/api/v1/imports/'.$this->compositeJob->id;

        return [
            'store' => $this->postJson('/api/v1/imports', [
                'file' => UploadedFile::fake()->createWithContent(
                    'composite-items.csv',
                    "code,name,base_price\nKIT-1,Starter kit,10.000\n",
                ),
                'type' => ImportType::CompositeItems->value,
            ]),
            'template' => $this->getJson('/api/v1/migration-wizard/template/composite_items'),
            'show' => $this->getJson($base),
            'preview' => $this->getJson($base.'/preview'),
            'errors' => $this->getJson($base.'/errors'),
            'update options' => $this->patchJson($base.'/options', ['options' => []]),
            'error summary' => $this->getJson($base.'/error-summary'),
            'execute' => $this->postJson($base.'/execute'),
            'failed rows' => $this->getJson($base.'/failed-rows.csv'),
            'source file' => $this->getJson($base.'/source-file'),
            'result workbook' => $this->getJson($base.'/result-workbook'),
            'discard' => $this->deleteJson($base),
        ];
    }

    private function createJob(ImportType $type): ImportJob
    {
        return ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'type' => $type,
            'status' => ImportStatus::Completed,
            'original_filename' => $type->value.'.csv',
            'file_path' => 'imports/'.$type->value.'.csv',
        ]);
    }

    private function setCompositeItemsEnabled(bool $enabled): void
    {
        $this->tenant->update([
            'enabled_extras' => $enabled ? ['CompositeItems'] : [],
        ]);
        app(CompanyConfigService::class)->invalidateForTenant($this->tenant->id);
        $this->tenant->refresh();
        $this->user->unsetRelation('tenant');
    }
}
