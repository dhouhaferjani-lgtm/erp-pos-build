<?php

declare(strict_types=1);

namespace Tests\Feature\Uom;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Tenant\Application\Services\TenantInitializationService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class UnitsProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Company $existingCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Units Provisioning Tenant',
            'slug' => 'units-provisioning-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Units Provisioning Owner',
            'email' => 'units-provisioning@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        $this->existingCompany = Company::factory()->for($this->tenant)->create();
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->existingCompany->id,
            'role' => MembershipRole::Owner,
        ]);
    }

    public function test_creating_a_company_provisions_the_canonical_visible_unit_set(): void
    {
        DB::table('units')->delete();
        DB::table('unit_categories')->delete();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'Company With Units',
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ])
            ->assertCreated();

        $company = Company::query()->findOrFail((string) $response->json('data.id'));
        $codes = DB::table('units')
            ->where('is_active', true)
            ->where(static function ($query) use ($company): void {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', $company->tenant_id);
            })
            ->pluck('code')
            ->map(static fn (mixed $code): string => strtolower((string) $code))
            ->all();

        $this->assertCount(19, $codes);
        foreach (['pc', 'g', 'kg', 'ml', 'l', 'mm', 'cm', 'm', 'min', 'hr'] as $expected) {
            $this->assertContains($expected, $codes, "The canonical set must include '{$expected}'.");
        }
    }

    public function test_creating_a_second_company_does_not_add_units_when_the_tenant_already_has_them(): void
    {
        (new UomSeeder)->run();
        $before = DB::table('units')->count();

        $this->createCompany('Second Company With Existing Units');

        $this->assertSame($before, DB::table('units')->count());
    }

    public function test_half_state_is_left_untouched_and_logged_instead_of_being_seeded(): void
    {
        (new UomSeeder)->run();
        DB::table('units')->delete();
        $categoryCount = DB::table('unit_categories')->count();
        $logSpy = Log::spy();

        $company = $this->createCompany('Company With Unit Half State');

        $this->assertSame(0, DB::table('units')->count());
        $this->assertSame($categoryCount, DB::table('unit_categories')->count());
        $logSpy->shouldHaveReceived('warning', [
            'units.empty_but_not_seedable',
            [
                'company_id' => $company->id,
                'units' => 0,
                'unit_categories' => $categoryCount,
            ],
        ]);
    }

    public function test_registration_initialization_yields_a_non_empty_visible_set_with_pc(): void
    {
        DB::table('units')->delete();
        DB::table('unit_categories')->delete();

        app(TenantInitializationService::class)->initializeForNewRegistration(
            $this->tenant,
            $this->existingCompany,
            $this->user,
        );

        $tenantId = $this->tenant->id;
        $this->assertGreaterThan(
            0,
            DB::table('units')
                ->where('is_active', true)
                ->where(static function ($query) use ($tenantId): void {
                    $query->whereNull('tenant_id')
                        ->orWhere('tenant_id', $tenantId);
                })
                ->count(),
        );
        $this->assertTrue(DB::table('units')->whereRaw('lower(code) = ?', ['pc'])->exists());
    }

    public function test_registration_initialization_delegates_half_state_to_the_single_policy(): void
    {
        (new UomSeeder)->run();
        DB::table('units')->delete();
        $categoryCount = DB::table('unit_categories')->count();
        $logSpy = Log::spy();

        app(TenantInitializationService::class)->initializeForNewRegistration(
            $this->tenant,
            $this->existingCompany,
            $this->user,
        );

        $logSpy->shouldHaveReceived('warning', [
            'units.empty_but_not_seedable',
            [
                'company_id' => $this->existingCompany->id,
                'units' => 0,
                'unit_categories' => $categoryCount,
            ],
        ]);
    }

    public function test_unit_seed_failure_rolls_back_only_units_and_company_creation_still_succeeds(): void
    {
        DB::table('units')->delete();
        DB::table('unit_categories')->delete();
        $failure = new RuntimeException('Injected failure after the second unit insert.');
        $this->failAfterSecondUnitInsert($failure);
        $logSpy = Log::spy();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'Company Surviving Unit Seed Failure',
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ])
            ->assertCreated();

        $company = Company::query()->findOrFail((string) $response->json('data.id'));
        $this->assertSame('Company Surviving Unit Seed Failure', $company->name);
        $this->assertSame(0, DB::table('units')->count());
        $this->assertSame(0, DB::table('unit_categories')->count());
        $logSpy->shouldHaveReceived('error', [
            'units.seed_failed',
            [
                'exception' => $failure,
                'tenant' => $this->tenant->id,
            ],
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id)
            ->postJson('/api/v1/imports', [
                'file' => UploadedFile::fake()->createWithContent('products.csv', "name\nBrake Pad"),
                'type' => 'products',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'units_not_seeded')
            ->assertJsonPath('error.details.company_id', $company->id);
        $this->assertSame(0, ImportJob::query()->count());
    }

    private function createCompany(string $name): Company
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => $name,
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ])
            ->assertCreated();

        return Company::query()->findOrFail((string) $response->json('data.id'));
    }

    private function failAfterSecondUnitInsert(RuntimeException $failure): void
    {
        $unitInsertCount = 0;

        DB::listen(static function (QueryExecuted $query) use (&$unitInsertCount, $failure): void {
            if (preg_match('/insert into\s+["`]?units["`]?/i', $query->sql) !== 1) {
                return;
            }

            $unitInsertCount++;
            if ($unitInsertCount === 2) {
                throw $failure;
            }
        });
    }
}
