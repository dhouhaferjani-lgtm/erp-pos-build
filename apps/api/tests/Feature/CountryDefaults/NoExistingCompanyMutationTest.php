<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CountryDefaults\M4Fixtures;
use Tests\TestCase;

final class NoExistingCompanyMutationTest extends TestCase
{
    use M4Fixtures;
    use RefreshDatabase;

    public function test_template_provisioning_for_a_new_company_never_writes_existing_company_accounts(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'M5 mutation boundary',
            'slug' => 'm5-mutation-boundary',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $existing = $this->company($tenant, 'existing');
        $target = $this->company($tenant, 'new');
        Account::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $existing->id,
            'code' => '1000',
            'name' => 'Operator chart must survive',
            'type' => 'expense',
            'system_purpose' => null,
            'is_system' => false,
            'balance' => '987.654',
        ]);
        $before = Account::query()->where('company_id', $existing->id)->get()->toJson();

        $actor = $this->m4Actor();
        $template = $this->m4Published('generic', '*', $actor);
        $this->m4Assign('*', $template, $actor);
        config(['country_defaults.provisioning_enabled' => true]);
        app(ChartOfAccountsService::class)->seedForCompany($target);

        self::assertSame($before, Account::query()->where('company_id', $existing->id)->get()->toJson());
        self::assertGreaterThan(1, Account::query()->where('company_id', $target->id)->count());
    }

    private function company(Tenant $tenant, string $suffix): Company
    {
        return Company::query()->create([
            'tenant_id' => $tenant->id,
            'name' => "M5 {$suffix}",
            'country_code' => 'ZZ',
            'currency' => 'EUR',
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);
    }
}
