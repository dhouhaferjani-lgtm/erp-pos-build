<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Partner\Application\Services\PartnerService;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PartnerVatLifetimeScopeTest extends TestCase
{
    use RefreshDatabase;

    private const VAT = 'FR12345678901';

    private Tenant $tenant;

    private Company $company;

    private Company $siblingCompany;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Partner VAT Lifetime Tenant',
            'slug' => 'partner-vat-lifetime-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = $this->createCompany('Primary Company', 'PRIMARY-TAX');
        $this->siblingCompany = $this->createCompany('Sibling Company', 'SIBLING-TAX');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Partner VAT Tester',
            'email' => 'partner-vat-lifetime@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        foreach ([$this->company, $this->siblingCompany] as $company) {
            UserCompanyMembership::create([
                'user_id' => $this->user->id,
                'company_id' => $company->id,
                'role' => 'admin',
            ]);
        }

        app(CompanyContext::class)->setCompanyId($this->company->id);
        Storage::fake('local');
    }

    public function test_create_returns_coded_422_when_vat_is_held_by_a_deleted_partner(): void
    {
        $this->createDeletedHolder();

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/partners', $this->partnerPayload('Replacement'));

        $response->assertUnprocessable();
        $this->assertCodedDeletedHolderMessage($response->json('error.errors.vat_number'));
    }

    public function test_update_returns_coded_422_when_vat_is_held_by_a_deleted_partner(): void
    {
        $target = $this->createPartner($this->company, 'Update Target', null);
        $this->createDeletedHolder();

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/partners/{$target->id}", [
                'country_code' => 'FR',
                'vat_number' => self::VAT,
            ]);

        $response->assertUnprocessable();
        $this->assertCodedDeletedHolderMessage($response->json('error.errors.vat_number'));
    }

    public function test_same_vat_in_a_sibling_company_is_allowed(): void
    {
        $this->createPartner($this->siblingCompany, 'Sibling Holder', self::VAT);

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/partners', $this->partnerPayload('Primary Holder'))
            ->assertCreated();

        $this->assertSame(2, Partner::withTrashed()->where('vat_number', self::VAT)->count());
    }

    public function test_parties_import_records_a_coded_truthful_row_error_for_a_deleted_vat_holder(): void
    {
        $this->createDeletedHolder();

        $upload = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/imports', [
                'file' => UploadedFile::fake()->createWithContent(
                    'parties.csv',
                    "name,type,code,tax_id\nImported Replacement,customer,IMPORT-CODE,".self::VAT,
                ),
                'type' => 'parties',
            ]);
        $upload->assertCreated();

        $jobId = $upload->json('data.id');
        $this->assertIsString($jobId);

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk();

        $row = ImportJob::query()->whereKey($jobId)->firstOrFail()->rows()->firstOrFail();
        $this->assertFalse($row->is_imported);
        $this->assertIsString($row->import_error);
        $this->assertStringStartsWith('vat_held_by_deleted_partner:', $row->import_error);
        $this->assertStringContainsString('purge the deleted record or choose a different VAT', $row->import_error);
        $this->assertStringNotContainsString('restore', $row->import_error);
    }

    public function test_vat_resolution_refuses_a_deleted_holder_with_the_coded_message(): void
    {
        $this->createDeletedHolder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('vat_held_by_deleted_partner:');

        $this->app->make(PartnerService::class)->findByVatOrName(
            $this->tenant->id,
            $this->company->id,
            self::VAT,
            'Unused Name',
        );
    }

    private function createCompany(string $name, string $taxId): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'legal_name' => "{$name} LLC",
            'tax_id' => $taxId,
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function createDeletedHolder(): Partner
    {
        $partner = $this->createPartner($this->company, 'Deleted Holder', self::VAT);
        $partner->delete();

        return $partner;
    }

    private function createPartner(Company $company, string $name, ?string $vatNumber): Partner
    {
        return Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => $name,
            'type' => PartnerType::Customer,
            'country_code' => 'FR',
            'vat_number' => $vatNumber,
        ]);
    }

    /** @return array{name: string, type: string, country_code: string, vat_number: string} */
    private function partnerPayload(string $name): array
    {
        return [
            'name' => $name,
            'type' => PartnerType::Customer->value,
            'country_code' => 'FR',
            'vat_number' => self::VAT,
        ];
    }

    private function assertCodedDeletedHolderMessage(mixed $messages): void
    {
        $this->assertIsArray($messages);
        $encoded = json_encode($messages);
        $this->assertIsString($encoded);
        $this->assertStringContainsString('vat_held_by_deleted_partner:', $encoded);
        $this->assertStringContainsString('purge the deleted record or choose a different VAT', $encoded);
        $this->assertStringNotContainsString('restore', $encoded);
    }
}
