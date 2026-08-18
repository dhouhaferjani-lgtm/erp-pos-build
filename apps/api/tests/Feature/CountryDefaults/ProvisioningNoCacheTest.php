<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\CountryDefaults\Application\Services\TemplatePublishingService;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CountryDefaults\M4Fixtures;
use Tests\TestCase;

final class ProvisioningNoCacheTest extends TestCase
{
    use M4Fixtures;
    use RefreshDatabase;

    public function test_same_service_observes_flag_change_on_the_next_company(): void
    {
        $service = app(ChartOfAccountsService::class);
        config(['country_defaults.provisioning_enabled' => false]);
        $legacy = $this->company('flag-legacy');
        $service->seedForCompany($legacy);

        $template = $this->publishedWildcard('Flag changed template');
        $actor = $this->m4Actor();
        $this->m4Assign('*', $template, $actor);
        config(['country_defaults.provisioning_enabled' => true]);
        $switched = $this->company('flag-template');
        $service->seedForCompany($switched);

        self::assertSame('Equity', $this->accountName($legacy));
        self::assertSame('Flag changed template', $this->accountName($switched));
    }

    public function test_same_service_observes_assignment_repoint_on_the_next_company(): void
    {
        config(['country_defaults.provisioning_enabled' => true]);
        $actor = $this->m4Actor();
        $first = $this->publishedWildcard('First assignment');
        $second = $this->publishedWildcard('Second assignment');
        $this->m4Assign('*', $first, $actor);
        $service = app(ChartOfAccountsService::class);
        $firstCompany = $this->company('assignment-first');
        $service->seedForCompany($firstCompany);

        $this->m4Assign('*', $second, $actor);
        $secondCompany = $this->company('assignment-second');
        $service->seedForCompany($secondCompany);

        self::assertSame('First assignment', $this->accountName($firstCompany));
        self::assertSame('Second assignment', $this->accountName($secondCompany));
    }

    private function publishedWildcard(string $name): AdminTemplate
    {
        $actor = $this->m4Actor();
        $template = $this->m4Draft('generic');
        DB::connection($template->getConnectionName())->transaction(function () use ($template, $name): void {
            $template->accounts()->where('code', '1000')->firstOrFail()->update(['name' => $name]);
        });

        return app(TemplatePublishingService::class)->publish(
            $template->id,
            'M5 no-cache proof',
            ['*'],
            $actor,
        );
    }

    private function company(string $suffix): Company
    {
        $tenant = Tenant::query()->create([
            'name' => "M5 {$suffix}",
            'slug' => 'm5-'.$suffix,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        return Company::query()->create([
            'tenant_id' => $tenant->id,
            'name' => "M5 {$suffix}",
            'country_code' => 'ZZ',
            'currency' => 'EUR',
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);
    }

    private function accountName(Company $company): string
    {
        return (string) Account::query()
            ->where('company_id', $company->id)
            ->where('code', '1000')
            ->value('name');
    }
}
