<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\PaymentMethod;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

final class PaymentMethodInstrumentKindTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    public function test_maturity_method_requires_kind_and_round_trips_enum(): void
    {
        [$user, $company] = $this->authenticatedContext();

        $missing = $this->actingAs($user)->postJson('/api/v1/payment-methods', [
            'code' => 'TRAITE_CUSTOM',
            'name' => 'Custom traite',
            'has_maturity' => true,
        ]);
        $this->assertApiValidationErrors($missing, ['instrument_kind']);

        $created = $this->actingAs($user)->postJson('/api/v1/payment-methods', [
            'code' => 'TRAITE_CUSTOM',
            'name' => 'Custom traite',
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Effet->value,
        ])->assertCreated();

        $created->assertJsonPath('data.instrument_kind', InstrumentKind::Effet->value);
        $method = PaymentMethod::query()->where('company_id', $company->id)->where('code', 'TRAITE_CUSTOM')->firstOrFail();
        $this->assertSame(InstrumentKind::Effet, $method->instrument_kind);
    }

    public function test_update_that_enables_maturity_requires_kind(): void
    {
        [$user, $company] = $this->authenticatedContext();
        $method = PaymentMethod::query()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'code' => 'WIRE_LATER',
            'name' => 'Wire later',
            'has_maturity' => false,
            'is_active' => true,
        ]);

        $missing = $this->actingAs($user)->patchJson("/api/v1/payment-methods/{$method->id}", [
            'has_maturity' => true,
        ]);
        $this->assertApiValidationErrors($missing, ['instrument_kind']);

        $this->actingAs($user)->patchJson("/api/v1/payment-methods/{$method->id}", [
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Other->value,
        ])->assertOk()->assertJsonPath('data.instrument_kind', InstrumentKind::Other->value);
    }

    public function test_voucher_instrument_method_cannot_also_be_a_maturity_method(): void
    {
        [$user] = $this->authenticatedContext();

        $response = $this->actingAs($user)->postJson('/api/v1/payment-methods', [
            'code' => 'store_voucher',
            'name' => 'Invalid deferred voucher',
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Other->value,
        ]);

        $this->assertApiValidationErrors($response, ['code']);
    }

    public function test_country_seeders_are_idempotent_and_map_actual_maturity_codes(): void
    {
        $tnTenant = Tenant::factory()->create();
        $tnCompany = Company::factory()->tunisia()->create(['tenant_id' => $tnTenant->id]);
        $frTenant = Tenant::factory()->create();
        $frCompany = Company::factory()->create(['tenant_id' => $frTenant->id]);
        $seeder = new PaymentMethodSeeder;

        $seeder->run($tnCompany);
        $tnCount = PaymentMethod::query()->where('company_id', $tnCompany->id)->count();
        $seeder->run($tnCompany);

        $seeder->run($frCompany);
        $frCount = PaymentMethod::query()->where('company_id', $frCompany->id)->count();
        $seeder->run($frCompany);

        $this->assertSame($tnCount, PaymentMethod::query()->where('company_id', $tnCompany->id)->count());
        $this->assertSame($frCount, PaymentMethod::query()->where('company_id', $frCompany->id)->count());
        $this->assertSame(InstrumentKind::Cheque, $this->seededMethod($tnCompany, 'CHECK')->instrument_kind);
        $this->assertSame(InstrumentKind::Effet, $this->seededMethod($tnCompany, 'TRAITE')->instrument_kind);
        $this->assertSame(InstrumentKind::Cheque, $this->seededMethod($frCompany, 'CHECK')->instrument_kind);
        $this->assertSame(InstrumentKind::Effet, $this->seededMethod($frCompany, 'LCR')->instrument_kind);
        $this->assertSame(InstrumentKind::Effet, $this->seededMethod($frCompany, 'BILL_EXCHANGE')->instrument_kind);
        $this->assertSame(InstrumentKind::Other, $this->seededMethod($frCompany, 'DIRECT_DEBIT')->instrument_kind);
    }

    public function test_instrument_kind_migration_is_re_runnable(): void
    {
        $migration = require database_path(
            'migrations/tenant/2026_07_12_100100_add_instrument_kind_to_payment_methods.php'
        );

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('payment_methods', 'instrument_kind'));
    }

    /**
     * @return array{User, Company}
     */
    private function authenticatedContext(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo('treasury.manage');
        UserCompanyMembership::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($company->id);

        return [$user, $company];
    }

    private function seededMethod(Company $company, string $code): PaymentMethod
    {
        return PaymentMethod::query()
            ->where('company_id', $company->id)
            ->where('code', $code)
            ->firstOrFail();
    }
}
