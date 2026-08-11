<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Enums\Vertical;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use App\Modules\CountryDefaults\Infrastructure\Models\CountryTemplateAssignment;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CentralConnectionUnderTenancyTest extends TestCase
{
    use RefreshDatabase;

    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = DB::getDefaultConnection();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        DB::purge('country_defaults_tenant_probe');
        config(['database.default' => $this->originalDefaultConnection]);
        DB::setDefaultConnection($this->originalDefaultConnection);

        parent::tearDown();
    }

    public function test_all_country_defaults_models_remain_on_central_after_default_connection_swap(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Central pin tenant',
            'slug' => 'central-pin-'.Str::lower(Str::random(8)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => [],
        ]);

        config(['tenancy.bootstrappers' => []]);
        tenancy()->initialize($tenant);
        $centralConnection = (string) config('tenancy.database.central_connection');

        config([
            'database.connections.country_defaults_tenant_probe' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'country_defaults_tenant_probe',
        ]);
        DB::purge('country_defaults_tenant_probe');

        self::assertTrue(tenancy()->initialized);
        self::assertSame('country_defaults_tenant_probe', DB::getDefaultConnection());

        $template = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Central while tenant active',
            'status' => TemplateStatus::Draft,
        ]);
        $row = AdminTemplateAccount::query()->create([
            'template_id' => $template->id,
            'code' => '100',
            'name' => 'Central row',
            'type' => 'asset',
            'parent_code' => null,
            'system_purpose' => null,
            'is_system' => false,
            'sort_order' => 1,
        ]);
        $assignment = CountryTemplateAssignment::query()->create([
            'country_code' => 'FR',
            'domain' => TemplateDomain::ChartOfAccounts,
            'template_id' => $template->id,
        ]);

        foreach ([$template, $row, $assignment] as $model) {
            self::assertSame($centralConnection, $model->getConnectionName());
        }
        self::assertSame('Central while tenant active', AdminTemplate::query()->findOrFail($template->id)->name);
        self::assertSame('100', $assignment->template()->firstOrFail()->accounts()->firstOrFail()->code);
    }

    public function test_certification_scope_uses_jsonb_on_postgresql_and_json_compatibility_on_sqlite(): void
    {
        $centralConnection = (string) config('tenancy.database.central_connection');

        if (DB::connection($centralConnection)->getDriverName() === 'pgsql') {
            $type = DB::connection($centralConnection)->table('information_schema.columns')
                ->where('table_schema', 'public')
                ->where('table_name', 'admin_templates')
                ->where('column_name', 'certified_country_codes')
                ->value('data_type');
            self::assertSame('jsonb', $type);

            return;
        }

        self::assertSame(
            'text',
            Schema::connection($centralConnection)->getColumnType('admin_templates', 'certified_country_codes'),
        );
    }
}
