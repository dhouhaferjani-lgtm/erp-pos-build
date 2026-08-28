<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\SuperAdmin;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\InventoryVarianceAccountProvisioner;
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
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\LegacyMockInterface;
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

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function refundCompensationCountries(): iterable
    {
        yield 'Tunisia' => ['tn', 'TN', '709', 'Rabais, remises et ristournes accordés', 'Perte sur remboursement (write-off)'];
        yield 'generic fallback' => ['generic', 'ZZ', '7090', 'Sales Returns', 'Refund Write-Off'];
    }

    #[DataProvider('refundCompensationCountries')]
    public function test_pre_policy_templates_still_provision_both_refund_compensation_purposes(
        string $templatePlan,
        string $countryCode,
        string $salesReturnCode,
        string $salesReturnName,
        string $writeOffName,
    ): void {
        $actor = $this->m4Actor();
        $template = $this->m4Published($templatePlan, $countryCode, $actor);
        $this->m4Assign($countryCode, $template, $actor);

        // Simulate a published chart certified before refund compensation
        // accounts became mandatory for every newly provisioned company.
        $template->accounts()
            ->whereIn('system_purpose', [
                SystemAccountPurpose::SalesReturn->value,
                SystemAccountPurpose::RefundWriteOff->value,
            ])
            ->delete();

        config(['country_defaults.provisioning_enabled' => true]);
        [, $company] = $this->tenantCompanyUser($countryCode, 'refund-default-'.strtolower($countryCode));

        $drifts = app(ChartOfAccountsService::class)->seedForCompany($company);

        $salesReturn = Account::findByPurpose($company->id, SystemAccountPurpose::SalesReturn);
        $writeOff = Account::findByPurpose($company->id, SystemAccountPurpose::RefundWriteOff);

        self::assertNotNull($salesReturn, 'Every fresh chart must resolve sales_return.');
        self::assertNotNull($writeOff, 'Every fresh chart must resolve refund_write_off.');
        self::assertSame($salesReturnCode, $salesReturn->code);
        self::assertSame($salesReturnName, $salesReturn->name);
        self::assertSame('6590', $writeOff->code);
        self::assertSame($writeOffName, $writeOff->name);
        self::assertSame([], $drifts, 'A newly provisioned chart must use the canonical refund definitions without drift.');
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

    public function test_template_variance_overlay_warns_and_skips_optional_gain_without_a_compatible_parent(): void
    {
        $actor = $this->m4Actor();
        $draft = $this->m4Draft('generic');
        $draft->accounts()
            ->where('system_purpose', SystemAccountPurpose::InventoryGainIncome->value)
            ->delete();
        $draft->accounts()->create([
            'code' => '9000',
            'name' => 'Third-plan revenue root',
            'type' => 'revenue',
            'parent_code' => null,
            'system_purpose' => null,
            'is_system' => false,
            'sort_order' => (int) $draft->accounts()->max('sort_order') + 1,
        ]);
        $draft->accounts()->where('parent_code', '7000')->update(['parent_code' => '9000']);
        $draft->accounts()->where('code', '7000')->delete();
        $template = app(TemplatePublishingService::class)->publish(
            $draft->id,
            'Third plan without an optional variance-gain parent',
            ['GB'],
            $actor,
        );
        $this->m4Assign('GB', $template, $actor);
        config(['country_defaults.provisioning_enabled' => true]);
        [$tenant, $company] = $this->tenantCompanyUser('GB', 'template-third-plan');
        $logSpy = Log::spy();

        app(ChartOfAccountsService::class)->seedForCompany($company);

        self::assertTrue(
            Account::query()
                ->where('company_id', $company->id)
                ->where('system_purpose', SystemAccountPurpose::InventoryShrinkageExpense->value)
                ->exists(),
        );
        self::assertFalse(
            Account::query()
                ->where('company_id', $company->id)
                ->where('system_purpose', SystemAccountPurpose::InventoryGainIncome->value)
                ->exists(),
        );
        self::assertInstanceOf(LegacyMockInterface::class, $logSpy);
        $logSpy->shouldHaveReceived('warning', [
            'INVENTORY-VARIANCE-TEMPLATE-OVERLAY skipped optional gain: no compatible revenue parent.',
            Mockery::on(static fn (array $context): bool => $context === [
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'country_code' => 'GB',
            ]),
        ])->once();
    }

    public function test_pre_policy_third_plan_template_installs_required_shrinkage_without_aborting_creation(): void
    {
        $actor = $this->m4Actor();
        $draft = $this->m4Draft('generic');
        $nextOrder = (int) $draft->accounts()->max('sort_order') + 1;
        $draft->accounts()->create([
            'code' => '8000',
            'name' => 'Third-plan expense root',
            'type' => 'expense',
            'parent_code' => null,
            'system_purpose' => null,
            'is_system' => false,
            'sort_order' => $nextOrder,
        ]);
        $draft->accounts()->create([
            'code' => '9000',
            'name' => 'Third-plan revenue root',
            'type' => 'revenue',
            'parent_code' => null,
            'system_purpose' => null,
            'is_system' => false,
            'sort_order' => $nextOrder + 1,
        ]);
        $draft->accounts()->where('parent_code', '6000')->update(['parent_code' => '8000']);
        $draft->accounts()->where('parent_code', '7000')->update(['parent_code' => '9000']);
        $draft->accounts()->whereIn('code', ['6000', '7000'])->delete();
        $template = app(TemplatePublishingService::class)->publish(
            $draft->id,
            'Third plan carrying neither variance family root',
            ['GB'],
            $actor,
        );
        $this->m4Assign('GB', $template, $actor);
        // Certified before shrinkage became REQUIRED: the resolver checks publication
        // and capability metadata, not mutable row hashes, so a chart with NEITHER
        // plan family and neither purpose still reaches the creation boundary. It must
        // complete — a REQUIRED purpose may never roll back tenant registration.
        $template->accounts()
            ->whereIn('system_purpose', [
                SystemAccountPurpose::InventoryShrinkageExpense->value,
                SystemAccountPurpose::InventoryGainIncome->value,
            ])
            ->delete();
        config(['country_defaults.provisioning_enabled' => true]);
        [$tenant, $company] = $this->tenantCompanyUser('GB', 'template-neither-family');
        $logSpy = Log::spy();

        app(ChartOfAccountsService::class)->seedForCompany($company);

        $shrinkage = Account::query()
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::InventoryShrinkageExpense->value)
            ->firstOrFail();
        self::assertSame(
            '8000',
            Account::query()->whereKey($shrinkage->parent_id)->value('code'),
            'the REQUIRED shrinkage account must graft onto a same-type root already in the chart',
        );
        self::assertFalse(
            Account::query()
                ->where('company_id', $company->id)
                ->where('system_purpose', SystemAccountPurpose::InventoryGainIncome->value)
                ->exists(),
        );
        self::assertInstanceOf(LegacyMockInterface::class, $logSpy);
        $logSpy->shouldHaveReceived('warning', [
            'INVENTORY-VARIANCE-TEMPLATE-OVERLAY grafted required shrinkage onto a fallback parent: no compatible expense plan parent.',
            Mockery::on(static fn (array $context): bool => $context === [
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'country_code' => 'GB',
                'parent_code' => '8000',
            ]),
        ])->once();
    }

    public function test_template_overlay_installs_required_shrinkage_as_a_root_when_no_expense_root_exists(): void
    {
        [$tenant, $company] = $this->tenantCompanyUser('GB', 'overlay-no-expense-root');
        Account::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '1000',
            'name' => 'Equity',
            'type' => 'equity',
            'is_active' => true,
            'is_system' => false,
            'balance' => '0.000',
        ]);
        $logSpy = Log::spy();

        app(InventoryVarianceAccountProvisioner::class)
            ->provisionTemplateCompany($company->id, $tenant->id, 'GB');

        $shrinkage = Account::query()
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::InventoryShrinkageExpense->value)
            ->firstOrFail();
        self::assertNull(
            $shrinkage->parent_id,
            'with no same-type root to graft onto, the REQUIRED account installs as a root of its own',
        );
        self::assertFalse(
            Account::query()
                ->where('company_id', $company->id)
                ->where('system_purpose', SystemAccountPurpose::InventoryGainIncome->value)
                ->exists(),
        );
        self::assertInstanceOf(LegacyMockInterface::class, $logSpy);
        $logSpy->shouldHaveReceived('warning', [
            'INVENTORY-VARIANCE-TEMPLATE-OVERLAY grafted required shrinkage onto a fallback parent: no compatible expense plan parent.',
            Mockery::on(static fn (array $context): bool => $context === [
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'country_code' => 'GB',
                'parent_code' => null,
            ]),
        ])->once();
    }

    public function test_template_overlay_refuses_a_plan_parent_code_of_the_wrong_type(): void
    {
        [$tenant, $company] = $this->tenantCompanyUser('GB', 'overlay-wrong-typed-parent');
        foreach ([['6000', 'expense'], ['7000', 'expense']] as [$code, $type]) {
            Account::query()->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'code' => $code,
                'name' => "Operator root {$code}",
                'type' => $type,
                'is_active' => true,
                'is_system' => false,
                'balance' => '0.000',
            ]);
        }

        app(InventoryVarianceAccountProvisioner::class)
            ->provisionTemplateCompany($company->id, $tenant->id, 'GB');

        $shrinkage = Account::query()
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::InventoryShrinkageExpense->value)
            ->firstOrFail();
        self::assertSame('6000', Account::query()->whereKey($shrinkage->parent_id)->value('code'));
        self::assertFalse(
            Account::query()
                ->where('company_id', $company->id)
                ->where('system_purpose', SystemAccountPurpose::InventoryGainIncome->value)
                ->exists(),
            'a revenue gain must never be grafted beneath an expense-typed lookalike code',
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
