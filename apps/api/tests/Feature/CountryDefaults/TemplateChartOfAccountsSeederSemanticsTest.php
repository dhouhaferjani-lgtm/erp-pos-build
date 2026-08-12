<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\CountryDefaults\Infrastructure\Seeders\TemplateChartOfAccountsSeeder;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CountryDefaults\M4Fixtures;
use Tests\TestCase;

final class TemplateChartOfAccountsSeederSemanticsTest extends TestCase
{
    use M4Fixtures;
    use RefreshDatabase;

    public function test_first_pass_preserves_operator_fields_and_only_promotes_system_flag(): void
    {
        $actor = $this->m4Actor();
        $template = $this->m4Published('generic', '*', $actor);
        $company = $this->company();
        $definition = $template->accounts()->where('code', '5000')->firstOrFail();
        $existing = Account::query()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'code' => $definition->code,
            'name' => 'Operator-owned name',
            'type' => AccountType::Equity,
            'system_purpose' => SystemAccountPurpose::RetainedEarnings,
            'is_system' => false,
            'balance' => '0.000',
        ]);

        app(TemplateChartOfAccountsSeeder::class)->seed($template, $company);

        $existing->refresh();
        self::assertSame('Operator-owned name', $existing->name);
        self::assertSame(AccountType::Equity, $existing->type);
        self::assertSame(SystemAccountPurpose::RetainedEarnings, $existing->system_purpose);
        self::assertTrue($existing->is_system);
    }

    public function test_second_pass_restores_parent_links_for_existing_rows_including_roots(): void
    {
        $actor = $this->m4Actor();
        $template = $this->m4Published('generic', '*', $actor);
        $company = $this->company();
        $seeder = app(TemplateChartOfAccountsSeeder::class);
        $seeder->seed($template, $company);

        $root = Account::query()->where('company_id', $company->id)->where('code', '5000')->firstOrFail();
        $child = Account::query()->where('company_id', $company->id)->where('code', '5100')->firstOrFail();
        $wrongParent = Account::query()->where('company_id', $company->id)->where('code', '6000')->firstOrFail();
        $root->update(['parent_id' => $wrongParent->id]);
        $child->update(['parent_id' => $wrongParent->id]);

        $seeder->seed($template, $company);

        self::assertNull($root->refresh()->parent_id);
        self::assertSame($root->id, $child->refresh()->parent_id);
        self::assertSame($template->accounts()->count(), Account::query()->where('company_id', $company->id)->count());
    }

    public function test_seed_is_company_scoped_and_never_changes_another_company(): void
    {
        $actor = $this->m4Actor();
        $template = $this->m4Published('generic', '*', $actor);
        $target = $this->company();
        $other = $this->company();
        $sentinel = Account::query()->create([
            'tenant_id' => $other->tenant_id,
            'company_id' => $other->id,
            'code' => '5000',
            'name' => 'Existing company sentinel',
            'type' => AccountType::Asset,
            'is_system' => false,
            'balance' => '42.000',
        ]);

        app(TemplateChartOfAccountsSeeder::class)->seed($template, $target);

        self::assertSame('Existing company sentinel', $sentinel->refresh()->name);
        self::assertFalse($sentinel->is_system);
        self::assertSame('42.000', $sentinel->balance);
        self::assertSame(1, Account::query()->where('company_id', $other->id)->count());
    }

    private function company(): Company
    {
        $tenant = Tenant::query()->create([
            'name' => 'M5 Seeder Tenant',
            'slug' => 'm5-seeder-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        return Company::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'M5 Seeder Company',
            'country_code' => 'ZZ',
            'currency' => 'EUR',
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);
    }
}
