<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\TenantInitializationService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DPA lane H-3 — a freshly registered tenant must be born with a CLEAN treasury.
 *
 * The registration path used to mint three cash repositories with fabricated
 * opening cash plus named third-party bank accounts (Banque de Tunisie, BNP
 * Paribas, …), each non-zero balance pushed through the treasury movement port
 * as a real `opening_balance` movement. Owner ruling 2026-08-09: "no hard data
 * anywhere … a new customer needs a clean setup".
 *
 * A fresh tenant now gets exactly ONE cash register + ONE safe, both at zero,
 * with no bank identity and no movements. Balances enter only through the real
 * opening-balance document lane.
 */
final class CleanTenantRegistrationTreasuryTest extends TestCase
{
    use RefreshDatabase;

    private TenantInitializationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CountriesSeeder::class);

        $this->service = app(TenantInitializationService::class);
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function countryProvider(): array
    {
        return [
            'Tunisia' => ['TN', 'TND'],
            'France' => ['FR', 'EUR'],
            'unmapped country' => ['US', 'USD'],
        ];
    }

    #[DataProvider('countryProvider')]
    public function test_registration_creates_exactly_one_cash_register_and_one_safe(
        string $countryCode,
        string $currency,
    ): void {
        [$tenant, $company, $user] = $this->createTenantCompanyUser($countryCode, $currency);

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        $repositories = PaymentRepository::query()
            ->where('company_id', $company->id)
            ->orderBy('code')
            ->get();

        $this->assertCount(2, $repositories, 'Registration must create exactly two repositories.');
        $this->assertSame(
            [RepositoryType::CashRegister, RepositoryType::Safe],
            $repositories->pluck('type')->sortBy(fn (RepositoryType $t): string => $t->value)->values()->all(),
            'Registration must create one cash_register and one safe — nothing else.',
        );
    }

    #[DataProvider('countryProvider')]
    public function test_registration_creates_no_bank_or_virtual_repositories(
        string $countryCode,
        string $currency,
    ): void {
        [$tenant, $company, $user] = $this->createTenantCompanyUser($countryCode, $currency);

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        $repositories = PaymentRepository::query()
            ->where('company_id', $company->id)
            ->get();

        foreach ($repositories as $repository) {
            $this->assertNotContains(
                $repository->type,
                [RepositoryType::BankAccount, RepositoryType::Virtual],
                "Registration created a {$repository->type->value} repository ({$repository->code}).",
            );
            $this->assertNull($repository->bank_id, "Repository {$repository->code} carries a bank identity.");
            $this->assertNull($repository->bank_name, "Repository {$repository->code} carries a bank name.");
            $this->assertNull($repository->account_number, "Repository {$repository->code} carries an account number.");
            $this->assertNull($repository->iban, "Repository {$repository->code} carries an IBAN.");
            $this->assertNull($repository->bic, "Repository {$repository->code} carries a BIC.");
        }
    }

    #[DataProvider('countryProvider')]
    public function test_registration_leaves_every_repository_at_zero_with_no_movements(
        string $countryCode,
        string $currency,
    ): void {
        [$tenant, $company, $user] = $this->createTenantCompanyUser($countryCode, $currency);

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        $repositories = PaymentRepository::query()
            ->where('company_id', $company->id)
            ->get();

        foreach ($repositories as $repository) {
            $this->assertSame(
                0,
                bccomp($repository->balance, '0', 3),
                "Repository {$repository->code} was born with a non-zero balance ({$repository->balance}).",
            );
        }

        $this->assertSame(
            0,
            DB::table('repository_movements')->where('company_id', $company->id)->count(),
            'Registration must not lay down any repository movement.',
        );
        $this->assertSame(
            0,
            DB::table('journal_entries')->where('company_id', $company->id)->count(),
            'Registration must not post any journal entry.',
        );
    }

    #[DataProvider('countryProvider')]
    public function test_seeded_repositories_are_gl_linked_and_active(
        string $countryCode,
        string $currency,
    ): void {
        [$tenant, $company, $user] = $this->createTenantCompanyUser($countryCode, $currency);

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        $repositories = PaymentRepository::query()
            ->where('company_id', $company->id)
            ->get();

        foreach ($repositories as $repository) {
            $this->assertTrue($repository->is_active, "Repository {$repository->code} must be active.");
            $this->assertNotNull($repository->gl_account_id, "Repository {$repository->code} must be GL-linked.");
            $this->assertSame($repository->gl_account_id, $repository->account_id);
            $this->assertSame($tenant->id, $repository->tenant_id);
        }
    }

    public function test_seeded_repository_names_are_localised_from_the_company_locale(): void
    {
        [$tenantFr, $companyFr, $userFr] = $this->createTenantCompanyUser('TN', 'TND', '-fr', 'fr_TN');
        $this->service->initializeForNewRegistration($tenantFr, $companyFr, $userFr);

        [$tenantEn, $companyEn, $userEn] = $this->createTenantCompanyUser('TN', 'TND', '-en', 'en_GB');
        $this->service->initializeForNewRegistration($tenantEn, $companyEn, $userEn);

        $frNames = PaymentRepository::query()->where('company_id', $companyFr->id)->orderBy('code')->pluck('name')->all();
        $enNames = PaymentRepository::query()->where('company_id', $companyEn->id)->orderBy('code')->pluck('name')->all();

        $this->assertSame(
            [trans('treasury.default_repositories.cash_register', [], 'fr'), trans('treasury.default_repositories.safe', [], 'fr')],
            $frNames,
        );
        $this->assertSame(
            [trans('treasury.default_repositories.cash_register', [], 'en'), trans('treasury.default_repositories.safe', [], 'en')],
            $enNames,
        );
        $this->assertNotSame($frNames, $enNames, 'The two locales must not resolve to the same labels.');
    }

    /**
     * @return array{0: Tenant, 1: Company, 2: User}
     */
    private function createTenantCompanyUser(
        string $countryCode,
        string $currency,
        string $suffix = '',
        string $locale = 'fr',
    ): array {
        $tenant = Tenant::create([
            'name' => "H3 Tenant {$countryCode}{$suffix}",
            'slug' => 'h3-tenant-'.strtolower($countryCode).strtolower($suffix),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
            'country_code' => strtoupper($countryCode),
            'currency_code' => $currency,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => "H3 Company {$countryCode}{$suffix}",
            'country_code' => strtoupper($countryCode),
            'currency' => $currency,
            'locale' => $locale,
            'timezone' => 'UTC',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => "H3 User {$countryCode}{$suffix}",
            'email' => strtolower($countryCode).strtolower($suffix).'@h3.test',
            'password' => 'password',
            'status' => 'active',
        ]);

        return [$tenant, $company, $user];
    }
}
