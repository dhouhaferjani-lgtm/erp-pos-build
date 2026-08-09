<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\OnboardingChecklistService;
use App\Modules\Tenant\Application\Services\TenantInitializationService;
use App\Modules\Tenant\Domain\Enums\OnboardingStep;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\InstrumentRemittanceService;
use App\Modules\Treasury\Application\Services\OutboundRepositoryValidator;
use App\Modules\Treasury\Application\Services\TenderRepositoryResolver;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\RemittanceType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DPA lane H-3 requirement 1 — the two zero-balance repositories a fresh tenant
 * is born with must satisfy every downstream default-resolution assumption, and
 * the surfaces that genuinely need a BANK repository must degrade cleanly rather
 * than crash.
 *
 * Each test below pins one downstream consumer identified by the H-3 sweep.
 */
final class CleanRegistrationDownstreamAssumptionsTest extends TestCase
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
     * `TenderRepositoryResolver` is the ONE fallback shared by the fiscal
     * projection bridge (`TreasuryReceiptBridge`) and the G3 shift-close
     * variance listener. On a fresh tenant no payment method carries a
     * `default_repository_id`, so every tender lands on the fallback branch: it
     * must return a GL-linked repository and never null (the bridge throws a
     * RuntimeException on null).
     *
     * Gate ruling C-1 / finding I-1 — this asserts the CASH REGISTER by code,
     * not merely "one of the two cash types". The weaker form passed whichever
     * repository won and so could not fail on the regression it exists to
     * prevent: before the resolver gained its type preference, landing on
     * CASH-01 depended on `HasUuids` minting time-ordered uuid7 and the seeder
     * inserting the till first. A framework bump to uuid4, or someone listing
     * the safe first, would have silently rerouted every new tenant's POS cash
     * into the safe with every test still green.
     */
    public function test_tender_repository_resolver_resolves_the_cash_register_specifically(): void
    {
        [$tenant, $company] = $this->registerTenant('TN', 'TND');

        /** @var TenderRepositoryResolver $resolver */
        $resolver = app(TenderRepositoryResolver::class);
        $resolved = $resolver->resolve($tenant->id, $company->id, null);

        $this->assertInstanceOf(PaymentRepository::class, $resolved);
        $this->assertNotNull($resolved->gl_account_id);
        $this->assertSame(
            'CASH-01',
            $resolved->code,
            'An unmapped cash tender belongs in the till, not the safe.',
        );
        $this->assertSame(RepositoryType::CashRegister, $resolved->type);
    }

    /**
     * The preference must hold on the ORDERING, not on insertion luck: with the
     * safe minted first (the id order the seeder never produces today), the
     * resolver must still return the cash register.
     */
    public function test_the_cash_register_wins_even_when_the_safe_sorts_first(): void
    {
        [$tenant, $company] = $this->registerTenant('TN', 'TND');

        $cashRegister = PaymentRepository::query()
            ->where('company_id', $company->id)
            ->where('type', RepositoryType::CashRegister)
            ->firstOrFail();
        $safe = PaymentRepository::query()
            ->where('company_id', $company->id)
            ->where('type', RepositoryType::Safe)
            ->firstOrFail();

        $this->assertLessThan(
            0,
            strcmp($cashRegister->id, $safe->id),
            'Guard: today the till already sorts first, so invert the ids to make this test meaningful.',
        );

        // Swap the two ids so the SAFE now sorts first — the exact state a
        // uuid4 regression or a reordered seeder array would produce.
        $parked = (string) Str::uuid7();
        DB::table('payment_repositories')->where('id', $cashRegister->id)->update(['id' => $parked]);
        DB::table('payment_repositories')->where('id', $safe->id)->update(['id' => $cashRegister->id]);
        DB::table('payment_repositories')->where('id', $parked)->update(['id' => $safe->id]);

        /** @var TenderRepositoryResolver $resolver */
        $resolver = app(TenderRepositoryResolver::class);
        $resolved = $resolver->resolve($tenant->id, $company->id, null);

        $this->assertInstanceOf(PaymentRepository::class, $resolved);
        $this->assertSame('CASH-01', $resolved->code, 'Type preference must beat UUID ordering.');
    }

    /**
     * Same rule entered from a payment-method id (the event-driven caller).
     */
    public function test_tender_repository_resolver_resolves_from_a_seeded_payment_method(): void
    {
        [$tenant, $company] = $this->registerTenant('TN', 'TND');

        $method = PaymentMethod::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->first();

        $this->assertNotNull($method, 'Registration seeds payment methods.');
        $this->assertNull($method->default_repository_id, 'Registration maps no method to a repository.');

        /** @var TenderRepositoryResolver $resolver */
        $resolver = app(TenderRepositoryResolver::class);
        $resolved = $resolver->resolveByMethodId($tenant->id, $company->id, $method->id);

        $this->assertInstanceOf(PaymentRepository::class, $resolved);
        $this->assertSame('CASH-01', $resolved->code);
    }

    /**
     * `RefundCompensationService` refuses with `missing_cash_repository` unless
     * an active, GL-linked CashRegister exists. The single seeded cash register
     * has to satisfy it.
     */
    public function test_a_gl_linked_active_cash_register_exists_for_refund_compensation(): void
    {
        [, $company] = $this->registerTenant('TN', 'TND');

        $this->assertTrue(
            PaymentRepository::query()
                ->where('company_id', $company->id)
                ->where('type', RepositoryType::CashRegister)
                ->where('is_active', true)
                ->whereNotNull('gl_account_id')
                ->exists(),
            'RefundCompensationService would refuse with missing_cash_repository.',
        );
    }

    /**
     * The onboarding checklist's "payment repositories" step must be satisfied
     * out of the box — a fresh tenant should not open on a red checklist item.
     */
    public function test_onboarding_checklist_payment_repositories_step_is_satisfied(): void
    {
        [, $company] = $this->registerTenant('TN', 'TND');

        /** @var OnboardingChecklistService $checklist */
        $checklist = app(OnboardingChecklistService::class);
        $items = $checklist->getStatus($company->id);

        $step = collect($items)->firstWhere('step', OnboardingStep::PaymentRepositories->value);

        $this->assertNotNull($step, 'The payment_repositories checklist step must exist.');
        $this->assertFalse($step['degraded'], 'The probe must run cleanly.');
        $this->assertTrue($step['completed'], 'The seeded cash register must complete the step.');
    }

    /**
     * Bank-only surfaces: a fresh tenant has NO bank repository, by design. Each
     * must refuse with a domain error naming the missing bank repository — not
     * blow up on a null, and not silently settle through the cash till.
     */
    public function test_bank_only_surfaces_refuse_cleanly_with_no_bank_repository(): void
    {
        [, $company] = $this->registerTenant('TN', 'TND');

        $cashRegister = PaymentRepository::query()
            ->where('company_id', $company->id)
            ->where('type', RepositoryType::CashRegister)
            ->firstOrFail();

        $this->assertSame(
            0,
            PaymentRepository::query()
                ->where('company_id', $company->id)
                ->whereIn('type', [RepositoryType::BankAccount, RepositoryType::Virtual])
                ->count(),
            'A fresh tenant must own no bank or wallet repository.',
        );

        // Outbound instrument clearing — refuses, naming the requirement.
        try {
            app(OutboundRepositoryValidator::class)->validate(
                repositoryId: $cashRegister->id,
                tenantId: $cashRegister->tenant_id,
                companyId: $cashRegister->company_id,
                currency: $cashRegister->currency,
                instrumentBankId: null,
            );
            $this->fail('OutboundRepositoryValidator accepted a cash repository.');
        } catch (DomainException $e) {
            $this->assertSame(
                'Outbound instruments can only clear through a bank-account repository.',
                $e->getMessage(),
            );
        }

        // Instrument remittances — refuses, naming the requirement.
        try {
            app(InstrumentRemittanceService::class)->createDraft(
                companyId: $cashRegister->company_id,
                tenantId: $cashRegister->tenant_id,
                bankRepositoryId: $cashRegister->id,
                type: RemittanceType::Collection,
                kind: InstrumentKind::Cheque,
                userId: null,
            );
            $this->fail('InstrumentRemittanceService accepted a cash repository.');
        } catch (DomainException $e) {
            $this->assertSame('Instrument remittances require a bank-account repository.', $e->getMessage());
        }
    }

    /**
     * @return array{0: Tenant, 1: Company, 2: User}
     */
    private function registerTenant(string $countryCode, string $currency): array
    {
        $tenant = Tenant::create([
            'name' => "H3 Downstream {$countryCode}",
            'slug' => 'h3-downstream-'.strtolower($countryCode),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
            'country_code' => strtoupper($countryCode),
            'currency_code' => $currency,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => "H3 Downstream Company {$countryCode}",
            'country_code' => strtoupper($countryCode),
            'currency' => $currency,
            'locale' => 'fr',
            'timezone' => 'UTC',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => "H3 Downstream User {$countryCode}",
            'email' => strtolower($countryCode).'-downstream@h3.test',
            'password' => 'password',
            'status' => 'active',
        ]);

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        return [$tenant, $company, $user];
    }
}
