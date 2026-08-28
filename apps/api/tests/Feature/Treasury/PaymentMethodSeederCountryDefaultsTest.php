<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Contracts\Fiscal\PaymentMethodResolver;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Country payment-method defaults (register G-5).
 *
 * PaymentMethodSeeder runs on the LIVE registration path
 * (TenantInitializationService::seedPaymentMethods), so a missing instrument is
 * a manual setup step for every tenant of that country.
 */
final class PaymentMethodSeederCountryDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Methods Tenant',
            'slug' => 'methods-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    /**
     * @return list<string>
     */
    private function seedFor(string $countryCode): array
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Methods Co '.$countryCode,
            'country_code' => $countryCode,
            'currency' => $countryCode === 'TN' ? 'TND' : 'EUR',
            'locale' => 'fr',
            'timezone' => 'UTC',
        ]);

        (new PaymentMethodSeeder)->run($company);

        /** @var list<string> */
        return PaymentMethod::query()
            ->where('company_id', $company->id)
            ->orderBy('position')
            ->pluck('code')
            ->all();
    }

    public function test_every_country_default_set_includes_a_bank_transfer(): void
    {
        foreach (['TN', 'FR', 'GB'] as $countryCode) {
            $codes = $this->seedFor($countryCode);

            $this->assertContains(
                'TRANSFER',
                $codes,
                sprintf('%s tenants must get a bank transfer (virement) method out of the box.', $countryCode),
            );
        }
    }

    public function test_tunisian_defaults_keep_their_local_instruments(): void
    {
        $codes = $this->seedFor('TN');

        foreach (['CASH', 'CHECK', 'TRAITE', 'CARD', 'WALLET', 'LOYALTY', 'TRANSFER'] as $expected) {
            $this->assertContains($expected, $codes);
        }
    }

    public function test_seeder_is_idempotent_for_a_country_set(): void
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Methods Co idem',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        (new PaymentMethodSeeder)->run($company);
        $first = PaymentMethod::query()->where('company_id', $company->id)->count();

        (new PaymentMethodSeeder)->run($company);

        $this->assertSame($first, PaymentMethod::query()->where('company_id', $company->id)->count());
    }

    public function test_seeder_leaves_an_existing_company_payment_method_set_untouched(): void
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Configured Methods Co',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ]);
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => 'CUSTOM',
            'name' => 'Configured Method',
        ]);

        (new PaymentMethodSeeder)->run($company);

        $this->assertSame(
            ['CUSTOM'],
            PaymentMethod::query()->where('company_id', $company->id)->pluck('code')->all(),
        );
    }

    public function test_pos_resolver_selects_each_companys_seeded_cash_when_default_codes_repeat(): void
    {
        $companyA = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
        ]);
        $companyB = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'FR',
        ]);

        $seeder = new PaymentMethodSeeder;
        $seeder->run($companyA);
        $seeder->run($companyB);

        $cashA = PaymentMethod::query()
            ->where('company_id', $companyA->id)
            ->where('code', 'CASH')
            ->firstOrFail();
        $cashB = PaymentMethod::query()
            ->where('company_id', $companyB->id)
            ->where('code', 'CASH')
            ->firstOrFail();

        $resolver = $this->app->make(PaymentMethodResolver::class);

        $this->assertNotSame($cashA->id, $cashB->id);
        $this->assertSame(
            $cashA->id,
            $resolver->resolveByCode($this->tenant->id, $companyA->id, 'CASH'),
        );
        $this->assertSame(
            $cashB->id,
            $resolver->resolveByCode($this->tenant->id, $companyB->id, 'CASH'),
        );
    }
}
