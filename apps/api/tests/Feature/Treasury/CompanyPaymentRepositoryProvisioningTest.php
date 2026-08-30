<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\AccountingOpeningService;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\TenantInitializationService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\CompanyPaymentRepositoryProvisionerInterface;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\LegacyMockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Throwable;

final class CompanyPaymentRepositoryProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Company $existingCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'G3c Treasury Tenant',
            'slug' => 'g3c-treasury-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'G3c Treasury Owner',
            'email' => 'g3c-treasury@example.test',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        $this->existingCompany = Company::factory()->for($this->tenant)->create([
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->existingCompany->id,
            'role' => MembershipRole::Owner,
        ]);
    }

    public function test_second_company_receives_its_own_day_one_repositories_at_its_main_location(): void
    {
        $siblingLocation = $this->location($this->existingCompany, 'MAIN');
        $siblingCash = $this->cashAccount($this->existingCompany, '531');
        app(CompanyPaymentRepositoryProvisionerInterface::class)
            ->provisionForCompany($this->existingCompany->tenant_id, $this->existingCompany->id, $siblingLocation->id);
        $siblingCountBefore = PaymentRepository::query()
            ->where('company_id', $this->existingCompany->id)
            ->count();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'Second Treasury Company',
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ])
            ->assertCreated();

        $company = Company::query()->findOrFail((string) $response->json('data.id'));
        $main = Location::query()
            ->where('company_id', $company->id)
            ->where('code', 'MAIN')
            ->firstOrFail();
        $cashAccount = Account::findByPurpose($company->id, SystemAccountPurpose::Cash);
        self::assertInstanceOf(Account::class, $cashAccount);

        $repositories = PaymentRepository::query()
            ->where('company_id', $company->id)
            ->orderBy('code')
            ->get();

        self::assertSame(['CASH-01', 'SAFE-01'], $repositories->pluck('code')->all());
        foreach ($repositories as $repository) {
            self::assertTrue($repository->is_active);
            self::assertSame(0, bccomp($repository->balance, '0', 3));
            self::assertSame($cashAccount->id, $repository->gl_account_id);
            self::assertNotSame($siblingCash->id, $repository->gl_account_id);
            self::assertSame($main->id, $repository->location_id);
        }

        self::assertSame(
            $siblingCountBefore,
            PaymentRepository::query()->where('company_id', $this->existingCompany->id)->count(),
        );
        $this->assertProvisioningDidNotBookMoney($company);
    }

    /**
     * Provisioning is metadata-only. The sole opening-money write path is
     * {@see AccountingOpeningService::postBatch()},
     * which owns the journal entry, journal lines, and repository movement.
     */
    #[DataProvider('provisioningReadFailureProvider')]
    public function test_each_provisioning_read_failure_is_savepoint_contained(string $read): void
    {
        $state = new class
        {
            private bool $armed = false;

            private bool $injected = false;

            public function arm(): void
            {
                $this->armed = true;
            }

            public function shouldInject(): bool
            {
                return $this->armed && ! $this->injected;
            }

            public function markInjected(): void
            {
                $this->injected = true;
            }

            public function wasInjected(): bool
            {
                return $this->injected;
            }
        };
        $arm = static function () use ($state): void {
            $state->arm();
        };

        $runtimeProvisioner = app(CompanyPaymentRepositoryProvisionerInterface::class);
        $this->app->instance(
            CompanyPaymentRepositoryProvisionerInterface::class,
            new class($runtimeProvisioner, $arm) implements CompanyPaymentRepositoryProvisionerInterface
            {
                public function __construct(
                    private readonly CompanyPaymentRepositoryProvisionerInterface $delegate,
                    private readonly Closure $arm,
                ) {}

                public function provisionForCompany(
                    string $tenantId,
                    string $companyId,
                    ?string $defaultLocationId = null,
                ): void {
                    ($this->arm)();
                    $this->delegate->provisionForCompany($tenantId, $companyId, null);
                }
            },
        );

        DB::connection()->beforeExecuting(static function (
            string $query,
            array $_bindings,
            Connection $connection,
        ) use ($read, $state): void {
            if (! $state->shouldInject() || ! self::isProvisioningRead($read, $query)) {
                return;
            }

            $state->markInjected();
            $connection->select('SELECT * FROM g3c_forced_missing_table');
        });
        $logSpy = Log::spy();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'Read Failure '.$read,
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ])
            ->assertCreated();

        self::assertTrue($state->wasInjected(), "The {$read} read fault was not injected.");
        $company = Company::query()->findOrFail((string) $response->json('data.id'));
        self::assertSame(1, Location::query()->where('company_id', $company->id)->count());
        self::assertSame(1, UserCompanyMembership::query()->where('company_id', $company->id)->count());
        self::assertSame(0, PaymentRepository::query()->where('company_id', $company->id)->count());
        $this->assertProvisioningDidNotBookMoney($company);
        self::assertInstanceOf(LegacyMockInterface::class, $logSpy);
        $logSpy->shouldHaveReceived('error', [
            'payment_repositories.provisioning_failed',
            Mockery::on(static fn (array $context): bool => $context['company_id'] === $company->id
                && $context['exception'] instanceof Throwable),
        ]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provisioningReadFailureProvider(): iterable
    {
        yield 'company lookup' => ['company'];
        yield 'cash-account lookup' => ['account'];
        yield 'fallback-location lookup' => ['location'];
    }

    public function test_second_location_adds_only_its_branch_drawer_and_a_non_pos_location_adds_nothing(): void
    {
        $company = $this->createCompany('Company With Branches');
        $countBefore = PaymentRepository::query()->where('company_id', $company->id)->count();

        $branchResponse = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id)
            ->postJson('/api/v1/locations', [
                'name' => 'POS Branch',
                'code' => 'BRANCH',
                'type' => LocationType::Warehouse->value,
                'pos_enabled' => true,
            ])
            ->assertCreated();

        $branchId = (string) $branchResponse->json('data.id');
        self::assertSame(
            $countBefore + 1,
            PaymentRepository::query()->where('company_id', $company->id)->count(),
        );
        self::assertSame(
            1,
            PaymentRepository::query()
                ->where('company_id', $company->id)
                ->where('location_id', $branchId)
                ->where('type', RepositoryType::CashRegister->value)
                ->count(),
        );
        self::assertSame(
            2,
            PaymentRepository::query()
                ->where('company_id', $company->id)
                ->whereIn('code', ['CASH-01', 'SAFE-01'])
                ->count(),
        );

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id)
            ->postJson('/api/v1/locations', [
                'name' => 'Storage Only',
                'code' => 'STORAGE',
                'type' => LocationType::Warehouse->value,
                'pos_enabled' => false,
            ])
            ->assertCreated();

        self::assertSame(
            $countBefore + 1,
            PaymentRepository::query()->where('company_id', $company->id)->count(),
        );
    }

    public function test_re_run_is_idempotent_and_a_partial_company_gains_only_the_missing_safe(): void
    {
        $company = Company::factory()->for($this->tenant)->create();
        $location = $this->location($company, 'MAIN');
        $cashAccount = $this->cashAccount($company, '531');
        $operatorAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => '539',
            'type' => AccountType::Asset,
            'system_purpose' => null,
        ]);
        $cash = PaymentRepository::forceCreate([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH-01',
            'name' => 'Operator till',
            'type' => RepositoryType::CashRegister->value,
            'location_id' => $location->id,
            'account_id' => $operatorAccount->id,
            'gl_account_id' => $operatorAccount->id,
            'is_active' => true,
        ]);

        $provisioner = app(CompanyPaymentRepositoryProvisionerInterface::class);
        $provisioner->provisionForCompany($company->tenant_id, $company->id, $location->id);

        self::assertSame(2, PaymentRepository::query()->where('company_id', $company->id)->count());
        self::assertSame($operatorAccount->id, $cash->refresh()->gl_account_id);
        self::assertSame(
            $cashAccount->id,
            PaymentRepository::query()
                ->where('company_id', $company->id)
                ->where('code', 'SAFE-01')
                ->value('gl_account_id'),
        );

        $idsBefore = PaymentRepository::query()
            ->where('company_id', $company->id)
            ->orderBy('code')
            ->pluck('id')
            ->all();
        $provisioner->provisionForCompany($company->tenant_id, $company->id, $location->id);
        $provisioner->provisionForCompany($company->tenant_id, $company->id, $location->id);

        self::assertSame(
            $idsBefore,
            PaymentRepository::query()
                ->where('company_id', $company->id)
                ->orderBy('code')
                ->pluck('id')
                ->all(),
        );
    }

    public function test_registration_path_still_creates_exactly_two_repositories(): void
    {
        $this->location($this->existingCompany, 'MAIN');

        app(TenantInitializationService::class)->initializeForNewRegistration(
            $this->tenant,
            $this->existingCompany,
            $this->user,
        );

        self::assertSame(
            2,
            PaymentRepository::query()->where('company_id', $this->existingCompany->id)->count(),
        );
        self::assertSame(
            ['CASH-01', 'SAFE-01'],
            PaymentRepository::query()
                ->where('company_id', $this->existingCompany->id)
                ->orderBy('code')
                ->pluck('code')
                ->all(),
        );
    }

    public function test_null_location_prefers_the_company_default_over_older_active_pos_locations(): void
    {
        $company = Company::factory()->for($this->tenant)->create();
        $this->cashAccount($company, '531');
        $this->locationWithPriority($company, 'OLDER-POS', false, true, true, now()->subDays(2));
        $default = $this->locationWithPriority($company, 'DEFAULT', true, false, false, now());

        app(CompanyPaymentRepositoryProvisionerInterface::class)
            ->provisionForCompany($company->tenant_id, $company->id, null);

        self::assertSame(
            [$default->id],
            PaymentRepository::query()->where('company_id', $company->id)->pluck('location_id')->unique()->values()->all(),
        );
    }

    public function test_null_location_without_a_default_prefers_active_then_pos_then_oldest(): void
    {
        $company = Company::factory()->for($this->tenant)->create();
        $this->cashAccount($company, '531');
        $this->locationWithPriority($company, 'INACTIVE-POS', false, false, true, now()->subDays(4));
        $this->locationWithPriority($company, 'ACTIVE-NON-POS', false, true, false, now()->subDays(3));
        $expected = $this->locationWithPriority($company, 'OLDER-ACTIVE-POS', false, true, true, now()->subDays(2));
        $this->locationWithPriority($company, 'NEWER-ACTIVE-POS', false, true, true, now()->subDay());

        app(CompanyPaymentRepositoryProvisionerInterface::class)
            ->provisionForCompany($company->tenant_id, $company->id, null);

        self::assertSame(
            [$expected->id],
            PaymentRepository::query()->where('company_id', $company->id)->pluck('location_id')->unique()->values()->all(),
        );
    }

    public function test_null_location_remains_null_when_the_company_has_no_location(): void
    {
        $company = Company::factory()->for($this->tenant)->create();
        $this->cashAccount($company, '531');

        app(CompanyPaymentRepositoryProvisionerInterface::class)
            ->provisionForCompany($company->tenant_id, $company->id, null);

        self::assertSame(2, PaymentRepository::query()
            ->where('company_id', $company->id)
            ->whereNull('location_id')
            ->count());
    }

    public function test_explicit_location_is_honoured_without_fallback_resolution(): void
    {
        $company = Company::factory()->for($this->tenant)->create();
        $this->cashAccount($company, '531');
        $this->locationWithPriority($company, 'DEFAULT', true, true, true, now()->subDay());
        $explicit = $this->locationWithPriority($company, 'EXPLICIT', false, false, false, now());

        app(CompanyPaymentRepositoryProvisionerInterface::class)
            ->provisionForCompany($company->tenant_id, $company->id, $explicit->id);

        self::assertSame(
            [$explicit->id],
            PaymentRepository::query()->where('company_id', $company->id)->pluck('location_id')->unique()->values()->all(),
        );
    }

    public function test_company_without_a_cash_purpose_account_is_created_with_two_unlinked_repositories(): void
    {
        $realChartProvisioner = app(ChartOfAccountsService::class);
        $chartProvisionerWithoutCash = new class($realChartProvisioner) extends ChartOfAccountsService
        {
            public function __construct(
                private readonly ChartOfAccountsService $delegate,
            ) {}

            public function seedForCompany(Company $company): array
            {
                $result = $this->delegate->seedForCompany($company);
                Account::query()
                    ->where('company_id', $company->id)
                    ->where('system_purpose', SystemAccountPurpose::Cash->value)
                    ->update(['system_purpose' => null]);

                return $result;
            }
        };
        $this->app->instance(ChartOfAccountsService::class, $chartProvisionerWithoutCash);
        $logSpy = Log::spy();

        $company = $this->createCompany('Company Without Cash Purpose');

        self::assertFalse(Account::query()
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::Cash->value)
            ->exists());
        self::assertSame(2, PaymentRepository::query()->where('company_id', $company->id)->count());
        self::assertSame(
            2,
            PaymentRepository::query()
                ->where('company_id', $company->id)
                ->whereNull('account_id')
                ->whereNull('gl_account_id')
                ->count(),
        );
        $logSpy->shouldHaveReceived('warning', [
            'payment_repositories.cash_account_missing',
            ['company_id' => $company->id],
        ]);
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

    private function cashAccount(Company $company, string $code): Account
    {
        return Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => $code,
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Cash,
        ]);
    }

    private function location(Company $company, string $code): Location
    {
        return Location::factory()->for($company)->create([
            'code' => $code,
            'type' => LocationType::Shop,
            'is_default' => true,
            'is_active' => true,
            'pos_enabled' => true,
        ]);
    }

    private function locationWithPriority(
        Company $company,
        string $code,
        bool $isDefault,
        bool $isActive,
        bool $posEnabled,
        \DateTimeInterface $createdAt,
    ): Location {
        return Location::factory()->for($company)->create([
            'code' => $code,
            'type' => LocationType::Shop,
            'is_default' => $isDefault,
            'is_active' => $isActive,
            'pos_enabled' => $posEnabled,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function assertProvisioningDidNotBookMoney(Company $company): void
    {
        self::assertSame(0, DB::table('repository_movements')->where('company_id', $company->id)->count());
        self::assertSame(0, DB::table('journal_entries')->where('company_id', $company->id)->count());
        self::assertSame(0, DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            ->count());
    }

    private static function isProvisioningRead(string $read, string $query): bool
    {
        $query = strtolower($query);

        return match ($read) {
            'company' => str_contains($query, 'from "companies" where "companies"."id"'),
            'account' => str_contains($query, 'from "accounts"')
                && str_contains($query, '"system_purpose"'),
            'location' => str_contains($query, 'from "locations"')
                && str_contains($query, 'order by "is_default" desc'),
            default => false,
        };
    }
}
