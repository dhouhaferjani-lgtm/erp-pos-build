<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\SuperAdmin;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\CountryDefaults\Application\Services\TemplatePublishingService;
use App\Modules\CountryDefaults\Domain\Exceptions\TemplateRecertificationRequiredException;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\TenantInitializationService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use Database\Seeders\CountryDefaultsChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CountryDefaults\M4Fixtures;
use Tests\TestCase;

final class ProvisioningFlagMatrixTest extends TestCase
{
    use M4Fixtures;
    use RefreshDatabase;

    public function test_both_country_defaults_activation_flags_are_disabled_by_default(): void
    {
        self::assertFalse(config('country_defaults.provisioning_enabled'));
        self::assertFalse(config('country_defaults.external_editors_enabled'));
    }

    public function test_additional_company_path_uses_legacy_seeder_when_flag_is_false(): void
    {
        config(['country_defaults.provisioning_enabled' => false]);
        [, $company] = $this->tenantCompanyUser('ZZ', 'legacy-additional');

        app(ChartOfAccountsService::class)->seedForCompany($company);

        self::assertSame('Equity', $this->accountName($company, '1000'));
        $this->assertInventoryVariancePurposes($company);
    }

    public function test_additional_company_path_uses_template_when_flag_is_true(): void
    {
        $this->assignCustomWildcard('Template Equity');
        config(['country_defaults.provisioning_enabled' => true]);
        [, $company] = $this->tenantCompanyUser('ZZ', 'template-additional');

        app(ChartOfAccountsService::class)->seedForCompany($company);

        self::assertSame('Template Equity', $this->accountName($company, '1000'));
    }

    public function test_pre_policy_published_template_is_completed_with_inventory_variance_purposes(): void
    {
        $actor = $this->m4Actor();
        $template = $this->m4Published('generic', '*', $actor);
        $this->m4Assign('*', $template, $actor);
        // Simulate an assignment certified before shrinkage became REQUIRED. The
        // resolver intentionally checks publication/capability metadata, not mutable
        // row hashes, so the company-creation boundary must still make this chart safe.
        $template->accounts()
            ->whereIn('system_purpose', [
                SystemAccountPurpose::InventoryShrinkageExpense->value,
                SystemAccountPurpose::InventoryGainIncome->value,
            ])
            ->delete();
        config(['country_defaults.provisioning_enabled' => true]);
        [, $company] = $this->tenantCompanyUser('ZZ', 'pre-policy-template');

        app(ChartOfAccountsService::class)->seedForCompany($company);

        $this->assertInventoryVariancePurposes($company);
    }

    public function test_template_provisioning_rolls_back_the_chart_when_variance_installation_fails(): void
    {
        $actor = $this->m4Actor();
        $template = $this->m4Published('generic', '*', $actor);
        $this->m4Assign('*', $template, $actor);
        config(['country_defaults.provisioning_enabled' => true]);
        [$tenant, $company] = $this->tenantCompanyUser('ZZ', 'template-variance-rollback');
        $collision = Account::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '6586',
            'name' => 'Operator-owned collision',
            'type' => 'expense',
            'system_purpose' => SystemAccountPurpose::InventoryGainIncome,
            'is_active' => true,
            'is_system' => false,
            'balance' => '0.000',
        ]);

        try {
            app(ChartOfAccountsService::class)->seedForCompany($company);
            self::fail('A purpose collision must abort template provisioning.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('refusing to repurpose it', $exception->getMessage());
        }

        self::assertSame(1, Account::query()->where('company_id', $company->id)->count());
        self::assertNull($collision->refresh()->parent_id);
        self::assertFalse(Account::query()->where('company_id', $company->id)->where('code', '1000')->exists());
    }

    public function test_template_variance_overlay_uses_the_assigned_chart_plan_for_a_non_native_country(): void
    {
        $actor = $this->m4Actor();
        $draft = $this->m4Draft('fr');
        $draft->accounts()
            ->where('system_purpose', SystemAccountPurpose::InventoryGainIncome->value)
            ->delete();
        $nextOrder = (int) $draft->accounts()->max('sort_order') + 1;
        foreach (['6130', '6170', '6250', '6256'] as $offset => $protectedCode) {
            $draft->accounts()->create([
                'code' => $protectedCode,
                'name' => "Morocco protected expense {$protectedCode}",
                'type' => 'expense',
                'parent_code' => null,
                'system_purpose' => null,
                'is_system' => false,
                'sort_order' => $nextOrder + $offset,
            ]);
        }
        $template = app(TemplatePublishingService::class)->publish(
            $draft->id,
            'French plan assigned to Morocco without optional gain',
            ['MA'],
            $actor,
        );
        $this->m4Assign('MA', $template, $actor);
        config(['country_defaults.provisioning_enabled' => true]);
        [, $company] = $this->tenantCompanyUser('MA', 'template-plan-mismatch');

        app(ChartOfAccountsService::class)->seedForCompany($company);

        $gain = Account::query()
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::InventoryGainIncome->value)
            ->firstOrFail();
        self::assertSame(
            '75',
            Account::query()->whereKey($gain->parent_id)->value('code'),
            'the overlay must use the French-plan parent present in the assigned chart',
        );
    }

    public function test_country_parameterized_contract_consumer_uses_template_path(): void
    {
        $this->assignCustomWildcard('Contract Template Equity');
        config(['country_defaults.provisioning_enabled' => true]);
        [, $company] = $this->tenantCompanyUser('ZZ', 'template-contract');
        $seeder = new CountryDefaultsChartOfAccountsSeeder(
            app(ChartOfAccountsService::class),
            'zz',
        );

        $seeder->run($company->id, $company->tenant_id);

        self::assertSame('Contract Template Equity', $this->accountName($company, '1000'));
    }

    public function test_registration_path_uses_legacy_seeder_when_flag_is_false(): void
    {
        config(['country_defaults.provisioning_enabled' => false]);
        [$tenant, $company, $user] = $this->tenantCompanyUser('ZZ', 'legacy-registration');
        $this->seed(RolesAndPermissionsSeeder::class);

        app(TenantInitializationService::class)->initializeForNewRegistration($tenant, $company, $user);

        self::assertSame('Equity', $this->accountName($company, '1000'));
        $this->assertInventoryVariancePurposes($company);
    }

    public function test_registration_path_uses_template_when_flag_is_true(): void
    {
        $this->assignCustomWildcard('Template Equity');
        config(['country_defaults.provisioning_enabled' => true]);
        [$tenant, $company, $user] = $this->tenantCompanyUser('ZZ', 'template-registration');
        $this->seed(RolesAndPermissionsSeeder::class);

        app(TenantInitializationService::class)->initializeForNewRegistration($tenant, $company, $user);

        self::assertSame('Template Equity', $this->accountName($company, '1000'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function transitionPaths(): iterable
    {
        yield 'additional non-timbre to timbre' => ['additional', true];
        yield 'registration non-timbre to timbre' => ['registration', true];
        yield 'additional timbre to non-timbre' => ['additional', false];
        yield 'registration timbre to non-timbre' => ['registration', false];
    }

    /** @return iterable<string, array{string}> */
    public static function provisioningPaths(): iterable
    {
        yield 'additional company' => ['additional'];
        yield 'registration' => ['registration'];
    }

    #[DataProvider('provisioningPaths')]
    public function test_lowercase_tn_resolves_the_exact_assignment_through_each_path(string $path): void
    {
        config(['country_defaults.provisioning_enabled' => true]);
        $actor = $this->m4Actor();
        $template = $this->m4Published('tn', 'TN', $actor);
        $this->m4Assign('TN', $template, $actor);
        if ($path === 'registration') {
            $this->seed(RolesAndPermissionsSeeder::class);
        }
        [$tenant, $company, $user] = $this->transitionSubject('tn', "{$path}-lowercase");

        $this->provisionThrough($path, $tenant, $company, $user);

        self::assertTrue(
            Account::query()
                ->where('company_id', $company->id)
                ->where('system_purpose', SystemAccountPurpose::SalesStampDutyPayable->value)
                ->exists(),
        );
    }

    #[DataProvider('transitionPaths')]
    public function test_capability_transition_refuses_each_path_until_assignment_is_repointed(
        string $path,
        bool $becomesTimbre,
    ): void {
        config(['country_defaults.provisioning_enabled' => true]);
        $actor = $this->m4Actor();
        $country = $becomesTimbre ? 'ZZ' : 'TN';
        $old = $this->m4Published($becomesTimbre ? 'generic' : 'tn', $country, $actor);
        $this->m4Assign($country, $old, $actor);
        $this->bindCapabilities($becomesTimbre ? ['TN', 'ZZ'] : [], 'transition-v2');
        if ($path === 'registration') {
            $this->seed(RolesAndPermissionsSeeder::class);
        }

        [$staleTenant, $staleCompany, $staleUser] = $this->transitionSubject($country, "{$path}-stale");
        try {
            $this->provisionThrough($path, $staleTenant, $staleCompany, $staleUser);
            self::fail('A stale assignment must refuse provisioning after a capability transition.');
        } catch (TemplateRecertificationRequiredException) {
            self::assertSame(0, Account::query()->where('company_id', $staleCompany->id)->count());
        }

        $replacement = $becomesTimbre
            ? $this->publishedGenericWithStamp($country, $actor)
            : $this->publishedTunisiaWithoutStamp($country, $actor);
        $this->m4Assign($country, $replacement, $actor);

        [$freshTenant, $freshCompany, $freshUser] = $this->transitionSubject($country, "{$path}-fresh");
        $this->provisionThrough($path, $freshTenant, $freshCompany, $freshUser);

        self::assertSame(
            $becomesTimbre,
            Account::query()
                ->where('company_id', $freshCompany->id)
                ->where('system_purpose', SystemAccountPurpose::SalesStampDutyPayable->value)
                ->exists(),
        );
    }

    private function assignCustomWildcard(string $name): void
    {
        $actor = $this->m4Actor();
        $template = $this->m4Draft('generic');
        DB::connection($template->getConnectionName())->transaction(function () use ($template, $name): void {
            $template->accounts()->where('code', '1000')->firstOrFail()->update(['name' => $name]);
        });
        $published = app(TemplatePublishingService::class)->publish(
            $template->id,
            'M5 flag matrix',
            ['*'],
            $actor,
        );
        $this->m4Assign('*', $published, $actor);
    }

    /** @param list<string> $timbreCountries */
    private function bindCapabilities(array $timbreCountries, string $version): void
    {
        $this->app->bind(
            CountryAccountingCapabilities::class,
            static fn (): CountryAccountingCapabilities => new class($timbreCountries, $version) implements CountryAccountingCapabilities
            {
                /** @param list<string> $countries */
                public function __construct(
                    private readonly array $countries,
                    private readonly string $registryVersion,
                ) {}

                public function supportsStampDuty(string $countryCode): bool
                {
                    return in_array(strtoupper(trim($countryCode)), $this->countries, true);
                }

                public function version(): string
                {
                    return $this->registryVersion;
                }
            },
        );
    }

    private function publishedGenericWithStamp(string $country, SuperAdmin $actor): AdminTemplate
    {
        $template = $this->m4Draft('generic');
        DB::connection($template->getConnectionName())->transaction(function () use ($template): void {
            AdminTemplateAccount::query()->create([
                'template_id' => $template->id,
                'code' => '4999-STAMP',
                'name' => 'Sales stamp duty payable',
                'type' => 'liability',
                'parent_code' => '4000',
                'system_purpose' => SystemAccountPurpose::SalesStampDutyPayable,
                'is_system' => true,
                'sort_order' => $template->accounts()->max('sort_order') + 1,
            ]);
        });

        return app(TemplatePublishingService::class)->publish(
            $template->id,
            'M5 transitioned timbre fixture',
            [$country],
            $actor,
        );
    }

    private function publishedTunisiaWithoutStamp(string $country, SuperAdmin $actor): AdminTemplate
    {
        $template = $this->m4Draft('tn');
        DB::connection($template->getConnectionName())->transaction(function () use ($template): void {
            $template->accounts()
                ->where('system_purpose', SystemAccountPurpose::SalesStampDutyPayable->value)
                ->firstOrFail()
                ->delete();
        });

        return app(TemplatePublishingService::class)->publish(
            $template->id,
            'M5 transitioned non-timbre fixture',
            [$country],
            $actor,
        );
    }

    /** @return array{Tenant, Company, User} */
    private function transitionSubject(string $country, string $suffix): array
    {
        return $this->tenantCompanyUser($country, $suffix);
    }

    private function provisionThrough(string $path, Tenant $tenant, Company $company, User $user): void
    {
        if ($path === 'registration') {
            app(TenantInitializationService::class)->initializeForNewRegistration($tenant, $company, $user);

            return;
        }

        app(ChartOfAccountsService::class)->seedForCompany($company);
    }

    /** @return array{Tenant, Company, User} */
    private function tenantCompanyUser(string $countryCode, string $suffix): array
    {
        $tenant = Tenant::query()->create([
            'name' => "M5 {$suffix}",
            'slug' => 'm5-'.$suffix,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
            'country_code' => $countryCode,
            'currency_code' => 'EUR',
        ]);
        $company = Company::query()->create([
            'tenant_id' => $tenant->id,
            'name' => "M5 {$suffix}",
            'country_code' => $countryCode,
            'currency' => 'EUR',
            'locale' => 'en',
            'timezone' => 'UTC',
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => "M5 {$suffix}",
            'email' => "{$suffix}@example.test",
            'password' => 'irrelevant',
            'status' => 'active',
        ]);

        return [$tenant, $company, $user];
    }

    private function accountName(Company $company, string $code): string
    {
        return (string) Account::query()
            ->where('company_id', $company->id)
            ->where('code', $code)
            ->value('name');
    }

    private function assertInventoryVariancePurposes(Company $company): void
    {
        foreach ([
            SystemAccountPurpose::InventoryShrinkageExpense,
            SystemAccountPurpose::InventoryGainIncome,
        ] as $purpose) {
            self::assertTrue(
                Account::query()
                    ->where('company_id', $company->id)
                    ->where('system_purpose', $purpose->value)
                    ->exists(),
                "{$purpose->value} must be installed atomically on every provisioning path.",
            );
        }
    }
}
